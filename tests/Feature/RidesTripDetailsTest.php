<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Support\Facades\Event;
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
use Modules\Rides\Events\RideChanged;
use Modules\Rides\Events\RideDriverMoved;
use Modules\Rides\Models\DriverRideProfile;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideFeatureRule;
use Modules\Rides\Models\RideOffer;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\CityResolver;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Services\WalletService;
use Tests\TestCase;

/**
 * Batch 3 of docs/rides_tasks.md: how many ride and the pickup note (D-60, D-61), changing the way
 * of paying before the trip (R-61), the captain's tier badge (R-81), "free waiting ends soon" (D-122),
 * and the time / distance left (D-129).
 */
class RidesTripDetailsTest extends TestCase
{
    private const PICKUP = ['lat' => 24.7136, 'lng' => 46.6753, 'address' => 'Olaya'];

    private const DROPOFF = ['lat' => 24.7500, 'lng' => 46.7000, 'address' => 'King Fahd Rd'];

    private Country $saudi;

    private User $rider;

    private int $economyId;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(fn () => Http::response([], 200));
        Event::fake([RideChanged::class, RideDriverMoved::class]);
        config(['rides.regulator_enforced' => false, 'broadcasting.default' => 'null']);

        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id,
            'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2])->id,
        ]);
        DiscoverCity::query()->create(['country_id' => $this->saudi->id, 'timezone' => 'Asia/Riyadh', 'lat' => 24.7136, 'lng' => 46.6753, 'status' => true, 'sort_order' => 1]);
        $this->economyId = RideCategory::query()->create([
            'slug' => 'economy', 'pricing_strategy' => 'distance', 'status' => true, 'sort_order' => 1, 'capacity_max' => 4,
            'flags' => ['cash_allowed' => true],
        ])->id;
        foreach (['rides', 'category.economy', 'wallet', 'card', 'cash'] as $feature) {
            RideFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        RideFeatures::forget();
        RidePolicies::forget();
        CityResolver::forget();
        $this->price([]);

        $this->rider = User::query()->create(['name' => 'Sara Ahmed', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    public function test_the_rider_says_how_many_ride_and_leaves_a_short_note_for_the_captain(): void
    {
        $driver = $this->onlineDriver();
        $quote = $this->quote();

        $this->book($quote, 'cash', ['passengers' => 5])->assertStatus(422)->assertJsonPath('error_code', 'rides_too_many_passengers')->assertJsonPath('data.max', 4);
        $this->book($quote, 'cash', ['pickup_note' => 'Call me on 050 123 4567'])->assertStatus(422)->assertJsonPath('error_code', 'rides_pickup_note_contact');
        $this->book($quote, 'cash', ['pickup_note' => 'اتصل ٠٥٠١٢٣٤٥٦٧'])->assertStatus(422)->assertJsonPath('error_code', 'rides_pickup_note_contact');
        $this->book($quote, 'cash', ['pickup_note' => str_repeat('a', 121)])->assertStatus(422)->assertJsonPath('error_code', 'rides_pickup_note_too_long');

        $uuid = $this->book($quote, 'cash', ['passengers' => 3, 'pickup_note' => "  East gate,\n  by the pharmacy  "])->assertCreated()
            ->assertJsonPath('data.passengers', 3)->assertJsonPath('data.pickup_note', 'East gate, by the pharmacy')->json('data.uuid');

        // The captain reads both on the request card, then on the ride.
        $offer = $this->asDriver($driver)->getJson('/api/driver/v1/offers')->assertOk()->json('data.0');
        $this->assertSame(3, $offer['passengers']);
        $this->assertSame('East gate, by the pharmacy', $offer['pickup_note']);
        $this->asDriver($driver)->postJson("/api/driver/v1/offers/{$offer['uuid']}/accept")->assertOk()
            ->assertJsonPath('data.passengers', 3)->assertJsonPath('data.pickup_note', 'East gate, by the pharmacy');
        $this->assertSame($uuid, $offer['ride_uuid']);
    }

    public function test_the_rider_switches_between_wallet_and_cash_before_the_trip(): void
    {
        $this->price(['payment.cash_fee_minor' => 100]);
        $this->fund($this->rider, 10000);
        $driver = $this->onlineDriver();
        $quote = $this->quote();
        $total = (int) $quote['total_minor'];

        $book = $this->book($quote, 'wallet')->assertCreated()->assertJsonPath('data.payment_change', ['cash']);
        $uuid = $book->json('data.uuid');
        $this->assertSame($total, $this->held());

        // Wallet → cash: the hold goes, the cash fee joins the lines and the price.
        $key = (string) Str::uuid();
        $cash = $this->change($uuid, 'cash', $key)->assertOk()
            ->assertJsonPath('data.payment_method', 'cash')->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.agreed_price_minor', $total + 100)->assertJsonPath('data.payment_change', ['wallet']);
        $this->assertContains('payment_method_fee', array_column($cash->json('data.lines'), 'code'));
        $this->assertSame($total + 100, collect($cash->json('data.lines'))->firstWhere('code', 'total')['amount_minor']);
        $this->assertSame(0, $this->held());
        $this->change($uuid, 'cash', $key)->assertOk()->assertJsonPath('data.payment_method', 'cash');

        // Back to the wallet: no fee, the whole price held again.
        $this->change($uuid, 'wallet')->assertOk()->assertJsonPath('data.agreed_price_minor', $total)->assertJsonPath('data.payment_status', 'held');
        $this->assertNotContains('payment_method_fee', array_column($this->ride($uuid)->json('data.lines'), 'code'));
        $this->assertSame($total, $this->held());
        $this->change($uuid, 'card')->assertStatus(409)->assertJsonPath('error_code', 'rides_payment_change_unavailable');

        // Not enough in the wallet: nothing moves.
        $this->change($uuid, 'cash')->assertOk();
        app(WalletService::class)->debit($this->wallet(), 9950, WalletBucket::Withdrawable, WalletTransactionType::ManualAdjustment, ['idempotency_key' => 'drain']);
        $this->change($uuid, 'wallet')->assertStatus(422)->assertJsonPath('error_code', 'rides_insufficient_balance');
        $this->assertSame('cash', Ride::query()->where('uuid', $uuid)->value('payment_method')->value);

        // Once the trip started, the way of paying stays.
        $this->drive($driver, $uuid);
        $this->ride($uuid)->assertJsonPath('data.payment_change', []);
        $this->change($uuid, 'wallet')->assertStatus(409)->assertJsonPath('error_code', 'rides_payment_change_unavailable');
        // The cash fee reaches the end too.
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/end", ['lat' => self::DROPOFF['lat'], 'lng' => self::DROPOFF['lng']])->assertOk();
        $this->assertSame($total + 100, Ride::query()->where('uuid', $uuid)->value('final_total_minor'));
    }

    public function test_cash_is_not_offered_where_it_is_switched_off(): void
    {
        RideFeatureRule::query()->where('feature', 'cash')->update(['enabled' => false]);
        RideFeatures::forget();
        $this->fund($this->rider, 10000);
        $uuid = $this->book($this->quote(), 'wallet')->assertCreated()->assertJsonPath('data.payment_change', [])->json('data.uuid');
        $this->change($uuid, 'cash')->assertStatus(403)->assertJsonPath('error_code', 'rides_feature_disabled');
    }

    public function test_the_rider_sees_the_captains_tier_and_the_time_left(): void
    {
        $driver = $this->onlineDriver();
        DriverRideProfile::query()->updateOrCreate(['driver_id' => $driver->id], ['tier' => 'gold']);
        $uuid = $this->book($this->quote(), 'cash')->json('data.uuid');
        $this->accept($driver);

        $ride = $this->ride($uuid)->assertJsonPath('data.driver.tier', 'gold')->assertJsonPath('data.driver.tier_label', __('rides.tiers.gold'));
        $progress = $ride->json('data.progress');
        $this->assertSame('pickup', $progress['to']);
        $this->assertGreaterThan(0, $progress['distance_m']);
        $this->assertSame(0, $progress['eta_s'] % 60, 'whole minutes');
        $this->asDriver($driver)->getJson("/api/driver/v1/rides/$uuid")->assertOk()->assertJsonPath('data.progress.to', 'pickup')
            ->assertJsonPath('data.progress.distance_m', $progress['distance_m']);

        // At the pickup the countdown takes over; on the trip it is the destination.
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/arrive", ['lat' => self::PICKUP['lat'], 'lng' => self::PICKUP['lng']])->assertOk()->assertJsonPath('data.progress', null);
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/start", ['lat' => self::PICKUP['lat'], 'lng' => self::PICKUP['lng']])->assertOk()->assertJsonPath('data.progress.to', 'destination');
    }

    public function test_both_hear_once_that_the_free_waiting_ends_soon(): void
    {
        $this->price(['waiting.free_s' => 180, 'waiting.warn_before_s' => 60]);
        $driver = $this->onlineDriver();
        $uuid = $this->book($this->quote(), 'cash')->json('data.uuid');
        $this->accept($driver);
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/arrive", ['lat' => self::PICKUP['lat'], 'lng' => self::PICKUP['lng']])->assertOk()
            ->assertJsonPath('data.waiting.warn_before_s', 60);

        $this->travel(60)->seconds();
        $this->artisan('rides:dispatch-tick')->assertSuccessful();
        $this->assertNull(Ride::query()->where('uuid', $uuid)->value('wait_warned_at'), 'too early');

        $this->travel(70)->seconds();
        $this->artisan('rides:dispatch-tick')->assertSuccessful();
        $warned = Ride::query()->where('uuid', $uuid)->value('wait_warned_at');
        $this->assertNotNull($warned);

        $this->travel(20)->seconds();
        $this->artisan('rides:dispatch-tick')->assertSuccessful();
        $this->assertEquals($warned, Ride::query()->where('uuid', $uuid)->value('wait_warned_at'), 'only once');
    }

    /** @param array<string, mixed> $values */
    private function price(array $values): void
    {
        app(RidePolicies::class)->save($this->saudi->id, null, null, $this->economyId, [
            'price.base_minor' => 600, 'price.per_km_minor' => 180, 'price.per_min_minor' => 30, 'price.min_fare_minor' => 1500,
        ] + $values);
        RidePolicies::forget();
    }

    /** @return array<string, mixed> */
    private function quote(): array
    {
        return $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/quotes', ['origin' => self::PICKUP, 'destination' => self::DROPOFF])->assertOk()->json('data.0');
    }

    /**
     * @param  array<string, mixed>  $quote
     * @param  array<string, mixed>  $extra
     */
    private function book(array $quote, string $method, array $extra = []): TestResponse
    {
        return $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides', ['quote_uuid' => $quote['uuid'], 'fingerprint' => $quote['fingerprint'], 'payment_method' => $method] + $extra, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function change(string $uuid, string $method, ?string $key = null): TestResponse
    {
        return $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/$uuid/payment-method", ['payment_method' => $method], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function ride(string $uuid): TestResponse
    {
        return $this->actingAs($this->rider, 'user_api')->getJson("/api/mobile/v1/rides/$uuid")->assertOk();
    }

    private function accept(Driver $driver): void
    {
        $offer = RideOffer::query()->where('driver_id', $driver->id)->where('status', 'sent')->sole();
        $this->asDriver($driver)->postJson("/api/driver/v1/offers/{$offer->uuid}/accept")->assertOk();
    }

    private function drive(Driver $driver, string $uuid): void
    {
        $this->accept($driver);
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/arrive", ['lat' => self::PICKUP['lat'], 'lng' => self::PICKUP['lng']])->assertOk();
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/start", ['lat' => self::PICKUP['lat'], 'lng' => self::PICKUP['lng']])->assertOk();
    }

    private function onlineDriver(): Driver
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
            'year' => 2022, 'body_type' => 'sedan', 'seats' => 4, 'plate_letters' => 'ا ب ج', 'plate_number' => '1001', 'plate_type' => 1,
            'status' => VehicleStatus::Approved, 'is_active' => true,
        ]);
        DriverPresence::query()->create([
            'driver_id' => $driver->id, 'status' => PresenceStatus::Online, 'lat' => 24.7300, 'lng' => 46.6900, 'gps_enabled' => true,
            'online_since' => now(), 'last_seen_at' => now(),
        ]);

        return $driver;
    }

    private function asDriver(Driver $driver): static
    {
        return $this->actingAs($driver, 'driver_api');
    }

    private function fund(User $user, int $minor): void
    {
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->firstOrCreateWallet($user, $this->saudi), $minor, WalletBucket::Withdrawable, WalletTransactionType::Topup, ['idempotency_key' => 'fund:'.$user->id]);
    }

    private function wallet(): Wallet
    {
        return app(WalletService::class)->firstOrCreateWallet($this->rider, $this->saudi)->fresh();
    }

    private function held(): int
    {
        $wallet = $this->wallet();

        return (int) $wallet->held_spend_only_minor + (int) $wallet->held_withdrawable_minor;
    }
}
