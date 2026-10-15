<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Discover\Models\DiscoverCity;
use Modules\Driver\Enums\DriverStatus;
use Modules\Driver\Enums\PresenceStatus;
use Modules\Driver\Enums\VehicleStatus;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverPresence;
use Modules\Driver\Models\DriverPresenceSession;
use Modules\Driver\Models\Vehicle;
use Modules\Driver\Models\VehicleColor;
use Modules\Driver\Models\VehicleMake;
use Modules\Driver\Models\VehicleModel;
use Modules\Rides\Events\DriverChanged;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideActiveLock;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideFeatureRule;
use Modules\Rides\Services\Drivers\DriverEligibility;
use Modules\Rides\Services\Drivers\DrivingHours;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Batch 7 of docs/rides_tasks.md: driving-time limits (D-42, D-43) — time online over the last window
 * from the presence sessions, a rest reminder at the warning level, and at the mandatory limit no going
 * online, no new requests and (with no ride running) off the road; every limit off until the country sets it.
 */
class RidesDrivingHoursTest extends TestCase
{
    private Country $saudi;

    private int $plate = 3000;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(fn () => Http::response([], 200));
        Event::fake([DriverChanged::class]);
        config(['rides.regulator_enforced' => false, 'broadcasting.default' => 'null']);

        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id,
            'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2])->id,
        ]);
        DiscoverCity::query()->create(['country_id' => $this->saudi->id, 'timezone' => 'Asia/Riyadh', 'lat' => 24.7136, 'lng' => 46.6753, 'status' => true, 'sort_order' => 1]);
        RideFeatureRule::query()->create(['feature' => 'rides', 'country_id' => $this->saudi->id, 'enabled' => true]);
        RideFeatures::forget();
    }

    public function test_nothing_is_limited_until_the_country_sets_a_limit(): void
    {
        $driver = $this->captain('Omar');
        $this->stretch($driver, now()->subHours(20), null);

        $this->asDriver($driver)->getJson('/api/driver/v1/driving-hours')->assertOk()
            ->assertJsonPath('data.enabled', false)->assertJsonPath('data.level', 'ok')->assertJsonPath('data.remaining_s', null);
        $this->assertNull(app(DriverEligibility::class)->reasonNotToWork($driver));
    }

    public function test_the_time_online_in_the_window_is_counted_clipped_and_levelled(): void
    {
        $this->limits(['driving.window_h' => 24, 'driving.warn_s' => 7 * 3600, 'driving.max_s' => 10 * 3600]);
        $driver = $this->captain('Omar');
        // 30 h ago for 8 h → only the last 2 h of it are inside the 24-hour window; then 3 h; then 1 h running now.
        $this->stretch($driver, now()->subHours(30), now()->subHours(22));
        $this->stretch($driver, now()->subHours(10), now()->subHours(7));
        $this->stretch($driver, now()->subHour(), null);

        $status = $this->asDriver($driver)->getJson('/api/driver/v1/driving-hours')->assertOk()->json('data');
        $this->assertSame([true, 24, 7 * 3600, 10 * 3600], [$status['enabled'], $status['window_h'], $status['warn_s'], $status['max_s']]);
        $this->assertEqualsWithDelta(6 * 3600, $status['used_s'], 5, '2 h + 3 h + 1 h');
        $this->assertSame('ok', $status['level']);
        $this->assertEqualsWithDelta(4 * 3600, $status['remaining_s'], 5);
        $this->assertNull($status['free_at']);

        // Another 1.5 h online and the warning level is reached, the limit not yet.
        $this->stretch($driver, now()->subMinutes(300), now()->subMinutes(210));
        $this->assertSame('warn', $this->asDriver($driver)->getJson('/api/driver/v1/driving-hours')->json('data.level'));
    }

    public function test_the_reminder_is_sent_once_per_window(): void
    {
        $this->limits(['driving.warn_s' => 3600, 'driving.max_s' => 10 * 3600]);
        $driver = $this->captain('Omar');
        $this->online($driver);
        $this->stretch($driver, now()->subHours(2), null);
        $other = $this->captain('Rested', '+966550000002');
        $this->online($other);
        $this->stretch($other, now()->subMinutes(10), null);

        $count = fn () => (int) $this->asDriver($driver)->getJson('/api/driver/v1/notifications/unread-count')->assertOk()->json('data.count');
        $this->withoutDefer();
        $before = $count();
        $this->assertSame(1, app(DrivingHours::class)->sweep());
        $this->assertSame($before + 1, $count());
        $this->assertSame(0, app(DrivingHours::class)->sweep(), 'told once per window');
        $this->assertSame($before + 1, $count());
        $this->assertTrue($this->isOnline($driver), 'a warning does not stop anyone');
        Event::assertDispatched(DriverChanged::class, fn (DriverChanged $e) => $e->driverId === $driver->id && $e->event === 'driving_hours');
    }

    public function test_over_the_mandatory_limit_the_captain_is_stopped_and_told_when_they_can_return(): void
    {
        $this->limits(['driving.max_s' => 3 * 3600]);
        $driver = $this->captain('Omar');
        $this->online($driver);
        // 4 h online, the first hour of it ended 3.5 h ago… the stretch runs from 4 h ago to now.
        $this->stretch($driver, now()->subHours(4), null);

        $status = $this->asDriver($driver)->getJson('/api/driver/v1/driving-hours')->assertOk()->json('data');
        $this->assertSame('limit', $status['level']);
        $this->assertSame(0, $status['remaining_s']);
        // 4 h used, 3 h allowed → 1 h + 1 s over; the oldest stretch leaves the window 20 h after it began… = 24 h after (4 h ago + 1 h + 1 s).
        $this->assertEqualsWithDelta(now()->subHours(3)->addHours(24)->getTimestamp(), strtotime($status['free_at']), 5);

        // Not offered work, can't go online, and the reason says why.
        $this->assertSame('driving_limit', app(DriverEligibility::class)->reasonNotToWork($driver));
        $this->asDriver($driver)->getJson('/api/driver/v1/presence')->assertOk()->assertJsonPath('data.can_go_online', false)->assertJsonPath('data.reason', 'driving_limit');

        // The sweep takes them offline (no ride running) and ends the session with the reason.
        $this->withoutDefer();
        app(DrivingHours::class)->sweep();
        $this->assertFalse($this->isOnline($driver));
        $this->assertSame('driving_limit', DriverPresenceSession::query()->where('driver_id', $driver->id)->latest('id')->value('end_reason'));
        $this->asDriver($driver)->postJson('/api/driver/v1/presence/online', ['lat' => 24.7, 'lng' => 46.67])->assertStatus(422)->assertJsonPath('error_code', 'driver_cannot_go_online');

        // Lifting the limit lets them back.
        $this->limits(['driving.max_s' => 0]);
        $this->asDriver($driver)->postJson('/api/driver/v1/presence/online', ['lat' => 24.7, 'lng' => 46.67])->assertOk();
    }

    public function test_a_ride_in_progress_finishes_before_the_captain_is_taken_offline(): void
    {
        $this->limits(['driving.max_s' => 3600]);
        $driver = $this->captain('Omar');
        $this->online($driver, PresenceStatus::OnTrip);
        $this->stretch($driver, now()->subHours(3), null);
        RideActiveLock::query()->create(['lock_key' => RideActiveLock::forDriver($driver->id), 'ride_id' => $this->ride($driver)->id]);

        $this->withoutDefer();
        app(DrivingHours::class)->sweep();
        $this->assertTrue($this->isOnline($driver), 'still on the ride');

        RideActiveLock::query()->delete();
        app(DrivingHours::class)->sweep();
        $this->assertFalse($this->isOnline($driver));
    }

    private function ride(Driver $driver): Ride
    {
        $rider = User::query()->create(['name' => 'Sara Ahmed', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $category = RideCategory::query()->create(['slug' => 'economy', 'pricing_strategy' => 'distance', 'status' => true, 'sort_order' => 1, 'capacity_max' => 4]);

        return Ride::query()->create([
            'uuid' => (string) Str::uuid(), 'number' => 'DR-HRS-1', 'user_id' => $rider->id, 'driver_id' => $driver->id, 'category_id' => $category->id,
            'country_id' => $this->saudi->id, 'currency_id' => $this->saudi->currency_id, 'status' => 'in_progress', 'pricing_mode' => 'upfront',
            'confirmed_snapshot' => [], 'payment_method' => 'cash', 'payment_status' => 'cash_due',
            'origin_lat' => 24.7136, 'origin_lng' => 46.6753, 'destination_lat' => 24.75, 'destination_lng' => 46.7,
        ]);
    }

    private function limits(array $values): void
    {
        app(RidePolicies::class)->save($this->saudi->id, null, null, null, $values);
        RidePolicies::forget();
        Cache::flush();
    }

    private function stretch(Driver $driver, $start, $end): void
    {
        DriverPresenceSession::query()->create(['driver_id' => $driver->id, 'started_at' => $start, 'ended_at' => $end]);
    }

    private function online(Driver $driver, PresenceStatus $status = PresenceStatus::Online): void
    {
        DriverPresence::query()->updateOrCreate(['driver_id' => $driver->id], [
            'status' => $status, 'lat' => 24.7136, 'lng' => 46.6753, 'gps_enabled' => true, 'online_since' => now()->subHours(1), 'last_seen_at' => now(),
        ]);
    }

    private function isOnline(Driver $driver): bool
    {
        return in_array(DriverPresence::query()->find($driver->id)?->status, [PresenceStatus::Online, PresenceStatus::OnTrip], true);
    }

    private function captain(string $name, string $phone = '+966550000001'): Driver
    {
        $driver = Driver::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => $name, 'phone' => $phone, 'phone_verified_at' => now(),
            'country_id' => $this->saudi->id, 'status' => DriverStatus::Active,
        ]);
        $make = VehicleMake::query()->create(['status' => true]);
        $model = VehicleModel::query()->create(['make_id' => $make->id, 'body_type' => 'sedan', 'seats' => 4, 'status' => true]);
        Vehicle::query()->create([
            'uuid' => (string) Str::uuid(), 'driver_id' => $driver->id, 'country_id' => $this->saudi->id, 'make_id' => $make->id, 'model_id' => $model->id,
            'color_id' => VehicleColor::query()->create(['hex' => '#FFFFFF', 'status' => true])->id,
            'year' => 2022, 'body_type' => 'sedan', 'seats' => 4, 'plate_letters' => 'ا ب ج', 'plate_number' => (string) $this->plate++, 'plate_type' => 1,
            'status' => VehicleStatus::Approved, 'is_active' => true,
        ]);

        return $driver;
    }

    private function asDriver(Driver $driver): static
    {
        return $this->actingAs($driver, 'driver_api');
    }
}
