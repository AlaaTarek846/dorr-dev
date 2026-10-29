<?php

namespace Tests\Feature;

use App\Enums\Gender;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
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
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideFeatureRule;
use Modules\Rides\Models\RideOffer;
use Modules\Rides\Services\Drivers\DriverEligibility;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\CityResolver;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Services\WalletService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * O-89 (owner): the services a captain works in — per country and kind of vehicle from the dashboard,
 * switched on / off by the captain, and obeyed wherever work is handed out.
 */
class RidesDriverServicesTest extends TestCase
{
    private const PICKUP = ['lat' => 24.7136, 'lng' => 46.6753, 'address' => 'Olaya'];

    private const DROPOFF = ['lat' => 24.7500, 'lng' => 46.7000, 'address' => 'King Fahd Rd'];

    private Country $saudi;

    private User $rider;

    private int $plates = 0;

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
        $economy = RideCategory::query()->create(['slug' => 'economy', 'pricing_strategy' => 'distance', 'status' => true, 'sort_order' => 1, 'capacity_max' => 4, 'flags' => ['cash_allowed' => true]]);
        $moto = RideCategory::query()->create(['slug' => 'motorcycle', 'pricing_strategy' => 'distance', 'status' => true, 'sort_order' => 2, 'capacity_max' => 1, 'flags' => ['cash_allowed' => true]]);
        foreach (['rides', 'category.economy', 'category.motorcycle', 'wallet', 'cash'] as $feature) {
            RideFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        RideFeatures::forget();
        CityResolver::forget();
        foreach ([$economy->id, $moto->id] as $category) {
            app(RidePolicies::class)->save($this->saudi->id, null, null, $category, [
                'price.base_minor' => 600, 'price.per_km_minor' => 180, 'price.per_min_minor' => 30, 'price.min_fare_minor' => 1500,
                'hourly.hour_price_minor' => 3000,
            ]);
        }
        RidePolicies::forget();

        $this->rider = User::query()->create(['name' => 'Sara Ahmed', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now(), 'gender' => Gender::Female]);
    }

    public function test_the_dashboard_decides_per_country_and_vehicle_and_the_captain_switches_on_what_they_want(): void
    {
        $car = $this->onlineDriver('sedan');
        $bus = $this->onlineDriver('bus');

        // Until the country is set: the defaults — a bus runs bus trips only.
        $this->assertSame(['rides', 'hourly', 'scheduled', 'airport', 'hire', 'lessons'], array_column($this->asDriver($car)->getJson('/api/driver/v1/work-services')->assertOk()->json('data.services'), 'key'));
        $this->asDriver($bus)->getJson('/api/driver/v1/work-services')->assertOk()->assertJsonPath('data.kind', 'bus')->assertJsonPath('data.services.0.key', 'bus');
        $this->bookEconomy()->assertCreated();
        $this->assertSame([$car->id], RideOffer::query()->pluck('driver_id')->all());

        // The dashboard opens passenger rides to buses here (as in Egypt) — and parcels to motorcycles, coming soon.
        $this->asAdmin(['driver-services.view', 'driver-services.update']);
        $matrix = $this->getJson('/api/admin/v1/driver-service-rules?country_id='.$this->saudi->id)->assertOk()->assertJsonPath('data.saved', false)->json('data.matrix');
        $this->assertTrue($matrix['bus']['bus']);
        $this->assertFalse($matrix['rides']['bus']);
        $matrix['rides']['bus'] = true;
        $matrix['parcels']['motorcycle'] = true;
        $this->putJson('/api/admin/v1/driver-service-rules', ['country_id' => $this->saudi->id, 'matrix' => $matrix])->assertOk()
            ->assertJsonPath('data.saved', true)->assertJsonPath('data.matrix.rides.bus', true);
        $this->assertContains('rides', array_column($this->asDriver($bus)->getJson('/api/driver/v1/work-services')->json('data.services'), 'key'));
        $this->assertNull(app(DriverEligibility::class)->categoryProblem($bus, $bus->vehicles()->first(), RideCategory::query()->where('slug', 'economy')->first()));

        // The captain switches passenger rides off: no rides for them, and why.
        $this->asDriver($car)->putJson('/api/driver/v1/work-services/rides', ['on' => false])->assertOk()
            ->assertJsonPath('data.services.0.key', 'rides')->assertJsonPath('data.services.0.on', false);
        $this->assertSame('service_switched_off', app(DriverEligibility::class)->categoryProblem($car->refresh(), $car->vehicles()->first(), RideCategory::query()->where('slug', 'economy')->first()));
        // A service not open to the vehicle, or coming soon, can't be switched on.
        $this->asDriver($car)->putJson('/api/driver/v1/work-services/bus', ['on' => true])->assertStatus(403)->assertJsonPath('error_code', 'rides_service_not_for_vehicle');
        $moto = $this->onlineDriver('motorcycle');
        $parcels = collect($this->asDriver($moto)->getJson('/api/driver/v1/work-services')->json('data.services'))->firstWhere('key', 'parcels');
        $this->assertTrue($parcels['coming_soon']);
        $this->assertFalse($parcels['on']);
        $this->asDriver($moto)->putJson('/api/driver/v1/work-services/parcels', ['on' => true])->assertStatus(422)->assertJsonPath('error_code', 'rides_service_coming_soon');

        // A motorcycle captain doesn't teach here, nor take «drive my car» jobs.
        $services = app(\Modules\Rides\Services\Drivers\DriverServices::class);
        $this->assertSame('service_not_for_vehicle', $services->problem($moto, 'lessons'));
        $this->asDriver($moto)->getJson('/api/driver/v1/hire/open')->assertStatus(403)->assertJsonPath('error_code', 'rides_service_not_for_vehicle');
    }

    public function test_the_motorcycle_is_by_distance_or_by_the_hour_per_country(): void
    {
        // The category itself is by distance: the motorcycle quotes by distance.
        $quote = collect($this->quote()->assertOk()->json('data'))->firstWhere('category.slug', 'motorcycle');
        $this->assertNull($quote['hourly']);

        // Here (Saudi Arabia) the rules make it by the hour only — no destination needed, and the captain's service is «hourly hire».
        app(RidePolicies::class)->save($this->saudi->id, null, null, RideCategory::query()->where('slug', 'motorcycle')->value('id'), [
            'price.base_minor' => 600, 'price.per_km_minor' => 180, 'price.min_fare_minor' => 1500, 'hourly.hour_price_minor' => 3000, 'hourly.mode' => 'hourly',
        ]);
        RidePolicies::forget();
        $open = $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/quotes', ['origin' => self::PICKUP])->assertOk()->json('data');
        $this->assertSame(['motorcycle'], array_column(array_column($open, 'category'), 'slug'));
        $this->assertTrue($open[0]['hourly_offer']['only']);
        $moto = $this->onlineDriver('motorcycle');
        $this->asDriver($moto)->putJson('/api/driver/v1/work-services/hourly', ['on' => false])->assertOk();
        $this->assertSame('service_switched_off', app(DriverEligibility::class)->categoryProblem($moto, $moto->vehicles()->first(), RideCategory::query()->where('slug', 'motorcycle')->first()));
    }

    private function bookEconomy(): TestResponse
    {
        $this->fund($this->rider, 50000);
        $quote = collect($this->quote()->json('data'))->firstWhere('category.slug', 'economy');

        return $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides', ['quote_uuid' => $quote['uuid'], 'fingerprint' => $quote['fingerprint'], 'payment_method' => 'wallet'], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function quote(): TestResponse
    {
        return $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/quotes', ['origin' => self::PICKUP, 'destination' => self::DROPOFF]);
    }

    private function onlineDriver(string $body): Driver
    {
        $n = ++$this->plates;
        $driver = Driver::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => "Captain $n", 'phone' => '+96655000000'.$n, 'phone_verified_at' => now(),
            'country_id' => $this->saudi->id, 'status' => DriverStatus::Active, 'gender' => Gender::Male,
        ]);
        $make = VehicleMake::query()->create(['status' => true]);
        $model = VehicleModel::query()->create(['make_id' => $make->id, 'body_type' => $body, 'seats' => 4, 'status' => true]);
        Vehicle::query()->create([
            'uuid' => (string) Str::uuid(), 'driver_id' => $driver->id, 'country_id' => $this->saudi->id, 'make_id' => $make->id, 'model_id' => $model->id,
            'color_id' => VehicleColor::query()->create(['hex' => '#FFFFFF', 'status' => true])->id,
            'year' => 2022, 'body_type' => $body, 'seats' => 4, 'plate_letters' => 'ا ب ج', 'plate_number' => (string) (1000 + $n), 'plate_type' => 1,
            'status' => VehicleStatus::Approved, 'is_active' => true,
        ]);
        DriverPresence::query()->create([
            'driver_id' => $driver->id, 'status' => PresenceStatus::Online, 'lat' => 24.7150, 'lng' => 46.6760, 'gps_enabled' => true,
            'online_since' => now(), 'last_seen_at' => now(),
        ]);

        return $driver;
    }

    private function asDriver(Driver $driver): static
    {
        return $this->actingAs($driver, 'driver_api');
    }

    /** @param list<string> $permissions */
    private function asAdmin(array $permissions): void
    {
        $admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo($permissions);
        Sanctum::actingAs($admin, [], 'admin_api');
    }

    private function fund(User $user, int $minor): void
    {
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->firstOrCreateWallet($user, $this->saudi), $minor, WalletBucket::Withdrawable, WalletTransactionType::Topup, ['idempotency_key' => 'fund:'.$user->id]);
    }
}
