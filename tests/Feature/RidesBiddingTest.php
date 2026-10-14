<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Discover\Models\DiscoverCity;
use Modules\Driver\Enums\DriverStatus;
use Modules\Driver\Enums\PresenceStatus;
use Modules\Driver\Enums\VehicleStatus;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverPresence;
use Modules\Driver\Models\Vehicle;
use Modules\Driver\Models\VehicleColor;
use Modules\Driver\Models\VehicleMake;
use Modules\Driver\Models\VehicleModel;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideActiveLock;
use Modules\Rides\Models\RideBid;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideFeatureRule;
use Modules\Rides\Models\RideOffer;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\CityResolver;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Services\WalletService;
use Tests\TestCase;

/**
 * Bidding, inDrive-style (docs/rides-plan.md §9): the rider's price above the floor, a batch of
 * drivers, "accept your price" or a higher offer with a timer, declines and re-offers, the rider's
 * raise, the first agreement locking the ride, and the wallet holding the difference first.
 */
class RidesBiddingTest extends TestCase
{
    private const PICKUP = ['lat' => 24.7136, 'lng' => 46.6753, 'address' => 'Olaya'];

    private const DROPOFF = ['lat' => 24.7500, 'lng' => 46.7000, 'address' => 'King Fahd Rd'];

    private Country $saudi;

    private RideCategory $offers;

    private User $rider;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        config(['rides.regulator_enforced' => false, 'broadcasting.default' => 'null']);
        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id,
            'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'ر.س', 'decimal_places' => 2])->id,
        ]);
        DiscoverCity::query()->create(['country_id' => $this->saudi->id, 'timezone' => 'Asia/Riyadh', 'lat' => 24.7136, 'lng' => 46.6753, 'status' => true, 'sort_order' => 1]);
        $this->offers = RideCategory::query()->create(['slug' => 'offers', 'pricing_strategy' => 'bid', 'status' => true, 'sort_order' => 1, 'flags' => ['cash_allowed' => true]]);
        foreach (['rides', 'category.offers', 'bidding', 'wallet', 'cash'] as $feature) {
            RideFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        RideFeatures::forget();
        RidePolicies::forget();
        CityResolver::forget();
        app(RidePolicies::class)->save($this->saudi->id, null, null, $this->offers->id, [
            'price.base_minor' => 600, 'price.per_km_minor' => 180, 'price.per_min_minor' => 30, 'price.min_fare_minor' => 1500,
        ]);
        $this->rider = User::query()->create(['name' => 'Sara Ahmed', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    public function test_the_rider_offers_a_price_above_the_floor_and_a_batch_of_drivers_gets_it(): void
    {
        $drivers = [$this->driver(), $this->driver(24.7140, 46.6760), $this->driver(24.7145, 46.6765), $this->driver(24.7150, 46.6770)];
        $quote = $this->quote()->assertOk()->json('data.0');
        $suggested = $quote['bid']['suggested_minor'];
        $floor = $quote['bid']['floor_minor'];
        $this->assertSame($suggested, $quote['total_minor']);
        $this->assertGreaterThanOrEqual((int) ceil($suggested * 0.8 / 100) * 100, $floor, 'at most 20% under, in whole riyals');
        $this->assertLessThan($suggested, $floor);

        $this->book($quote, 'cash', $floor - 100)->assertUnprocessable()->assertJsonPath('error_code', 'rides_bid_below_floor');
        $this->book($quote, 'cash', null)->assertUnprocessable()->assertJsonPath('error_code', 'rides_bid_price_required');
        $ride = $this->book($quote, 'cash', $floor)->assertCreated()
            ->assertJsonPath('data.status', 'bidding')
            ->assertJsonPath('data.bidding.rider_price_minor', $floor)
            ->json('data');

        $this->assertSame(3, RideOffer::query()->where('ride_id', Ride::query()->where('uuid', $ride['uuid'])->value('id'))->count(), 'a batch of three (D-86)');
        $card = $this->asDriver($drivers[0])->getJson('/api/driver/v1/offers')->assertOk()->json('data.0');
        $this->assertSame($floor, $card['bid']['rider_price_minor']);
        $this->assertSame($floor + intdiv($floor * 5000, 10000), $card['bid']['cap_minor']);
        $this->assertSame([500, 1000, 1500], $card['bid']['steps_minor']);
    }

    public function test_a_driver_who_accepts_the_riders_price_gets_the_ride_at_once(): void
    {
        $first = $this->driver();
        $second = $this->driver(24.7140, 46.6760);
        $quote = $this->quote()->json('data.0');
        $price = $quote['bid']['floor_minor'];
        $uuid = $this->book($quote, 'cash', $price)->json('data.uuid');
        $offer = RideOffer::query()->where('driver_id', $first->id)->sole();

        $this->asDriver($first)->postJson("/api/driver/v1/offers/{$offer->uuid}/bid", ['price_minor' => $price])->assertOk()
            ->assertJsonPath('data.ride.status', 'accepted');

        $ride = Ride::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame($price, $ride->agreed_price_minor);
        $this->assertSame('withdrawn', RideOffer::query()->where('driver_id', $second->id)->value('status')->value);
        $this->driveToTheEnd($first, $uuid);
        $this->assertSame($price, $ride->fresh()->final_total_minor, 'the agreed price is a fixed fare');
    }

    public function test_offers_decline_reoffer_lower_and_the_rider_takes_one_with_the_wallet_holding_the_difference(): void
    {
        $this->fund(100000);
        $a = $this->driver();
        $b = $this->driver(24.7140, 46.6760);
        $quote = $this->quote()->json('data.0');
        $price = $quote['bid']['floor_minor'];
        $uuid = $this->book($quote, 'wallet', $price)->json('data.uuid');
        $offerA = RideOffer::query()->where('driver_id', $a->id)->sole();
        $offerB = RideOffer::query()->where('driver_id', $b->id)->sole();
        $cap = $price + intdiv($price * 5000, 10000);

        $this->asDriver($a)->postJson("/api/driver/v1/offers/{$offerA->uuid}/bid", ['price_minor' => $price - 100])->assertUnprocessable()->assertJsonPath('error_code', 'rides_bid_below_rider_price');
        $this->asDriver($a)->postJson("/api/driver/v1/offers/{$offerA->uuid}/bid", ['price_minor' => $cap + 100])->assertUnprocessable()->assertJsonPath('error_code', 'rides_bid_above_cap');
        $bidA = $this->asDriver($a)->postJson("/api/driver/v1/offers/{$offerA->uuid}/bid", ['price_minor' => $cap])->assertCreated()->json('data.bid.uuid');
        $this->asDriver($a)->postJson("/api/driver/v1/offers/{$offerA->uuid}/bid", ['price_minor' => $cap - 100])->assertStatus(409)->assertJsonPath('error_code', 'rides_bid_already_open');
        $this->asDriver($b)->postJson("/api/driver/v1/offers/{$offerB->uuid}/bid", ['price_minor' => $cap - 200])->assertCreated();

        $board = $this->actingAs($this->rider, 'user_api')->getJson("/api/mobile/v1/rides/{$uuid}/bids")->assertOk()->json('data');
        $this->assertSame([$cap - 200, $cap], array_column($board['bids'], 'price_minor'), 'cheapest first');
        $this->assertTrue($board['bids'][0]['cheapest']);
        $this->assertSame(20, $board['bids'][0]['ttl_s']);

        // Declined — the driver offers again, lower than before but still above the rider.
        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/{$uuid}/bids/{$bidA}/decline")->assertOk();
        $again = $this->asDriver($a)->postJson("/api/driver/v1/offers/{$offerA->uuid}/bid", ['price_minor' => $price + 100])->assertCreated()->json('data.bid.uuid');

        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/{$uuid}/bids/{$again}/accept")->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.driver.name', $a->name);
        $ride = Ride::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame($price + 100, $ride->agreed_price_minor);
        $this->assertSame($price + 100, (int) $ride->holds()->sum('amount_minor'), 'the difference was held before the ride was given');
        $this->assertSame('withdrawn', RideBid::query()->where('driver_id', $b->id)->value('status')->value);
        $this->assertFalse(RideActiveLock::query()->where('lock_key', 'like', 'driver_bid:%')->exists(), 'every bidder is free again');
    }

    public function test_an_offer_declines_itself_when_its_timer_runs_out(): void
    {
        $a = $this->driver();
        $quote = $this->quote()->json('data.0');
        $price = $quote['bid']['floor_minor'];
        $this->book($quote, 'cash', $price);
        $offer = RideOffer::query()->sole();
        $bid = $this->asDriver($a)->postJson("/api/driver/v1/offers/{$offer->uuid}/bid", ['price_minor' => $price + 500])->json('data.bid.uuid');

        $this->travel(21)->seconds();
        $this->artisan('rides:dispatch-tick')->assertSuccessful();

        $this->assertSame('expired', RideBid::query()->where('uuid', $bid)->value('status')->value);
        $this->assertFalse(RideActiveLock::query()->whereKey(RideActiveLock::forDriverBid($a->id))->exists());
        $this->asDriver($a)->postJson("/api/driver/v1/offers/{$offer->uuid}/bid", ['price_minor' => $price + 300])->assertCreated();
    }

    public function test_the_rider_raises_the_price_and_without_money_a_higher_offer_cannot_be_taken(): void
    {
        $this->fund(0);
        $a = $this->driver();
        $quote = $this->quote()->json('data.0');
        $price = $quote['bid']['floor_minor'];
        $this->fund($price);
        $uuid = $this->book($quote, 'wallet', $price)->json('data.uuid');
        $offer = RideOffer::query()->sole();

        $bid = $this->asDriver($a)->postJson("/api/driver/v1/offers/{$offer->uuid}/bid", ['price_minor' => $price + 500])->assertCreated()->json('data.bid.uuid');
        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/{$uuid}/bids/{$bid}/accept")
            ->assertUnprocessable()->assertJsonPath('error_code', 'rides_insufficient_balance');
        $this->assertSame('pending', RideBid::query()->where('uuid', $bid)->value('status')->value, 'the offer stays until its timer ends');
        $this->assertSame('bidding', Ride::query()->where('uuid', $uuid)->value('status')->value);

        $this->fund(5000);
        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/{$uuid}/raise", ['price_minor' => $price])->assertUnprocessable()->assertJsonPath('error_code', 'rides_raise_not_higher');
        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/{$uuid}/raise", ['price_minor' => $price + 500])->assertOk()
            ->assertJsonPath('data.bidding.rider_price_minor', $price + 500);
        $this->assertSame($price + 500, (int) Ride::query()->where('uuid', $uuid)->firstOrFail()->holds()->sum('amount_minor'));
        $this->assertSame($price + 500, $this->asDriver($a)->getJson('/api/driver/v1/offers')->json('data.0.bid.rider_price_minor'), 'drivers see the new price');
    }

    public function test_past_the_visible_limit_offers_wait_in_line_and_show_when_room_frees(): void
    {
        app(RidePolicies::class)->save($this->saudi->id, null, null, $this->offers->id, [
            'price.base_minor' => 600, 'price.per_km_minor' => 180, 'price.per_min_minor' => 30, 'price.min_fare_minor' => 1500, 'bids.max_visible' => 1,
        ]);
        $a = $this->driver();
        $b = $this->driver(24.7140, 46.6760);
        $quote = $this->quote()->json('data.0');
        $price = $quote['bid']['floor_minor'];
        $uuid = $this->book($quote, 'cash', $price)->json('data.uuid');

        $first = $this->asDriver($a)->postJson('/api/driver/v1/offers/'.RideOffer::query()->where('driver_id', $a->id)->value('uuid').'/bid', ['price_minor' => $price + 500])->json('data.bid');
        $second = $this->asDriver($b)->postJson('/api/driver/v1/offers/'.RideOffer::query()->where('driver_id', $b->id)->value('uuid').'/bid', ['price_minor' => $price + 700])->json('data.bid');
        $this->assertSame(['pending', 'queued'], [$first['status'], $second['status']]);
        $this->actingAs($this->rider, 'user_api')->getJson("/api/mobile/v1/rides/{$uuid}/bids")->assertJsonCount(1, 'data.bids')->assertJsonPath('data.queued', 1);

        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/{$uuid}/bids/{$first['uuid']}/decline")->assertOk();
        $this->actingAs($this->rider, 'user_api')->getJson("/api/mobile/v1/rides/{$uuid}/bids")->assertJsonPath('data.bids.0.uuid', $second['uuid'])->assertJsonPath('data.queued', 0);
    }

    // ---------------------------------------------------------------- helpers

    private function quote(): TestResponse
    {
        return $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/quotes', ['origin' => self::PICKUP, 'destination' => self::DROPOFF]);
    }

    /** @param array<string, mixed> $quote */
    private function book(array $quote, string $method, ?int $price): TestResponse
    {
        return $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides', array_filter([
            'quote_uuid' => $quote['uuid'], 'fingerprint' => $quote['fingerprint'], 'payment_method' => $method, 'rider_price_minor' => $price,
        ], fn ($v) => $v !== null), ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function driveToTheEnd(Driver $driver, string $uuid): void
    {
        $at = ['lat' => self::PICKUP['lat'], 'lng' => self::PICKUP['lng']];
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/arrive", $at)->assertOk();
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/start", $at)->assertOk();
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/end", ['lat' => self::DROPOFF['lat'], 'lng' => self::DROPOFF['lng']])->assertOk()->assertJsonPath('data.status', 'completed');
    }

    private function driver(float $lat = 24.7150, float $lng = 46.6760): Driver
    {
        $n = ++$this->n;
        $driver = Driver::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => "Captain $n", 'phone' => '+96655000000'.$n, 'phone_verified_at' => now(),
            'country_id' => $this->saudi->id, 'status' => DriverStatus::Active,
        ]);
        $make = VehicleMake::query()->create(['status' => true]);
        Vehicle::query()->create([
            'uuid' => (string) Str::uuid(), 'driver_id' => $driver->id, 'country_id' => $this->saudi->id, 'make_id' => $make->id,
            'model_id' => VehicleModel::query()->create(['make_id' => $make->id, 'body_type' => 'sedan', 'seats' => 4, 'status' => true])->id,
            'color_id' => VehicleColor::query()->create(['hex' => '#FFFFFF', 'status' => true])->id,
            'year' => 2022, 'body_type' => 'sedan', 'seats' => 4, 'plate_letters' => 'ا ب ج', 'plate_number' => (string) (2000 + $n), 'plate_type' => 1,
            'status' => VehicleStatus::Approved, 'is_active' => true,
        ]);
        DriverPresence::query()->create(['driver_id' => $driver->id, 'status' => PresenceStatus::Online, 'lat' => $lat, 'lng' => $lng, 'gps_enabled' => true, 'online_since' => now(), 'last_seen_at' => now()]);

        return $driver;
    }

    private function asDriver(Driver $driver): static
    {
        return $this->actingAs($driver, 'driver_api');
    }

    private function fund(int $minor): void
    {
        if ($minor <= 0) {
            return;
        }
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->firstOrCreateWallet($this->rider, $this->saudi), $minor, WalletBucket::Withdrawable, WalletTransactionType::Topup, ['idempotency_key' => 'fund:'.Str::uuid()]);
    }
}
