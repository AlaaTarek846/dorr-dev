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
use Modules\Driver\Events\DriverLocationUpdated;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverPresence;
use Modules\Driver\Models\Vehicle;
use Modules\Driver\Models\VehicleColor;
use Modules\Driver\Models\VehicleMake;
use Modules\Driver\Models\VehicleModel;
use Modules\Rides\Events\DriverChanged;
use Modules\Rides\Events\RideChanged;
use Modules\Rides\Events\RideDriverMoved;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideFeatureRule;
use Modules\Rides\Models\RidePickupPoint;
use Modules\Rides\Models\RideQueueEntry;
use Modules\Rides\Models\RideZone;
use Modules\Rides\Services\Airport\AirportQueue;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\CityResolver;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Batch 4 of docs/rides_tasks.md: the airport queue (D-276..D-280), the official points on the captain's
 * Airport screen (D-281), the restricted areas and the alert for entering one (D-282, D-283) — joining by
 * hand or by itself, the place in line and the wait estimate, every way out, an airport ride going to
 * the first captain in line, and the quiet cases (switch off, elsewhere).
 */
class RidesAirportQueueTest extends TestCase
{
    private const AIRPORT = [[24.95, 46.68], [24.95, 46.75], [25.00, 46.75], [25.00, 46.68]];

    private const GATE = ['lat' => 24.9700, 'lng' => 46.7000, 'address' => 'Gate 3'];

    private const CITY_SPOT = ['lat' => 24.7136, 'lng' => 46.6753, 'address' => 'Olaya'];

    private Country $saudi;

    private User $rider;

    private int $economyId;

    private RideZone $airport;

    private RidePickupPoint $gate3;

    private int $plate = 1000;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(fn () => Http::response([], 200));
        Event::fake([RideChanged::class, RideDriverMoved::class, DriverChanged::class]);
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
        $this->switches(['rides', 'category.economy', 'cash', 'pickup_points', 'airport_queue', 'restricted_zones']);
        app(RidePolicies::class)->save($this->saudi->id, null, null, $this->economyId, [
            'price.base_minor' => 600, 'price.per_km_minor' => 180, 'price.per_min_minor' => 30, 'price.min_fare_minor' => 1500,
        ]);
        RidePolicies::forget();

        $this->airport = $this->zone('airport', ['ar' => 'مطار الملك خالد', 'en' => 'King Khalid Airport'], self::AIRPORT, ['queue_enabled' => true]);
        $this->gate3 = new RidePickupPoint(['zone_id' => $this->airport->id, 'lat' => 24.97, 'lng' => 46.70, 'kind' => 'gate', 'for_pickup' => true, 'for_dropoff' => true, 'status' => true]);
        $this->gate3->fillAllTranslations(['name' => ['ar' => 'بوابة 3', 'en' => 'Gate 3'], 'instructions' => ['en' => 'By the pillar']])->save();

        $this->rider = User::query()->create(['name' => 'Sara Ahmed', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    public function test_a_captain_joins_by_hand_sees_their_place_and_the_official_points_and_leaves(): void
    {
        $first = $this->captain('First', '+966550000001', 24.9660, 46.7000);
        $second = $this->captain('Second', '+966550000002', 24.9680, 46.7000);
        $outside = $this->captain('Outside', '+966550000003', 24.7136, 46.6753);

        // Nothing around a captain in the city.
        $this->asDriver($outside)->getJson('/api/driver/v1/airport-queue')->assertOk()->assertJsonPath('data.zone', null)->assertJsonPath('data.can_join', false);
        $this->asDriver($outside)->postJson('/api/driver/v1/airport-queue/join')->assertStatus(422)->assertJsonPath('error_code', 'rides_airport_queue_not_inside');

        // At the airport, not yet in line: where they are, who is waiting, the official points (D-281).
        $this->asDriver($first)->getJson('/api/driver/v1/airport-queue')->assertOk()
            ->assertJsonPath('data.zone.name', 'King Khalid Airport')->assertJsonPath('data.inside', true)->assertJsonPath('data.queued', false)
            ->assertJsonPath('data.can_join', true)->assertJsonPath('data.entry_mode', 'manual')->assertJsonPath('data.pickup_points.0.name', 'Gate 3')
            ->assertJsonPath('data.rules.leave_grace_s', 120);

        $this->asDriver($first)->postJson('/api/driver/v1/airport-queue/join')->assertOk()->assertJsonPath('data.position', 1)->assertJsonPath('data.entry', 'manual');
        $this->asDriver($second)->postJson('/api/driver/v1/airport-queue/join')->assertOk()->assertJsonPath('data.position', 2)->assertJsonPath('data.size', 2);
        // Joining again (a double tap) changes nothing.
        $this->asDriver($first)->postJson('/api/driver/v1/airport-queue/join')->assertOk()->assertJsonPath('data.position', 1);
        $this->assertSame(2, RideQueueEntry::query()->where('status', 'waiting')->count());
        $this->asDriver($first)->getJson('/api/driver/v1/airport-queue')->assertJsonPath('data.estimated_wait_s', null);

        // The first leaves: the second moves up, and both hear it on their own channels.
        $this->asDriver($first)->postJson('/api/driver/v1/airport-queue/leave')->assertOk()->assertJsonPath('data.queued', false);
        $this->asDriver($second)->getJson('/api/driver/v1/airport-queue')->assertJsonPath('data.position', 1)->assertJsonPath('data.size', 1);
        Event::assertDispatched(DriverChanged::class, fn (DriverChanged $e) => $e->driverId === $second->id && $e->event === 'queue');
        $left = RideQueueEntry::query()->where('driver_id', $first->id)->sole();
        $this->assertSame(['left', 'manual'], [$left->status, $left->left_reason]);
    }

    public function test_the_queue_needs_its_switch_its_zone_and_a_captain_able_to_work(): void
    {
        $captain = $this->captain('First', '+966550000001', 24.9660, 46.7000);

        $this->switches(['airport_queue'], false);
        $this->asDriver($captain)->getJson('/api/driver/v1/airport-queue')->assertOk()->assertJsonPath('data.zone', null);
        $this->asDriver($captain)->postJson('/api/driver/v1/airport-queue/join')->assertStatus(422)->assertJsonPath('error_code', 'rides_airport_queue_not_inside');

        $this->switches(['airport_queue']);
        $this->airport->update(['config' => ['queue_enabled' => false]]);
        $this->asDriver($captain)->getJson('/api/driver/v1/airport-queue')->assertOk()->assertJsonPath('data.zone', null);

        $this->airport->update(['config' => ['queue_enabled' => true]]);
        DriverPresence::query()->whereKey($captain->id)->update(['status' => PresenceStatus::Offline->value]);
        $this->asDriver($captain)->postJson('/api/driver/v1/airport-queue/join')->assertStatus(422)->assertJsonPath('error_code', 'rides_airport_queue_offline');
        $this->asDriver($captain)->getJson('/api/driver/v1/airport-queue')->assertJsonPath('data.can_join', false)->assertJsonPath('data.cannot_join_reason', 'offline');

        DriverPresence::query()->whereKey($captain->id)->update(['status' => PresenceStatus::Online->value, 'last_seen_at' => now()->subMinutes(20)]);
        $this->asDriver($captain)->postJson('/api/driver/v1/airport-queue/join')->assertStatus(422)->assertJsonPath('error_code', 'rides_airport_queue_stale');
    }

    public function test_a_captain_in_an_automatic_zone_gets_in_line_by_arriving(): void
    {
        app(RidePolicies::class)->save($this->saudi->id, null, $this->airport->id, null, ['queue.entry' => 'auto']);
        RidePolicies::forget();
        $captain = $this->captain('Auto', '+966550000001', 24.9660, 46.7000);
        $this->assertSame(0, RideQueueEntry::query()->count());

        event(new DriverLocationUpdated($captain, 24.9660, 46.7000, null, null, null));
        $entry = RideQueueEntry::query()->sole();
        $this->assertSame(['waiting', 'auto'], [$entry->status, $entry->entry]);
        $this->asDriver($captain)->getJson('/api/driver/v1/airport-queue')->assertJsonPath('data.queued', true)->assertJsonPath('data.entry', 'auto')->assertJsonPath('data.entry_mode', 'auto');

        // In a manual zone arriving does nothing.
        $this->airport->update(['config' => ['queue_enabled' => true]]);
        app(RidePolicies::class)->save($this->saudi->id, null, $this->airport->id, null, ['queue.entry' => 'manual']);
        RidePolicies::forget();
        $other = $this->captain('Manual', '+966550000002', 24.9660, 46.7000);
        event(new DriverLocationUpdated($other, 24.9660, 46.7000, null, null, null));
        $this->assertSame(1, RideQueueEntry::query()->count());
    }

    public function test_every_way_out_of_the_line(): void
    {
        $queue = app(AirportQueue::class);
        $offline = $this->queued('Offline', '+966550000001');
        $stale = $this->queued('Stale', '+966550000002');
        $strays = $this->queued('Strays', '+966550000003');
        $slow = $this->queued('Slow', '+966550000004');
        $stays = $this->queued('Stays', '+966550000005');

        // Offline and a silent phone go at the next sweep.
        DriverPresence::query()->whereKey($offline->id)->update(['status' => PresenceStatus::Offline->value]);
        DriverPresence::query()->whereKey($stale->id)->update(['last_seen_at' => now()->subMinutes(10)]);
        // Outside the zone: the grace clock starts, and stops when they are back.
        event(new DriverLocationUpdated($strays, 25.5, 47.5, null, null, null));
        $this->assertNotNull($this->entryOf($strays)->outside_since);
        event(new DriverLocationUpdated($strays, 24.9700, 46.7000, null, null, null));
        $this->assertNull($this->entryOf($strays)->outside_since);
        event(new DriverLocationUpdated($strays, 25.5, 47.5, null, null, null));

        $this->assertSame(2, $queue->sweep());
        $this->assertSame('offline', $this->entryOf($offline, 'left')->left_reason);
        $this->assertSame('stale', $this->entryOf($stale, 'left')->left_reason);
        $this->assertNull($this->entryOf($strays, 'waiting')->left_reason);

        // Past the grace the stray is out; a long wait is out when the zone has a limit.
        app(RidePolicies::class)->save($this->saudi->id, null, $this->airport->id, null, ['queue.max_wait_s' => 600]);
        RidePolicies::forget();
        $this->travel(130)->seconds();
        foreach ([$strays, $slow, $stays] as $driver) {
            DriverPresence::query()->whereKey($driver->id)->update(['last_seen_at' => now()]);
        }
        $this->assertSame(1, $queue->sweep());
        $this->assertSame('left_zone', $this->entryOf($strays, 'left')->left_reason);
        $this->travel(500)->seconds();
        foreach ([$slow, $stays] as $driver) {
            DriverPresence::query()->whereKey($driver->id)->update(['last_seen_at' => now()]);
        }
        $this->assertSame(2, $queue->sweep());
        $this->assertSame('timeout', $this->entryOf($slow, 'left')->left_reason);

        // The market's switch going off empties the line.
        $this->travel(-700)->seconds();
        $again = $this->queued('Again', '+966550000006');
        $this->switches(['airport_queue'], false);
        $this->assertSame(1, $queue->sweep());
        $this->assertSame('removed', $this->entryOf($again, 'left')->left_reason);
    }

    public function test_the_wait_estimate_learns_from_finished_waits(): void
    {
        $queue = app(AirportQueue::class);
        $first = $this->queued('First', '+966550000001');
        $second = $this->queued('Second', '+966550000002');
        $entry = $this->entryOf($second);

        // Fewer finished waits than queue.min_samples: no estimate (D-279 "if available").
        $this->assertNull($queue->estimateWaitS($entry));

        // Five captains who waited 600 s from place 2: 300 s a place → second in line, 600 s.
        foreach (range(1, 5) as $i) {
            RideQueueEntry::query()->create([
                'zone_id' => $this->airport->id, 'driver_id' => $first->id, 'status' => 'left', 'entry' => 'manual', 'position_at_join' => 2,
                'joined_at' => now()->subHours($i), 'left_at' => now()->subHours($i)->addSeconds(600), 'left_reason' => 'got_trip', 'wait_s' => 600,
            ]);
        }
        $this->assertSame(600, $queue->estimateWaitS($entry));
        $this->asDriver($second)->getJson('/api/driver/v1/airport-queue')->assertJsonPath('data.position', 2)->assertJsonPath('data.estimated_wait_s', 600);

        // Old waits don't count.
        RideQueueEntry::query()->where('left_reason', 'got_trip')->update(['left_at' => now()->subDays(30)]);
        $this->assertNull($queue->estimateWaitS($entry));
    }

    public function test_an_airport_ride_goes_to_the_first_captain_in_line_and_taking_it_moves_the_line(): void
    {
        $first = $this->queued('First', '+966550000001', 24.9650, 46.7000);
        // Closer to the gate, but behind in line.
        $second = $this->queued('Second', '+966550000002', 24.9701, 46.7001);
        $free = $this->captain('Free', '+966550000003', 24.9700, 46.7000);

        $quote = $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/quotes', ['origin' => ['point_id' => $this->gate3->id] + self::GATE, 'destination' => self::CITY_SPOT])->assertOk()->json('data.0');
        $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides', ['quote_uuid' => $quote['uuid'], 'fingerprint' => $quote['fingerprint'], 'payment_method' => 'cash'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        $this->asDriver($second)->getJson('/api/driver/v1/offers')->assertOk()->assertJsonCount(0, 'data');
        $this->asDriver($free)->getJson('/api/driver/v1/offers')->assertOk()->assertJsonCount(0, 'data');
        $offer = $this->asDriver($first)->getJson('/api/driver/v1/offers')->assertOk()->assertJsonCount(1, 'data')->json('data.0');

        // They decline: the next in line gets it before a free captain.
        $this->asDriver($first)->postJson("/api/driver/v1/offers/{$offer['uuid']}/decline")->assertOk();
        $offered = $this->asDriver($second)->getJson('/api/driver/v1/offers')->assertOk()->json('data');
        $this->assertCount(1, $offered, 'the second captain in line is next');
        $this->asDriver($free)->getJson('/api/driver/v1/offers')->assertOk()->assertJsonCount(0, 'data');

        // Taking the ride takes them out of the line; the first (who declined) is still waiting and moves up.
        $this->asDriver($second)->postJson("/api/driver/v1/offers/{$offered[0]['uuid']}/accept")->assertOk();
        $this->assertSame('got_trip', $this->entryOf($second, 'left')->left_reason);
        $this->asDriver($first)->getJson('/api/driver/v1/airport-queue')->assertJsonPath('data.position', 1)->assertJsonPath('data.size', 1);
    }

    public function test_restricted_areas_are_listed_and_entering_one_alerts_once(): void
    {
        $blocked = $this->zone('blocked', ['ar' => 'منطقة عسكرية', 'en' => 'Military area'], [[24.60, 46.50], [24.60, 46.55], [24.65, 46.55], [24.65, 46.50]]);
        $captain = $this->captain('Walker', '+966550000001', 24.7136, 46.6753);

        $list = $this->asDriver($captain)->getJson('/api/driver/v1/restricted-zones')->assertOk();
        $this->assertSame(['Military area'], array_column($list->json('data'), 'name'));
        $this->assertSame('blocked', $list->json('data.0.type'));
        $this->assertCount(4, $list->json('data.0.polygon'));

        $count = fn () => (int) $this->asDriver($captain)->getJson('/api/driver/v1/notifications/unread-count')->assertOk()->json('data.count');
        $this->withoutDefer();
        $before = $count();
        event(new DriverLocationUpdated($captain, 24.62, 46.52, null, null, null));
        event(new DriverLocationUpdated($captain, 24.63, 46.53, null, null, null));
        $this->assertSame($before + 1, $count(), 'one alert, not one per point');
        $this->assertTrue(Cache::has("rides:zone-alert:{$captain->id}:{$blocked->id}"));

        // Outside, or with the switch off, nothing.
        $this->switches(['restricted_zones'], false);
        $this->asDriver($captain)->getJson('/api/driver/v1/restricted-zones')->assertOk()->assertJsonCount(0, 'data');
    }

    private function entryOf(Driver $driver, string $status = 'waiting'): RideQueueEntry
    {
        return RideQueueEntry::query()->where('driver_id', $driver->id)->where('status', $status)->latest('id')->firstOrFail();
    }

    private function queued(string $name, string $phone, float $lat = 24.9660, float $lng = 46.7000): Driver
    {
        $driver = $this->captain($name, $phone, $lat, $lng);
        $this->asDriver($driver)->postJson('/api/driver/v1/airport-queue/join')->assertOk();

        return $driver;
    }

    /** @param list<string> $features */
    private function switches(array $features, bool $enabled = true): void
    {
        foreach ($features as $feature) {
            RideFeatureRule::query()->updateOrCreate(['feature' => $feature, 'country_id' => $this->saudi->id], ['enabled' => $enabled]);
        }
        RideFeatures::forget();
        RidePolicies::forget();
        CityResolver::forget();
    }

    /**
     * @param  array<string, string>  $names
     * @param  list<list<float>>  $polygon
     * @param  array<string, mixed>|null  $config
     */
    private function zone(string $type, array $names, array $polygon, ?array $config = null): RideZone
    {
        $zone = new RideZone(['country_id' => $this->saudi->id, 'type' => $type, 'polygon' => $polygon, 'config' => $config, 'status' => true, 'priority' => 1]);
        $zone->fillAllTranslations(['name' => $names])->save();

        return $zone;
    }

    private function captain(string $name, string $phone, float $lat, float $lng): Driver
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
        DriverPresence::query()->create([
            'driver_id' => $driver->id, 'status' => PresenceStatus::Online, 'lat' => $lat, 'lng' => $lng, 'gps_enabled' => true,
            'online_since' => now(), 'last_seen_at' => now(),
        ]);

        return $driver;
    }

    private function asDriver(Driver $driver): static
    {
        return $this->actingAs($driver, 'driver_api');
    }
}
