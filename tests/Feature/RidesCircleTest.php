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
use Modules\Rides\Events\DriverChanged;
use Modules\Rides\Events\RideChanged;
use Modules\Rides\Events\RideDriverMoved;
use Modules\Rides\Models\FavoriteDriver;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideEvent;
use Modules\Rides\Models\RideFeatureRule;
use Modules\Rides\Services\Dispatch\DispatchEngine;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\CityResolver;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * DORR Circle (R-INN-07, D-INN-07): a ride offered to the rider's favourite captains only — all or the
 * ones picked — one at a time; the rider asked once when none of them took it, and nobody else asked
 * until the rider says "search all captains".
 */
class RidesCircleTest extends TestCase
{
    private const PICKUP = ['lat' => 24.7136, 'lng' => 46.6753, 'address' => 'Olaya'];

    private const DROPOFF = ['lat' => 24.7500, 'lng' => 46.7000, 'address' => 'King Fahd Rd'];

    private Country $saudi;

    private User $rider;

    private int $plate = 2000;

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
        $economy = RideCategory::query()->create(['slug' => 'economy', 'pricing_strategy' => 'distance', 'status' => true, 'sort_order' => 1, 'capacity_max' => 4, 'flags' => ['cash_allowed' => true]]);
        foreach (['rides', 'category.economy', 'cash', 'favorite_circle'] as $feature) {
            RideFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        app(RidePolicies::class)->save($this->saudi->id, null, null, $economy->id, ['price.base_minor' => 600, 'price.per_km_minor' => 180, 'price.per_min_minor' => 30, 'price.min_fare_minor' => 1500]);
        RideFeatures::forget();
        RidePolicies::forget();
        CityResolver::forget();

        $this->rider = User::query()->create(['name' => 'Sara Ahmed', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    public function test_a_ride_for_the_circle_goes_to_favourites_only_then_the_rider_is_asked_before_anyone_else(): void
    {
        $far = $this->captain('Far', '+966550000001', 24.7600, 46.7200);
        $near = $this->captain('Near', '+966550000002', 24.7200, 46.6800);
        $stranger = $this->captain('Stranger', '+966550000003', 24.7137, 46.6754);
        $this->favourite($far);
        $this->favourite($near);

        // The circle to choose from.
        $drivers = $this->actingAs($this->rider, 'user_api')->getJson('/api/mobile/v1/rides/circle')->assertOk()->json('data.drivers');
        $this->assertEqualsCanonicalizing([$far->id, $near->id], array_column($drivers, 'driver_id'));

        // Booked for the whole circle: the nearest favourite is asked, the stranger — closest of all — is not.
        $uuid = $this->book($this->quote(), ['circle' => ['driver_ids' => []]])->assertCreated()
            ->assertJsonPath('data.circle.active', true)->assertJsonPath('data.circle.count', 2)->assertJsonPath('data.circle.asked', false)->json('data.uuid');
        $this->assertSame('circle', Ride::query()->where('uuid', $uuid)->value('search_scope'));
        $this->asDriver($stranger)->getJson('/api/driver/v1/offers')->assertOk()->assertJsonCount(0, 'data');
        $this->asDriver($far)->getJson('/api/driver/v1/offers')->assertOk()->assertJsonCount(0, 'data');
        $offer = $this->asDriver($near)->getJson('/api/driver/v1/offers')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.from_circle', true)->json('data.0');

        // They decline: the other favourite is next, still not the stranger.
        $this->asDriver($near)->postJson("/api/driver/v1/offers/{$offer['uuid']}/decline")->assertOk();
        $second = $this->asDriver($far)->getJson('/api/driver/v1/offers')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->asDriver($far)->postJson("/api/driver/v1/offers/{$second['uuid']}/decline")->assertOk();
        $this->asDriver($stranger)->getJson('/api/driver/v1/offers')->assertOk()->assertJsonCount(0, 'data');

        // The circle is spent: after circle.ask_after_s the rider is asked — once — and still nobody else is.
        $this->travel(100)->seconds();
        $this->touchPresences();
        app(DispatchEngine::class)->tick();
        app(DispatchEngine::class)->tick();
        $this->actingAs($this->rider, 'user_api')->getJson("/api/mobile/v1/rides/{$uuid}")->assertOk()->assertJsonPath('data.circle.asked', true)->assertJsonPath('data.circle.active', true);
        $this->assertSame(1, RideEvent::query()->where('type', 'circle_asked')->count());
        $this->asDriver($stranger)->getJson('/api/driver/v1/offers')->assertOk()->assertJsonCount(0, 'data');

        // "Search all captains": the usual search takes over and the nearest captain is asked.
        $this->widen($uuid)->assertOk()->assertJsonPath('data.circle.active', false)->assertJsonPath('data.circle.asked', true);
        $this->assertSame('all', Ride::query()->where('uuid', $uuid)->value('search_scope'));
        $this->asDriver($stranger)->getJson('/api/driver/v1/offers')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.from_circle', false);
        // Asking again changes nothing.
        $this->widen($uuid)->assertOk();
        $this->assertSame(1, RideEvent::query()->where('type', 'circle_widened')->count());
    }

    public function test_the_rider_can_pick_which_favourites_and_only_their_own(): void
    {
        $first = $this->captain('First', '+966550000001', 24.7200, 46.6800);
        $second = $this->captain('Second', '+966550000002', 24.7137, 46.6754);
        $other = $this->captain('Other', '+966550000003', 24.7138, 46.6755);
        $this->favourite($first);
        $this->favourite($second);

        // Only the one picked is asked, though the other favourite is closer.
        $this->book($this->quote(), ['circle' => ['driver_ids' => [$first->id]]])->assertCreated()->assertJsonPath('data.circle.count', 1);
        $this->asDriver($second)->getJson('/api/driver/v1/offers')->assertOk()->assertJsonCount(0, 'data');
        $this->asDriver($first)->getJson('/api/driver/v1/offers')->assertOk()->assertJsonCount(1, 'data');
        $this->cancelCurrent();

        // A captain who isn't a favourite can't be picked; nor can a circle without favourites.
        $this->book($this->quote(), ['circle' => ['driver_ids' => [$other->id]]])->assertStatus(422)->assertJsonPath('error_code', 'rides_circle_invalid');
        FavoriteDriver::query()->delete();
        $this->book($this->quote(), ['circle' => ['driver_ids' => []]])->assertStatus(422)->assertJsonPath('error_code', 'rides_circle_empty');
    }

    public function test_the_circle_needs_its_switch_and_widening_needs_a_circle_ride(): void
    {
        $captain = $this->captain('Only', '+966550000001', 24.7200, 46.6800);
        $this->favourite($captain);

        RideFeatureRule::query()->where('feature', 'favorite_circle')->update(['enabled' => false]);
        RideFeatures::forget();
        $this->book($this->quote(), ['circle' => ['driver_ids' => []]])->assertForbidden()
            ->assertJsonPath('error_code', 'rides_feature_disabled')->assertJsonPath('data.feature', 'favorite_circle');

        // A normal ride has no circle to widen, and says so.
        $uuid = $this->book($this->quote())->assertCreated()->assertJsonPath('data.circle', null)->json('data.uuid');
        $this->widen($uuid)->assertStatus(409)->assertJsonPath('error_code', 'rides_circle_not_active');
        $this->asDriver($captain)->getJson('/api/driver/v1/offers')->assertOk()->assertJsonPath('data.0.from_circle', false);
    }

    private function favourite(Driver $driver): void
    {
        FavoriteDriver::query()->create(['user_id' => $this->rider->id, 'driver_id' => $driver->id, 'created_at' => now()]);
    }

    private function touchPresences(): void
    {
        DriverPresence::query()->update(['last_seen_at' => now()]);
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
    private function book(array $quote, array $extra = []): TestResponse
    {
        return $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides', ['quote_uuid' => $quote['uuid'], 'fingerprint' => $quote['fingerprint'], 'payment_method' => 'cash'] + $extra, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function widen(string $uuid): TestResponse
    {
        return $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/{$uuid}/circle/widen", [], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function cancelCurrent(): void
    {
        $uuid = Ride::query()->where('user_id', $this->rider->id)->latest('id')->value('uuid');
        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/{$uuid}/cancel", [], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
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
