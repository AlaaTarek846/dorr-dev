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
use Modules\Rides\Models\DriverDailyStat;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideActiveLock;
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
use Modules\Wallet\Services\PinService;
use Modules\Wallet\Services\WalletService;
use Tests\TestCase;

/**
 * Rides booked ahead (R-12, R-105..108, R-165, R-170, D-260..269): paid or held at booking, no search
 * and no rider lock until the time comes near; free cancellation until an hour before, the fee after;
 * a captain may reserve it and gets it straight away at the time — or it searches like any ride;
 * the waiting never starts before the time.
 */
class RidesScheduledTest extends TestCase
{
    private const PICKUP = ['lat' => 24.7136, 'lng' => 46.6753, 'address' => 'Olaya'];

    private const DROPOFF = ['lat' => 24.7500, 'lng' => 46.7000, 'address' => 'King Fahd Rd'];

    private Country $saudi;

    private User $rider;

    private int $n = 0;

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
        $economy = RideCategory::query()->create([
            'slug' => 'economy', 'pricing_strategy' => 'distance', 'status' => true, 'sort_order' => 1, 'capacity_max' => 4,
            'flags' => ['cash_allowed' => true],
        ]);
        foreach (['rides', 'category.economy', 'wallet', 'card', 'cash', 'scheduled'] as $feature) {
            RideFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        RideFeatures::forget();
        RidePolicies::forget();
        CityResolver::forget();
        app(RidePolicies::class)->save($this->saudi->id, null, null, $economy->id, [
            'price.base_minor' => 600, 'price.per_km_minor' => 180, 'price.per_min_minor' => 30, 'price.min_fare_minor' => 1500,
            'price.cancel_fee_minor' => 1000,
        ]);
        $this->rider = User::query()->create(['name' => 'Sara Ahmed', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->freezeTime();
    }

    public function test_a_booking_ahead_holds_the_money_and_waits_without_searching_or_locking_the_rider(): void
    {
        $this->fund(10000);
        $driver = $this->driver();
        $quote = $this->quote()->assertOk()->json('data.0');
        $this->assertSame(3600, $quote['scheduling']['min_ahead_s']);

        $at = now()->addHours(3)->startOfMinute();
        $ride = $this->book($quote, 'wallet', $at)->assertCreated()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.scheduled.at', $at->toIso8601String())
            ->assertJsonPath('data.scheduled.free_cancel_until', $at->copy()->subHour()->toIso8601String())
            ->json('data');

        $this->assertSame($quote['hold_minor'], $this->held(), 'paid ahead: held now');
        $this->assertSame(0, RideOffer::query()->count(), 'nobody is offered it yet');
        $this->assertSame(0, RideActiveLock::query()->count(), 'the rider may still ride now');
        $this->assertEmpty($this->actingAs($this->rider, 'user_api')->getJson('/api/mobile/v1/rides/current')->assertOk()->json('data'));

        // Riding now meanwhile is fine.
        $this->book($this->quote()->json('data.0'), 'cash')->assertCreated()->assertJsonPath('data.status', 'searching');
        $this->assertSame($driver->id, RideOffer::query()->sole()->driver_id);

        $this->actingAs($this->rider, 'user_api')->getJson('/api/mobile/v1/rides?tab=scheduled')->assertOk()
            ->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.uuid', $ride['uuid'])
            ->assertJsonPath('data.items.0.scheduled_at', $at->toIso8601String());
        $this->actingAs($this->rider, 'user_api')->getJson('/api/mobile/v1/rides?tab=current')->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_the_money_stays_held_for_a_booking_days_ahead(): void
    {
        $this->fund(10000);
        $quote = $this->quote()->json('data.0');
        $this->book($quote, 'wallet', now()->addDays(5))->assertCreated();

        $this->travel(2)->days();
        app(WalletService::class)->releaseExpiredHolds();
        $this->assertSame($quote['hold_minor'], $this->held(), 'the one-day safety valve counts from the booked time');
    }

    public function test_the_time_must_be_inside_the_policy_window_and_the_switch_on(): void
    {
        $quote = $this->quote()->json('data.0');
        $this->book($quote, 'cash', now()->addMinutes(30))->assertStatus(422)->assertJsonPath('error_code', 'rides_scheduled_too_soon');
        $this->book($quote, 'cash', now()->addDays(8))->assertStatus(422)->assertJsonPath('error_code', 'rides_scheduled_too_far');

        $this->book($quote, 'cash', now()->addHours(2))->assertCreated();
        $this->book($this->quote()->json('data.0'), 'cash', now()->addHours(2)->addMinutes(20))->assertStatus(422)
            ->assertJsonPath('error_code', 'rides_scheduled_overlap');

        RideFeatureRule::query()->where('feature', 'scheduled')->update(['enabled' => false]);
        RideFeatures::forget();
        $this->book($this->quote()->json('data.0'), 'cash', now()->addDay())->assertForbidden()->assertJsonPath('error_code', 'rides_feature_disabled');
    }

    public function test_free_cancellation_until_an_hour_before_then_the_fee(): void
    {
        $this->fund(10000);
        $at = now()->addHours(3)->startOfMinute();
        $first = $this->book($this->quote()->json('data.0'), 'wallet', $at)->json('data.uuid');

        $this->actingAs($this->rider, 'user_api')->getJson("/api/mobile/v1/rides/$first/cancel/preview")->assertOk()
            ->assertJsonPath('data.fee_minor', 0)->assertJsonPath('data.free_until', $at->copy()->subHour()->toIso8601String());
        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/$first/cancel")->assertOk()->assertJsonPath('data.status', 'canceled');
        $this->assertSame(0, $this->held());
        $this->assertSame(10000, $this->balance());

        $second = $this->book($this->quote()->json('data.0'), 'wallet', $at)->json('data.uuid');
        $this->travelTo($at->copy()->subMinutes(50));
        $this->actingAs($this->rider, 'user_api')->getJson("/api/mobile/v1/rides/$second/cancel/preview")->assertOk()
            ->assertJsonPath('data.fee_minor', 1000)->assertJsonPath('data.free_until', null);
        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/$second/cancel")->assertOk();
        $this->assertSame(9000, $this->balance());
        $this->assertSame(0, $this->held());
    }

    public function test_it_starts_by_itself_near_its_time_with_a_reminder_first(): void
    {
        $at = now()->addHours(3)->startOfMinute();
        $uuid = $this->book($this->quote()->json('data.0'), 'cash', $at)->json('data.uuid');
        $driver = $this->driver();

        $this->travelTo($at->copy()->subMinutes(59));
        $this->artisan('rides:dispatch-tick')->assertSuccessful();
        $ride = Ride::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertNotNull($ride->reminded_at, 'reminded an hour before');
        $this->assertSame('scheduled', $ride->status->value);

        $this->travelTo($at->copy()->subMinutes(30));
        $this->presence($driver);
        $this->artisan('rides:dispatch-tick')->assertSuccessful();
        $ride->refresh();
        $this->assertSame('searching', $ride->status->value);
        $this->assertNotNull($ride->search_started_at);
        $this->assertSame($driver->id, RideOffer::query()->where('status', 'sent')->sole()->driver_id);
        $this->assertTrue(RideActiveLock::query()->whereKey(RideActiveLock::forRider($this->rider->id))->exists());
    }

    public function test_a_captain_reserves_it_gets_it_at_the_time_and_the_waiting_starts_at_the_time(): void
    {
        $at = now()->addHours(3)->startOfMinute();
        $uuid = $this->book($this->quote()->json('data.0'), 'cash', $at)->json('data.uuid');
        $captain = $this->driver();
        $nearer = $this->driver(24.7137, 46.6754);

        $card = $this->asDriver($captain)->getJson('/api/driver/v1/scheduled')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame($uuid, $card['uuid']);
        $this->assertGreaterThan(0, $card['earning_minor']);
        $this->assertSame($at->copy()->subMinutes(30)->toIso8601String(), $card['online_from']);

        $this->asDriver($captain)->postJson("/api/driver/v1/scheduled/$uuid/reserve")->assertOk()->assertJsonPath('data.mine', true);
        $this->asDriver($nearer)->postJson("/api/driver/v1/scheduled/$uuid/reserve")->assertStatus(409)->assertJsonPath('error_code', 'rides_reservation_taken');
        $this->asDriver($nearer)->getJson('/api/driver/v1/scheduled')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->rider, 'user_api')->getJson("/api/mobile/v1/rides/$uuid")->assertOk()
            ->assertJsonPath('data.reserved_driver.name', $captain->name)->assertJsonPath('data.scheduled.reserved', true);

        // Too early to head out, then the captain heads out an hour before.
        $this->asDriver($captain)->postJson("/api/driver/v1/scheduled/$uuid/start")->assertStatus(409)->assertJsonPath('error_code', 'rides_start_too_early');
        $this->travelTo($at->copy()->subMinutes(45));
        $this->presence($captain);
        $this->asDriver($captain)->getJson('/api/driver/v1/scheduled/mine')->assertOk()->assertJsonPath('data.0.can_start', true);
        $this->asDriver($captain)->postJson("/api/driver/v1/scheduled/$uuid/start")->assertOk()
            ->assertJsonPath('data.status', 'accepted');
        $this->assertSame(0, RideOffer::query()->count(), 'straight to its captain, no search');

        // Arriving early: the free waiting and the no-show clock begin only at the time.
        $this->asDriver($captain)->postJson("/api/driver/v1/rides/$uuid/arrive", ['lat' => self::PICKUP['lat'], 'lng' => self::PICKUP['lng']])->assertOk()
            ->assertJsonPath('data.times.waiting_from', $at->toIso8601String());
        $this->travelTo($at->copy()->subMinutes(5));
        $this->asDriver($captain)->postJson("/api/driver/v1/rides/$uuid/no-show")->assertStatus(409)->assertJsonPath('error_code', 'rides_no_show_too_early');
        $this->travelTo($at->copy()->addMinutes(6));
        $this->asDriver($captain)->postJson("/api/driver/v1/rides/$uuid/no-show")->assertOk()->assertJsonPath('data.status', 'no_show');
    }

    public function test_an_offline_captain_loses_the_reservation_and_the_ride_searches_like_any_other(): void
    {
        $at = now()->addHours(5)->startOfMinute();
        $uuid = $this->book($this->quote()->json('data.0'), 'cash', $at)->json('data.uuid');
        $captain = $this->driver();
        $this->asDriver($captain)->postJson("/api/driver/v1/scheduled/$uuid/reserve")->assertOk();

        // Another booking on top of it is refused (D-267).
        $rider2 = User::query()->create(['name' => 'Omar', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $other = $this->book($this->quote($rider2)->json('data.0'), 'cash', $at->copy()->addMinutes(10))->json('data.uuid');
        $this->asDriver($captain)->postJson("/api/driver/v1/scheduled/$other/reserve")->assertStatus(409)->assertJsonPath('error_code', 'rides_reservation_overlap');

        $backup = $this->driver(24.7140, 46.6755);
        $this->travelTo($at->copy()->subMinutes(30));
        $this->presence($backup);
        DriverPresence::query()->whereKey($captain->id)->update(['status' => PresenceStatus::Offline->value]);
        $this->artisan('rides:dispatch-tick')->assertSuccessful();

        $ride = Ride::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame('searching', $ride->status->value);
        $this->assertNull($ride->reserved_driver_id);
        $this->assertTrue(RideOffer::query()->where('ride_id', $ride->id)->where('driver_id', $backup->id)->exists());
        $this->assertSame(1, (int) DriverDailyStat::query()->where('driver_id', $captain->id)->sum('cancels'), 'a missed reservation counts');
    }

    public function test_giving_a_reservation_back_late_counts_as_a_cancel_and_early_does_not(): void
    {
        $at = now()->addHours(5)->startOfMinute();
        $uuid = $this->book($this->quote()->json('data.0'), 'cash', $at)->json('data.uuid');
        $captain = $this->driver();

        $this->asDriver($captain)->postJson("/api/driver/v1/scheduled/$uuid/reserve")->assertOk()->assertJsonPath('data.release_counts_as_cancel', false);
        $this->asDriver($captain)->postJson("/api/driver/v1/scheduled/$uuid/release")->assertOk();
        $this->assertSame(0, (int) DriverDailyStat::query()->where('driver_id', $captain->id)->sum('cancels'));

        $this->asDriver($captain)->postJson("/api/driver/v1/scheduled/$uuid/reserve")->assertOk();
        $this->travelTo($at->copy()->subMinutes(90));
        $this->presence($captain);
        $this->asDriver($captain)->getJson('/api/driver/v1/scheduled/mine')->assertOk()->assertJsonPath('data.0.release_counts_as_cancel', true);
        $this->asDriver($captain)->postJson("/api/driver/v1/scheduled/$uuid/release")->assertOk();
        $this->assertSame(1, (int) DriverDailyStat::query()->where('driver_id', $captain->id)->sum('cancels'));
        $this->assertNull(Ride::query()->where('uuid', $uuid)->value('reserved_driver_id'));
    }

    public function test_a_card_booking_waits_for_its_time_once_paid(): void
    {
        $this->fund(10000);
        app(PinService::class)->set($this->rider, '1234');
        $this->driver();
        $ride = $this->book($this->quote()->json('data.0'), 'card', now()->addHours(4))->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_payment')->json('data');

        $this->postJson("/api/mobile/v1/wallet/checkouts/{$ride['checkout_id']}/pay", ['method' => 'wallet'], ['X-Country' => 'SA', 'X-Wallet-Pin' => '1234'])
            ->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertSame('scheduled', Ride::query()->where('uuid', $ride['uuid'])->value('status')->value);
        $this->assertSame(0, RideOffer::query()->count());
        $this->assertGreaterThan(0, $this->held());
    }

    public function test_a_rider_still_on_another_ride_misses_the_booking_after_the_grace_and_pays_nothing(): void
    {
        $this->fund(10000);
        $at = now()->addHours(2)->startOfMinute();
        $uuid = $this->book($this->quote()->json('data.0'), 'wallet', $at)->json('data.uuid');

        $this->travelTo($at->copy()->subMinutes(35));
        $this->book($this->quote()->json('data.0'), 'cash')->assertCreated(); // riding now, holding the rider's lock

        $this->travelTo($at->copy()->subMinutes(30));
        $this->artisan('rides:dispatch-tick')->assertSuccessful();
        $this->assertSame('scheduled', Ride::query()->where('uuid', $uuid)->value('status')->value, 'tried again later');

        $this->travelTo($at->copy()->addMinutes(16));
        $this->artisan('rides:dispatch-tick')->assertSuccessful();
        $this->assertSame('expired', Ride::query()->where('uuid', $uuid)->value('status')->value);
        $this->assertSame(10000, $this->balance());
        $this->assertSame(0, $this->held());
    }

    // ---------------------------------------------------------------- helpers

    private function quote(?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->rider, 'user_api')->postJson('/api/mobile/v1/rides/quotes', ['origin' => self::PICKUP, 'destination' => self::DROPOFF]);
    }

    /** @param array<string, mixed> $quote */
    private function book(array $quote, string $method, mixed $at = null): TestResponse
    {
        return $this->postJson('/api/mobile/v1/rides', array_filter([
            'quote_uuid' => $quote['uuid'], 'fingerprint' => $quote['fingerprint'], 'payment_method' => $method,
            'scheduled_at' => $at?->toIso8601String(),
        ]), ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function driver(float $lat = 24.7150, float $lng = 46.6760): Driver
    {
        $n = ++$this->n;
        $driver = Driver::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => "Captain $n", 'phone' => '+96655000000'.$n, 'phone_verified_at' => now(),
            'country_id' => $this->saudi->id, 'status' => DriverStatus::Active,
        ]);
        $make = VehicleMake::query()->create(['status' => true]);
        $model = VehicleModel::query()->create(['make_id' => $make->id, 'body_type' => 'sedan', 'seats' => 4, 'status' => true]);
        Vehicle::query()->create([
            'uuid' => (string) Str::uuid(), 'driver_id' => $driver->id, 'country_id' => $this->saudi->id, 'make_id' => $make->id, 'model_id' => $model->id,
            'color_id' => VehicleColor::query()->create(['hex' => '#FFFFFF', 'status' => true])->id,
            'year' => 2022, 'body_type' => 'sedan', 'seats' => 4, 'plate_letters' => 'ا ب ج', 'plate_number' => (string) (1000 + $n), 'plate_type' => 1,
            'status' => VehicleStatus::Approved, 'is_active' => true,
        ]);
        DriverPresence::query()->create([
            'driver_id' => $driver->id, 'status' => PresenceStatus::Online, 'lat' => $lat, 'lng' => $lng, 'gps_enabled' => true,
            'online_since' => now(), 'last_seen_at' => now(),
        ]);

        return $driver;
    }

    /** The captain's app reported its place just now. */
    private function presence(Driver $driver): void
    {
        DriverPresence::query()->whereKey($driver->id)->update(['status' => PresenceStatus::Online->value, 'last_seen_at' => now()]);
    }

    private function asDriver(Driver $driver): static
    {
        return $this->actingAs($driver, 'driver_api');
    }

    private function fund(int $minor): void
    {
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->firstOrCreateWallet($this->rider, $this->saudi), $minor, WalletBucket::Withdrawable, WalletTransactionType::Topup, ['idempotency_key' => 'fund:'.$this->rider->id]);
    }

    private function wallet(): Wallet
    {
        return app(WalletService::class)->firstOrCreateWallet($this->rider, $this->saudi)->fresh();
    }

    private function held(): int
    {
        $wallet = $this->wallet();

        return (int) ($wallet->held_spend_only_minor + $wallet->held_withdrawable_minor);
    }

    private function balance(): int
    {
        $wallet = $this->wallet();

        return (int) ($wallet->spend_only_minor + $wallet->withdrawable_minor);
    }
}
