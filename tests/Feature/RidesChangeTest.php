<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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
use Modules\Rides\Events\RideChanged;
use Modules\Rides\Events\RideDriverMoved;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideAdjustment;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideFeatureRule;
use Modules\Rides\Models\RideOffer;
use Modules\Rides\Models\RideStop;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\CityResolver;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Services\WalletService;
use Tests\TestCase;

/**
 * Changing the trip during the ride (R-91..95, D-131..137, wireflow 6أ/6ب/6ج): the rider sees what
 * a new destination does to the distance, the time and the price before sending; the captain has 30
 * seconds to accept; accepted, the route and the fixed fare change (and the end charges the new
 * total); declined or unanswered, nothing changes. One more stop works the same way.
 */
class RidesChangeTest extends TestCase
{
    private const PICKUP = ['lat' => 24.7136, 'lng' => 46.6753, 'address' => 'Olaya'];

    private const DROPOFF = ['lat' => 24.7500, 'lng' => 46.7000, 'address' => 'King Fahd Rd'];

    private const FARTHER = ['lat' => 24.8000, 'lng' => 46.7300, 'address' => 'Arab Mall'];

    private Country $saudi;

    private User $rider;

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Event::fake([RideChanged::class, RideDriverMoved::class]);
        config(['rides.regulator_enforced' => false, 'broadcasting.default' => 'null']);
        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id,
            'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'ر.س', 'decimal_places' => 2])->id,
        ]);
        DiscoverCity::query()->create(['country_id' => $this->saudi->id, 'timezone' => 'Asia/Riyadh', 'lat' => 24.7136, 'lng' => 46.6753, 'status' => true, 'sort_order' => 1]);
        $economy = RideCategory::query()->create(['slug' => 'economy', 'pricing_strategy' => 'distance', 'status' => true, 'sort_order' => 1, 'flags' => ['cash_allowed' => true, 'multi_stop_allowed' => true]]);
        foreach (['rides', 'category.economy', 'cash', 'wallet', 'change_destination', 'multi_stop'] as $feature) {
            RideFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        RideFeatures::forget();
        RidePolicies::forget();
        CityResolver::forget();
        app(RidePolicies::class)->save($this->saudi->id, null, null, $economy->id, [
            'price.base_minor' => 600, 'price.per_km_minor' => 180, 'price.per_min_minor' => 30, 'price.min_fare_minor' => 1500,
        ]);
        $this->rider = User::query()->create(['name' => 'Sara Ahmed', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->driver = $this->makeDriver();
    }

    public function test_a_farther_destination_shows_its_cost_first_then_the_captain_accepts_and_the_end_charges_it(): void
    {
        $this->fund(50000);
        $uuid = $this->onTheRide('wallet');
        $ride = Ride::query()->where('uuid', $uuid)->firstOrFail();
        $before = (int) $ride->agreed_price_minor;

        $preview = $this->asRider()->postJson("/api/mobile/v1/rides/$uuid/changes", self::FARTHER)->assertCreated()->json('data');
        $this->assertSame('preview', $preview['status']);
        $this->assertGreaterThan(0, $preview['distance_delta_m']);
        $this->assertGreaterThan(0, $preview['price_delta_minor']);
        $this->assertSame($before + $preview['price_delta_minor'], $preview['new_total_minor']);
        $this->assertSame(self::DROPOFF['address'], $ride->fresh()->destination_address, 'a preview changes nothing');

        $this->asRider()->postJson("/api/mobile/v1/rides/$uuid/changes/{$preview['uuid']}/send")->assertOk()->assertJsonPath('data.status', 'pending');
        $card = $this->asDriver()->getJson('/api/driver/v1/rides/current')->assertOk()->json('data.change_request');
        $this->assertSame($preview['uuid'], $card['uuid']);
        $this->assertGreaterThan(0, $card['earning_delta_minor']);
        $this->assertLessThanOrEqual(30, $card['seconds_left']);

        $this->asDriver()->postJson("/api/driver/v1/rides/$uuid/changes/{$preview['uuid']}/accept")->assertOk()->assertJsonPath('data.status', 'applied');
        $ride->refresh();
        $this->assertSame(self::FARTHER['address'], $ride->destination_address);
        $this->assertSame($preview['new_total_minor'], (int) $ride->agreed_price_minor);
        $this->assertSame($preview['price_delta_minor'], (int) RideAdjustment::query()->where('ride_id', $ride->id)->where('type', 'destination_change')->value('amount_minor'));

        $rider = $this->asRider()->getJson("/api/mobile/v1/rides/$uuid")->assertOk()->assertJsonPath('data.change_request.status', 'applied');
        $this->assertSame($preview['new_total_minor'], collect($rider->json('data.lines'))->firstWhere('code', 'total')['amount_minor']);

        $this->asDriver()->postJson("/api/driver/v1/rides/$uuid/end", ['lat' => self::FARTHER['lat'], 'lng' => self::FARTHER['lng']])->assertOk()
            ->assertJsonPath('data.final_total_minor', $preview['new_total_minor']);
    }

    public function test_declined_or_unanswered_changes_leave_the_trip_as_it_was(): void
    {
        $uuid = $this->onTheRide('cash');
        $before = (int) Ride::query()->where('uuid', $uuid)->value('agreed_price_minor');

        $first = $this->asRider()->postJson("/api/mobile/v1/rides/$uuid/changes", self::FARTHER)->json('data.uuid');
        $this->asRider()->postJson("/api/mobile/v1/rides/$uuid/changes/$first/send")->assertOk();
        $second = $this->asRider()->postJson("/api/mobile/v1/rides/$uuid/changes", self::FARTHER)->json('data.uuid');
        $this->asRider()->postJson("/api/mobile/v1/rides/$uuid/changes/$second/send")->assertStatus(409)->assertJsonPath('error_code', 'rides_change_pending');

        $this->asDriver()->postJson("/api/driver/v1/rides/$uuid/changes/$first/reject")->assertOk()->assertJsonPath('data.status', 'rejected');
        $ride = Ride::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(self::DROPOFF['address'], $ride->destination_address);
        $this->assertSame($before, (int) $ride->agreed_price_minor);

        $third = $this->asRider()->postJson("/api/mobile/v1/rides/$uuid/changes", self::FARTHER)->json('data.uuid');
        $this->asRider()->postJson("/api/mobile/v1/rides/$uuid/changes/$third/send")->assertOk();
        $this->travel(31)->seconds();
        $this->artisan('rides:dispatch-tick')->assertSuccessful();
        $this->asRider()->getJson("/api/mobile/v1/rides/$uuid")->assertOk()->assertJsonPath('data.change_request.status', 'expired');
        $this->asDriver()->postJson("/api/driver/v1/rides/$uuid/changes/$third/accept")->assertStatus(409)->assertJsonPath('error_code', 'rides_change_expired');
        $this->assertSame(self::DROPOFF['address'], Ride::query()->where('uuid', $uuid)->value('destination_address'));
    }

    public function test_one_more_stop_during_the_ride_once_the_captain_agrees(): void
    {
        $uuid = $this->onTheRide('cash');
        $stop = ['lat' => 24.7300, 'lng' => 46.6900, 'address' => 'Pharmacy', 'type' => 'add_stop'];
        $change = $this->asRider()->postJson("/api/mobile/v1/rides/$uuid/changes", $stop)->assertCreated()->json('data.uuid');
        $this->asRider()->postJson("/api/mobile/v1/rides/$uuid/changes/$change/send")->assertOk();
        $this->asDriver()->postJson("/api/driver/v1/rides/$uuid/changes/$change/accept")->assertOk();

        $ride = Ride::query()->where('uuid', $uuid)->firstOrFail();
        $added = RideStop::query()->where('ride_id', $ride->id)->sole();
        $this->assertSame('Pharmacy', $added->address);
        $this->assertSame(self::DROPOFF['address'], $ride->destination_address);
        $this->asDriver()->getJson('/api/driver/v1/rides/current')->assertOk()->assertJsonPath('data.navigation.lat', 24.73);
    }

    public function test_the_switch_and_the_kind_of_ride_are_respected(): void
    {
        $uuid = $this->onTheRide('cash');
        RideFeatureRule::query()->where('feature', 'change_destination')->update(['enabled' => false]);
        RideFeatures::forget();
        $this->asRider()->postJson("/api/mobile/v1/rides/$uuid/changes", self::FARTHER)->assertForbidden()->assertJsonPath('error_code', 'rides_feature_disabled');

        // Someone else's ride.
        $other = User::query()->create(['name' => 'Omar', 'phone' => '+966500000009', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->actingAs($other, 'user_api')->postJson("/api/mobile/v1/rides/$uuid/changes", self::FARTHER)->assertNotFound();
    }

    // ---------------------------------------------------------------- helpers

    private function onTheRide(string $method): string
    {
        $quote = $this->asRider()->postJson('/api/mobile/v1/rides/quotes', ['origin' => self::PICKUP, 'destination' => self::DROPOFF])->json('data.0');
        $uuid = $this->postJson('/api/mobile/v1/rides', ['quote_uuid' => $quote['uuid'], 'fingerprint' => $quote['fingerprint'], 'payment_method' => $method], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->json('data.uuid');
        $offer = RideOffer::query()->where('driver_id', $this->driver->id)->where('status', 'sent')->sole();
        $this->asDriver()->postJson("/api/driver/v1/offers/{$offer->uuid}/accept")->assertOk();
        $this->asDriver()->postJson("/api/driver/v1/rides/$uuid/arrive", ['lat' => self::PICKUP['lat'], 'lng' => self::PICKUP['lng']])->assertOk();
        $this->asDriver()->postJson("/api/driver/v1/rides/$uuid/start", ['lat' => self::PICKUP['lat'], 'lng' => self::PICKUP['lng']])->assertOk()->assertJsonPath('data.status', 'in_progress');

        return $uuid;
    }

    private function makeDriver(): Driver
    {
        $driver = Driver::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'Captain One', 'phone' => '+966550000001', 'phone_verified_at' => now(),
            'country_id' => $this->saudi->id, 'status' => DriverStatus::Active,
        ]);
        $make = VehicleMake::query()->create(['status' => true]);
        $model = VehicleModel::query()->create(['make_id' => $make->id, 'body_type' => 'sedan', 'seats' => 4, 'status' => true]);
        Vehicle::query()->create([
            'uuid' => (string) Str::uuid(), 'driver_id' => $driver->id, 'country_id' => $this->saudi->id, 'make_id' => $make->id, 'model_id' => $model->id,
            'color_id' => VehicleColor::query()->create(['hex' => '#FFFFFF', 'status' => true])->id,
            'year' => 2022, 'body_type' => 'sedan', 'seats' => 4, 'plate_letters' => 'ا ب ج', 'plate_number' => '1234', 'plate_type' => 1,
            'status' => VehicleStatus::Approved, 'is_active' => true,
        ]);
        DriverPresence::query()->create([
            'driver_id' => $driver->id, 'status' => PresenceStatus::Online, 'lat' => 24.7150, 'lng' => 46.6760, 'gps_enabled' => true,
            'online_since' => now(), 'last_seen_at' => now(),
        ]);

        return $driver;
    }

    private function asRider(): static
    {
        return $this->actingAs($this->rider, 'user_api');
    }

    private function asDriver(): static
    {
        return $this->actingAs($this->driver, 'driver_api');
    }

    private function fund(int $minor): void
    {
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->firstOrCreateWallet($this->rider, $this->saudi), $minor, WalletBucket::Withdrawable, WalletTransactionType::Topup, ['idempotency_key' => 'fund:'.$this->rider->id]);
    }
}
