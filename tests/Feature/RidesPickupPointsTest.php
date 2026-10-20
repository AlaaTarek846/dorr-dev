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
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideFeatureRule;
use Modules\Rides\Models\RidePickupPoint;
use Modules\Rides\Models\RideZone;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\CityResolver;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Batch 4 of docs/rides_tasks.md: approved pickup points for sensitive places (R-16, D-111) and the
 * airport cases (R-17, R-18) — the zone around a place, the points to choose from, a point being a must
 * in some zones, the zone's own waiting / no-show rules, and what the rider and the captain read.
 */
class RidesPickupPointsTest extends TestCase
{
    private const AIRPORT = [[24.95, 46.68], [24.95, 46.75], [25.00, 46.75], [25.00, 46.68]];

    /** Inside the airport zone, nowhere near a point. */
    private const AIRPORT_SPOT = ['lat' => 24.9800, 'lng' => 46.7200, 'address' => 'Airport road'];

    private const CITY_SPOT = ['lat' => 24.7136, 'lng' => 46.6753, 'address' => 'Olaya'];

    private Country $saudi;

    private User $rider;

    private int $economyId;

    private RideZone $airport;

    private RidePickupPoint $gate3;

    private RidePickupPoint $departures;

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
        $this->switches(['rides', 'category.economy', 'cash', 'pickup_points']);
        $this->price([]);

        $this->airport = $this->zone('airport', ['ar' => 'مطار الملك خالد', 'en' => 'King Khalid Airport'], self::AIRPORT);
        $this->gate3 = $this->point($this->airport, 24.9700, 46.7000, 'gate', ['ar' => 'بوابة 3', 'en' => 'Gate 3'], ['ar' => 'عند عمود الأرقام', 'en' => 'By the number pillar']);
        $this->departures = $this->point($this->airport, 24.9650, 46.7050, 'terminal', ['ar' => 'صالة المغادرة', 'en' => 'Departures'], [], pickup: false);

        $this->rider = User::query()->create(['name' => 'Sara Ahmed', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    public function test_the_rider_sees_the_approved_points_around_a_place_and_nothing_elsewhere(): void
    {
        $here = $this->actingAs($this->rider, 'user_api')->getJson('/api/mobile/v1/rides/pickup-points?lat=24.98&lng=46.72')->assertOk()
            ->assertJsonPath('data.zone.name', 'King Khalid Airport')->assertJsonPath('data.zone.type', 'airport')->assertJsonPath('data.required', false);
        // Pickups: only the gate (the departures terminal is drop-off only), with how far it is.
        $this->assertSame(['Gate 3'], array_column($here->json('data.points'), 'name'));
        $this->assertSame('Gate', $here->json('data.points.0.kind_label'));
        $this->assertSame('By the number pillar', $here->json('data.points.0.instructions'));
        $this->assertGreaterThan(0, $here->json('data.points.0.distance_m'));

        $this->actingAs($this->rider, 'user_api')->getJson('/api/mobile/v1/rides/pickup-points?lat=24.98&lng=46.72&purpose=dropoff')->assertOk()
            ->assertJsonPath('data.points.0.name', 'Gate 3')->assertJsonPath('data.points.1.name', 'Departures');

        $this->actingAs($this->rider, 'user_api')->getJson('/api/mobile/v1/rides/pickup-points?lat=24.7136&lng=46.6753')->assertOk()
            ->assertJsonPath('data.zone', null)->assertJsonPath('data.points', []);

        // Arabic names for an Arabic rider.
        $this->actingAs($this->rider, 'user_api')->getJson('/api/mobile/v1/rides/pickup-points?lat=24.98&lng=46.72', ['Accept-Language' => 'ar'])->assertOk()
            ->assertJsonPath('data.points.0.name', 'بوابة 3');
    }

    public function test_a_chosen_point_moves_the_pickup_onto_it_and_both_sides_read_it(): void
    {
        $driver = $this->onlineDriver();

        $quote = $this->quote(['origin' => ['point_id' => $this->gate3->id] + self::AIRPORT_SPOT]);
        $this->assertSame('Gate 3', $quote['pickup_point']['name']);
        $uuid = $this->book($quote)->assertCreated()
            ->assertJsonPath('data.pickup_point.name', 'Gate 3')->assertJsonPath('data.pickup_point.kind', 'gate')
            ->assertJsonPath('data.origin.lat', 24.97)->assertJsonPath('data.origin.address', 'Gate 3')->json('data.uuid');
        $this->assertSame($this->gate3->id, Ride::query()->where('uuid', $uuid)->value('pickup_point')['id']);

        // D-111: the captain's card and ride carry the same point, and it is where they are sent.
        $offer = $this->asDriver($driver)->getJson('/api/driver/v1/offers')->assertOk()
            ->assertJsonPath('data.0.pickup_point.name', 'Gate 3')->assertJsonPath('data.0.pickup_point.instructions', 'By the number pillar')->json('data.0');
        $this->asDriver($driver)->postJson("/api/driver/v1/offers/{$offer['uuid']}/accept")->assertOk()
            ->assertJsonPath('data.pickup_point.name', 'Gate 3')->assertJsonPath('data.navigation.lat', 24.97);

        // Standing by the gate is the gate, no id needed.
        $near = $this->quote(['origin' => ['lat' => 24.97001, 'lng' => 46.70001, 'address' => 'x']]);
        $this->assertSame('Gate 3', $near['pickup_point']['name']);
    }

    public function test_a_pickup_only_zone_makes_an_approved_point_a_must(): void
    {
        $this->airport->update(['type' => 'pickup_only']);
        $this->onlineDriver();

        $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/quotes', ['origin' => self::AIRPORT_SPOT, 'destination' => self::CITY_SPOT])
            ->assertStatus(422)->assertJsonPath('error_code', 'rides_pickup_point_required')
            ->assertJsonPath('data.required', true)->assertJsonPath('data.points.0.name', 'Gate 3');

        // A point that isn't one of this place's, or isn't for pickups, is refused.
        $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/quotes', ['origin' => ['point_id' => $this->departures->id] + self::AIRPORT_SPOT, 'destination' => self::CITY_SPOT])
            ->assertStatus(422)->assertJsonPath('error_code', 'rides_pickup_point_invalid');

        $this->quote(['origin' => ['point_id' => $this->gate3->id] + self::AIRPORT_SPOT]);

        // The same pickup outside a zone, or with the feature off, is untouched.
        $this->switches(['pickup_points'], false);
        $this->quote(['origin' => self::AIRPORT_SPOT]);
    }

    public function test_a_zone_can_require_the_dropoff_point_too_for_airport_trips(): void
    {
        $this->airport->update(['config' => ['dropoff_required' => true]]);
        $this->onlineDriver();

        $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/quotes', ['origin' => self::CITY_SPOT, 'destination' => self::AIRPORT_SPOT])
            ->assertStatus(422)->assertJsonPath('error_code', 'rides_dropoff_point_required')
            ->assertJsonPath('data.points.1.name', 'Departures');

        $quote = $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/quotes', ['origin' => self::CITY_SPOT, 'destination' => ['point_id' => $this->departures->id] + self::AIRPORT_SPOT])
            ->assertOk()->json('data.0');
        $this->assertSame('Departures', $quote['dropoff_point']['name']);
        $this->assertNull($quote['pickup_point']);
        $this->book($quote)->assertCreated()->assertJsonPath('data.dropoff_point.name', 'Departures')->assertJsonPath('data.destination.address', 'Departures');
    }

    public function test_the_airports_own_waiting_rules_apply_to_a_pickup_there(): void
    {
        $this->onlineDriver();
        app(RidePolicies::class)->save($this->saudi->id, null, $this->airport->id, null, ['waiting.free_s' => 600, 'no_show.wait_s' => 900]);
        RidePolicies::forget();

        $inside = $this->book($this->quote(['origin' => ['point_id' => $this->gate3->id] + self::AIRPORT_SPOT]))->assertCreated()
            ->assertJsonPath('data.waiting.free_s', 600)->assertJsonPath('data.waiting.no_show_after_s', 900)->json('data.uuid');
        $this->assertNotEmpty($inside);

        // Anywhere else keeps the city's numbers.
        $this->cancelCurrent($inside);
        $this->book($this->quote(['origin' => self::CITY_SPOT]))->assertCreated()
            ->assertJsonPath('data.waiting.free_s', 180)->assertJsonPath('data.waiting.no_show_after_s', 300);
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

    /** @param array<string, mixed> $values */
    private function price(array $values): void
    {
        app(RidePolicies::class)->save($this->saudi->id, null, null, $this->economyId, [
            'price.base_minor' => 600, 'price.per_km_minor' => 180, 'price.per_min_minor' => 30, 'price.min_fare_minor' => 1500,
        ] + $values);
        RidePolicies::forget();
    }

    /**
     * @param  array<string, string>  $names
     * @param  list<list<float>>  $polygon
     */
    private function zone(string $type, array $names, array $polygon): RideZone
    {
        $zone = new RideZone(['country_id' => $this->saudi->id, 'type' => $type, 'polygon' => $polygon, 'status' => true, 'priority' => 1]);
        $zone->fillAllTranslations(['name' => $names])->save();

        return $zone;
    }

    /**
     * @param  array<string, string>  $names
     * @param  array<string, string>  $instructions
     */
    private function point(RideZone $zone, float $lat, float $lng, string $kind, array $names, array $instructions, bool $pickup = true): RidePickupPoint
    {
        $point = new RidePickupPoint(['zone_id' => $zone->id, 'lat' => $lat, 'lng' => $lng, 'kind' => $kind, 'for_pickup' => $pickup, 'for_dropoff' => true, 'status' => true]);
        $point->fillAllTranslations(['name' => $names, 'instructions' => $instructions])->save();

        return $point;
    }

    /**
     * @param  array<string, mixed>  $trip  merged over a trip from the airport spot to the city
     * @return array<string, mixed>
     */
    private function quote(array $trip = []): array
    {
        return $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/quotes', $trip + ['origin' => self::AIRPORT_SPOT, 'destination' => self::CITY_SPOT])->assertOk()->json('data.0');
    }

    /** @param  array<string, mixed>  $quote */
    private function book(array $quote): TestResponse
    {
        return $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides', ['quote_uuid' => $quote['uuid'], 'fingerprint' => $quote['fingerprint'], 'payment_method' => 'cash'], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function cancelCurrent(string $uuid): void
    {
        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/$uuid/cancel", [], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
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
            'driver_id' => $driver->id, 'status' => PresenceStatus::Online, 'lat' => 24.9750, 'lng' => 46.7100, 'gps_enabled' => true,
            'online_since' => now(), 'last_seen_at' => now(),
        ]);

        return $driver;
    }

    private function asDriver(Driver $driver): static
    {
        return $this->actingAs($driver, 'driver_api');
    }
}
