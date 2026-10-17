<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\ServiceCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Discover\Models\DiscoverCity;
use Modules\Driver\Database\Seeders\DriverDatabaseSeeder;
use Modules\Driver\Models\DriverDocumentRequirement;
use Modules\Driver\Models\VehicleMake;
use Modules\Driver\Models\VehicleModel;
use Modules\Rides\Database\Seeders\RidesDatabaseSeeder;
use Modules\Rides\Enums\PricingMode;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideFeatureRule;
use Modules\Rides\Models\RidePolicy;
use Modules\Rides\Models\RideRegulatorCity;
use Modules\Rides\Models\RideSetting;
use Modules\Rides\Models\RideTaxRule;
use Modules\Rides\Models\RideZone;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\CityResolver;
use Modules\Rides\Services\Geo\GeoProvider;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\Rides\Services\Regulator\RegulatorCityNames;
use Modules\Rides\Support\Geo;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 0 of rides (docs/rides-plan.md §19): feature switches per country / city (no rule = off),
 * policies merged from the broadest scope to the most specific, the city of a point, and the
 * Google route call.
 */
class RidesFoundationTest extends TestCase
{
    private Country $saudi;

    private DiscoverCity $riyadh;

    private DiscoverCity $jeddah;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id,
            'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'ر.س', 'decimal_places' => 2])->id,
        ]);
        $this->riyadh = DiscoverCity::query()->create(['country_id' => $this->saudi->id, 'timezone' => 'Asia/Riyadh', 'lat' => 24.7136, 'lng' => 46.6753, 'status' => true, 'sort_order' => 1]);
        $this->jeddah = DiscoverCity::query()->create(['country_id' => $this->saudi->id, 'timezone' => 'Asia/Riyadh', 'lat' => 21.4858, 'lng' => 39.1925, 'status' => true, 'sort_order' => 2]);
        RideCategory::query()->create(['slug' => 'economy', 'pricing_strategy' => 'distance', 'status' => true, 'sort_order' => 1]);
        RideCategory::query()->create(['slug' => 'vip', 'pricing_strategy' => 'distance', 'status' => true, 'sort_order' => 2]);
        RideFeatures::forget();
        RidePolicies::forget();
        CityResolver::forget();
    }

    private function rule(string $feature, bool $enabled, ?int $cityId = null): void
    {
        RideFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'city_id' => $cityId, 'enabled' => $enabled]);
    }

    public function test_no_rule_means_off_and_nothing_works_without_the_service_itself(): void
    {
        $features = app(RideFeatures::class);
        $this->assertFalse($features->allows($this->saudi->id, null, 'rides'));

        $this->rule('category.economy', true);
        $this->assertFalse($features->allows($this->saudi->id, null, 'category.economy'), 'a category is off while the service is off');

        $this->rule('rides', true);
        $this->assertTrue($features->allows($this->saudi->id, null, 'category.economy'));
        $this->assertFalse($features->allows($this->saudi->id, null, 'category.vip'));
    }

    public function test_a_city_rule_beats_the_country_rule(): void
    {
        $this->rule('rides', true);
        $this->rule('bidding', true);
        $this->rule('bidding', false, $this->jeddah->id);

        $features = app(RideFeatures::class);
        $this->assertTrue($features->allows($this->saudi->id, $this->riyadh->id, 'bidding'));
        $this->assertFalse($features->allows($this->saudi->id, $this->jeddah->id, 'bidding'));
    }

    public function test_the_features_endpoint_answers_for_the_city_the_rider_is_in(): void
    {
        $this->rule('rides', true);
        $this->rule('category.economy', true);
        $this->rule('multi_stop', true);
        $this->rule('multi_stop', false, $this->jeddah->id);
        $rider = User::query()->create(['phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);

        $this->actingAs($rider, 'user_api')->getJson('/api/mobile/v1/rides/features?lat=21.49&lng=39.19')
            ->assertOk()
            ->assertJsonPath('data.city_id', $this->jeddah->id)
            ->assertJsonPath('data.service', true)
            ->assertJsonPath('data.categories.economy', true)
            ->assertJsonPath('data.categories.vip', false)
            ->assertJsonPath('data.features.multi_stop', false);

        $this->actingAs($rider, 'user_api')->getJson('/api/mobile/v1/rides/features?lat=24.71&lng=46.67')
            ->assertOk()
            ->assertJsonPath('data.city_id', $this->riyadh->id)
            ->assertJsonPath('data.features.multi_stop', true);
    }

    public function test_the_features_endpoint_says_why_the_service_is_off(): void
    {
        $rider = User::query()->create(['phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);

        // Nobody switched `rides` on in the rider's country.
        $this->actingAs($rider, 'user_api')->getJson('/api/mobile/v1/rides/features')
            ->assertOk()
            ->assertJsonPath('data.service', false)
            ->assertJsonPath('data.reason', 'not_enabled');

        // No country on the account and no usable point: nothing to look the switches up by.
        $stranger = User::query()->create(['phone' => '+966500000003', 'status' => 'active', 'phone_verified_at' => now()]);
        $this->actingAs($stranger, 'user_api')->getJson('/api/mobile/v1/rides/features')
            ->assertOk()
            ->assertJsonPath('data.reason', 'no_country');

        $this->rule('rides', true);
        $this->actingAs($rider, 'user_api')->getJson('/api/mobile/v1/rides/features')
            ->assertOk()
            ->assertJsonPath('data.service', true)
            ->assertJsonPath('data.reason', null);
    }

    public function test_the_enable_country_command_switches_the_phase_one_features(): void
    {
        $this->artisan('rides:enable-country', ['code' => 'sa'])->assertSuccessful();

        $features = app(RideFeatures::class);
        $this->assertTrue($features->allows($this->saudi->id, null, 'rides'));
        $this->assertTrue($features->allows($this->saudi->id, null, 'category.economy'));
        $this->assertTrue($features->allows($this->saudi->id, null, 'cash'));
        $this->assertFalse($features->allows($this->saudi->id, null, 'bidding'));

        $this->artisan('rides:enable-country', ['code' => 'SA', '--off' => true])->assertSuccessful();
        RideFeatures::forget();
        $this->assertFalse(app(RideFeatures::class)->allows($this->saudi->id, null, 'rides'));

        $this->artisan('rides:enable-country', ['code' => 'ZZ'])->assertFailed();
    }

    public function test_a_service_area_polygon_decides_the_city_before_the_nearest_centre(): void
    {
        // A square around a point far from both centres, assigned to Jeddah.
        RideZone::query()->create([
            'country_id' => $this->saudi->id, 'city_id' => $this->jeddah->id, 'type' => 'service_area', 'status' => true,
            'polygon' => [[23.0, 44.0], [23.0, 45.0], [24.0, 45.0], [24.0, 44.0]],
        ]);

        $cities = app(CityResolver::class);
        $this->assertSame($this->jeddah->id, $cities->cityAt(23.5, 44.5));
        $this->assertSame($this->riyadh->id, $cities->cityAt(24.70, 46.70));
        $this->assertNull($cities->cityAt(28.0, 36.0), 'far from every city and every area');
    }

    public function test_policies_merge_from_the_country_down_to_the_city_and_category(): void
    {
        $economy = RideCategory::query()->where('slug', 'economy')->firstOrFail();
        $policies = app(RidePolicies::class);

        $policies->save($this->saudi->id, null, null, null, ['waiting.free_s' => 240]);
        $policies->save($this->saudi->id, null, null, $economy->id, ['price.base_minor' => 600, 'price.per_km_minor' => 180]);
        $policies->save($this->saudi->id, $this->jeddah->id, null, $economy->id, ['price.base_minor' => 700, 'pricing_mode' => 'metered']);

        $riyadh = $policies->for($this->saudi->id, $this->riyadh->id, null, $economy->id);
        $this->assertSame(600, $riyadh->int('price.base_minor'));
        $this->assertSame(240, $riyadh->int('waiting.free_s'));
        $this->assertSame(PricingMode::Upfront, $riyadh->pricingMode());
        $this->assertSame(100, $riyadh->int('arrival.radius_m'), 'untouched keys keep their default (Jawad: 100 m)');

        $jeddah = $policies->for($this->saudi->id, $this->jeddah->id, null, $economy->id);
        $this->assertSame(700, $jeddah->int('price.base_minor'));
        $this->assertSame(180, $jeddah->int('price.per_km_minor'));
        $this->assertSame(PricingMode::Metered, $jeddah->pricingMode());

        // Saving the same scope again replaces it instead of adding a second row, and is audited.
        $policies->save($this->saudi->id, null, null, null, ['waiting.free_s' => 300]);
        $this->assertSame(3, RidePolicy::query()->count());
        $this->assertSame(300, $policies->for($this->saudi->id)->int('waiting.free_s'));
        $this->assertSame(4, AuditLog::query()->where('action', 'ride_policy.saved')->count());
    }

    public function test_unknown_keys_and_wrong_values_are_refused(): void
    {
        $policies = app(RidePolicies::class);

        foreach ([['price.base' => 5], ['pricing_mode' => 'free'], ['price.per_km_minor' => -1], ['pin.required' => 'yes'], ['bids.counter_steps_minor' => [5, 'x']]] as $bad) {
            try {
                $policies->save($this->saudi->id, null, null, null, $bad);
                $this->fail('accepted '.json_encode($bad));
            } catch (RidesException $e) {
                $this->assertSame('invalid_policy', $e->errorCode);
            }
        }

        // A driver's minimum balance is a debt limit, so it may be negative.
        $policies->save($this->saudi->id, null, null, null, ['driver.min_balance_minor' => -7500]);
        $this->assertSame(-7500, $policies->for($this->saudi->id)->int('driver.min_balance_minor'));
    }

    public function test_the_seeders_set_up_saudi_rides_and_can_run_twice(): void
    {
        ServiceCategory::query()->create(['module_name' => 'passenger_ride', 'requires_provider' => true, 'status' => true]);
        $this->riyadh->translations()->create(['locale' => 'ar', 'name' => 'الرياض']);
        RideCategory::query()->forceDelete();

        foreach ([1, 2] as $run) {
            $this->seed(DriverDatabaseSeeder::class);
            $this->seed(RidesDatabaseSeeder::class);
        }
        RideFeatures::forget();
        RidePolicies::forget();

        $this->assertSame(11, RideCategory::query()->count());
        $this->assertSame(['economy'], RideCategory::query()->where('status', true)->pluck('slug')->all(), 'phase 1 is Economy only');

        $vat = RideTaxRule::query()->sole();
        $this->assertSame(['commission', 1500, 'driver'], [$vat->base->value, $vat->rate_bp, $vat->borne_by->value]);

        $wasl = RideRegulatorCity::query()->sole();
        $this->assertSame('الرياض', $wasl->official_name);
        $this->assertSame($this->riyadh->id, $wasl->city_id);

        $economy = RideCategory::query()->where('slug', 'economy')->firstOrFail();
        $policy = app(RidePolicies::class)->for($this->saudi->id, $this->riyadh->id, null, $economy->id);
        $this->assertSame(PricingMode::Upfront, $policy->pricingMode());
        $this->assertSame(1500, $policy->int('price.min_fare_minor'));
        $this->assertTrue(app(RideFeatures::class)->allows($this->saudi->id, $this->riyadh->id, 'category.economy'));
        $this->assertFalse(app(RideFeatures::class)->allows($this->saudi->id, $this->riyadh->id, 'bidding'), 'bidding is phase 2');

        $this->assertSame(4, DriverDocumentRequirement::query()->count());
        $this->assertGreaterThan(10, VehicleModel::query()->count());
        $this->assertSame(1, VehicleMake::query()->whereHas('translations', fn ($q) => $q->where('name', 'Toyota'))->count());
    }

    public function test_geo_helpers(): void
    {
        $this->assertEqualsWithDelta(1112, Geo::metres(24.0, 46.0, 24.01, 46.0), 5);

        $square = [[0.0, 0.0], [0.0, 1.0], [1.0, 1.0], [1.0, 0.0]];
        $this->assertTrue(Geo::inPolygon(0.5, 0.5, $square));
        $this->assertTrue(Geo::inPolygon(0.0, 0.5, $square), 'on an edge counts as inside');
        $this->assertFalse(Geo::inPolygon(1.5, 0.5, $square));

        [$minLat, $maxLat, $minLng, $maxLng] = Geo::boxAround(24.0, 46.0, 5000);
        $this->assertTrue($minLat < 24.0 && $maxLat > 24.0 && $minLng < 46.0 && $maxLng > 46.0);
        $this->assertEqualsWithDelta(5000, Geo::metres(24.0, 46.0, $maxLat, 46.0), 5);
    }

    public function test_google_routes_returns_distance_duration_polyline_and_legs(): void
    {
        config(['rides.google.key' => 'test-key']);
        Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [[
            'distanceMeters' => 7400,
            'duration' => '1260s',
            'polyline' => ['encodedPolyline' => 'abc'],
            'legs' => [['distanceMeters' => 3000, 'duration' => '500s'], ['distanceMeters' => 4400, 'duration' => '760s']],
        ]]])]);

        $route = app(GeoProvider::class)->route(24.71, 46.67, 24.77, 46.73, [[24.74, 46.70]]);

        $this->assertNotNull($route);
        $this->assertFalse($route->estimated);
        $this->assertSame(7400, $route->distanceM);
        $this->assertSame(1260, $route->durationS);
        $this->assertSame('abc', $route->polyline);
        $this->assertCount(2, $route->legs);

        Http::assertSent(fn ($request) => $request->hasHeader('X-Goog-Api-Key', 'test-key')
            && count($request['intermediates']) === 1
            && $request['travelMode'] === 'DRIVE');
    }

    public function test_the_admin_key_wins_over_the_env_key(): void
    {
        config(['rides.google.key' => 'env-key']);
        RideSetting::current()->update(['google_maps_key' => 'admin-key']);
        Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [['distanceMeters' => 1000, 'duration' => '120s']]])]);

        app(GeoProvider::class)->route(24.71, 46.67, 24.77, 46.73);

        Http::assertSent(fn ($request) => $request->hasHeader('X-Goog-Api-Key', 'admin-key'));
        $this->assertNotSame('admin-key', DB::table('ride_settings')->value('google_maps_key'), 'stored encrypted');
    }

    public function test_without_google_the_route_is_jawads_free_estimate(): void
    {
        // No key at all, then a key Google refuses (e.g. it ran out): both fall back, never null.
        config(['rides.google.key' => null]);
        $noKey = app(GeoProvider::class)->route(24.70, 46.70, 24.80, 46.70, [[24.75, 46.70]]);

        config(['rides.google.key' => 'spent-key']);
        Http::fake(['routes.googleapis.com/*' => Http::response(['error' => ['message' => 'quota']], 429)]);
        $refused = app(GeoProvider::class)->route(24.70, 46.70, 24.80, 46.70, [[24.75, 46.70]]);

        foreach ([$noKey, $refused] as $route) {
            $this->assertTrue($route->estimated);
            $this->assertNull($route->polyline);
            $this->assertCount(2, $route->legs);
            // ~11.1 km in a straight line × 1.3 road factor ≈ 14.5 km, at 60 km/h ≈ 14.5 minutes.
            $this->assertEqualsWithDelta(14455, $route->distanceM, 60);
            $this->assertEqualsWithDelta(867, $route->durationS, 5);
        }

        RideSetting::current()->update(['fallback_speed_kmh' => 30, 'fallback_road_factor_bp' => 10000]);
        $slow = app(GeoProvider::class)->route(24.70, 46.70, 24.80, 46.70);
        $this->assertEqualsWithDelta(11119, $slow->distanceM, 50);
        $this->assertEqualsWithDelta(1334, $slow->durationS, 10);
    }

    public function test_the_city_comes_from_google_first_and_from_our_list_when_google_cannot_tell(): void
    {
        $this->jeddah->translations()->create(['locale' => 'ar', 'name' => 'جدة']);
        config(['rides.google.key' => 'test-key']);

        // Google says Jeddah for a point nearer Riyadh's centre than Jeddah's: Google wins.
        $this->geocodeAnswers('جـدة');
        $this->assertSame($this->jeddah->id, app(CityResolver::class)->cityAt(24.70, 46.68));
        // Cached per ~1 km: the second lookup doesn't call Google.
        app(CityResolver::class)->cityAt(24.701, 46.681);
        Http::assertSentCount(1);

        // Google can't tell (the key ran out): our own list decides — the nearest city.
        $this->geocodeAnswers(null, 'OVER_QUERY_LIMIT');
        $this->assertSame($this->riyadh->id, app(CityResolver::class)->cityAt(24.95, 46.95));
    }

    public function test_the_wasl_name_is_always_the_stored_official_one(): void
    {
        RideRegulatorCity::query()->create(['regulator' => 'wasl', 'country_id' => $this->saudi->id, 'city_id' => $this->riyadh->id, 'official_name' => 'الرياض', 'status' => true]);
        $names = app(RegulatorCityNames::class);
        config(['rides.google.key' => 'test-key']);

        // Google writes it slightly differently: the official spelling is what comes out.
        $this->geocodeAnswers(' الرياض ');
        $this->assertSame('الرياض', $names->officialName('wasl', 24.71, 46.67));

        // Google names a city with no official name: no guess, through our own mapping instead.
        $this->geocodeAnswers('الدرعية');
        $this->assertSame('الرياض', $names->officialName('wasl', 24.74, 46.57));

        // Jeddah has no official name yet: null, the trip will wait in the admin.
        $this->assertNull($names->officialName('wasl', 21.49, 39.19));
    }

    public function test_the_admin_sets_the_key_without_ever_seeing_it_back(): void
    {
        $admin = Admin::query()->create(['name' => 'Admin', 'email' => 'admin@dorr.test', 'password' => 'secret123', 'status' => 'active']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['ride-settings.view', 'ride-settings.update'] as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo(['ride-settings.view', 'ride-settings.update']);
        Sanctum::actingAs($admin, [], 'admin_api');

        $this->putJson('/api/admin/v1/ride-settings', ['google_maps_key' => 'AIzaSy-secret-1234', 'fallback_speed_kmh' => 50])
            ->assertOk()
            ->assertJsonPath('data.google_maps_key_set', true)
            ->assertJsonPath('data.google_maps_key_hint', '••••1234')
            ->assertJsonPath('data.fallback_speed_kmh', 50)
            ->assertJsonMissing(['google_maps_key' => 'AIzaSy-secret-1234']);

        $this->assertSame('AIzaSy-secret-1234', RideSetting::current()->googleKey());
        $this->assertSame(1, AuditLog::query()->where('action', 'ride_settings.updated')->count());
        $this->assertStringNotContainsString('secret', (string) json_encode(AuditLog::query()->first()->after));

        $this->putJson('/api/admin/v1/ride-settings', ['fallback_road_factor_bp' => 5000])->assertStatus(422);
    }

    /** Google Geocoding answers with this city name (or this error status) from now on. */
    private function geocodeAnswers(?string $city, string $status = 'OK'): void
    {
        $this->geocode = ['city' => $city, 'status' => $status];
        if ($this->geocodeFaked) {
            return;
        }
        $this->geocodeFaked = true;
        Http::fake(['maps.googleapis.com/maps/api/geocode/*' => fn () => Http::response([
            'status' => $this->geocode['status'],
            'results' => $this->geocode['city'] === null ? [] : [['address_components' => [['long_name' => $this->geocode['city'], 'types' => ['locality', 'political']]]]],
        ])]);
    }

    /** @var array{city: ?string, status: string} */
    private array $geocode = ['city' => null, 'status' => 'OK'];

    private bool $geocodeFaked = false;
}
