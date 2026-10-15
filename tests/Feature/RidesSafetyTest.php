<?php

namespace Tests\Feature;

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
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideEvent;
use Modules\Rides\Models\RideFeatureRule;
use Modules\Rides\Models\RideOffer;
use Modules\Rides\Models\RideSafetyIncident;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\CityResolver;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Safety (R-86, R-110, R-111, R-113, D-141, D-142, D-156): a family link that shows the ride live —
 * the captain's first name, the car and its plate, where it is — and "arrived safely" at the end,
 * never a phone number; and SOS from either side, kept with where it was pressed and handled by
 * the safety team.
 */
class RidesSafetyTest extends TestCase
{
    private const PICKUP = ['lat' => 24.7136, 'lng' => 46.6753, 'address' => 'Olaya'];

    private const DROPOFF = ['lat' => 24.7500, 'lng' => 46.7000, 'address' => 'King Fahd Rd'];

    private Country $saudi;

    private User $rider;

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
        $economy = RideCategory::query()->create(['slug' => 'economy', 'pricing_strategy' => 'distance', 'status' => true, 'sort_order' => 1, 'flags' => ['cash_allowed' => true]]);
        foreach (['rides', 'category.economy', 'cash', 'share'] as $feature) {
            RideFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        RideFeatures::forget();
        RidePolicies::forget();
        CityResolver::forget();
        app(RidePolicies::class)->save($this->saudi->id, null, null, $economy->id, [
            'price.base_minor' => 600, 'price.per_km_minor' => 180, 'price.per_min_minor' => 30, 'price.min_fare_minor' => 1500,
            'safety.emergency_number' => '911',
        ]);
        $this->rider = User::query()->create(['name' => 'Sara Ahmed', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    public function test_the_family_link_follows_the_ride_without_phone_numbers_and_ends_with_arrived_safely(): void
    {
        $driver = $this->driver('Khalid Al Harbi');
        $uuid = $this->bookAndAccept($driver);

        $link = $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/$uuid/share")->assertCreated()->json('data.url');
        $this->assertSame($link, $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/$uuid/share")->json('data.url'), 'one link per ride');
        $token = Str::afterLast($link, '/');

        $page = $this->get("/ride/$token", ['Accept-Language' => 'ar'])->assertOk();
        $page->assertSee('Khalid')->assertDontSee('Al Harbi')->assertDontSee('+966')->assertSee('الكابتن في الطريق');

        $live = $this->getJson("/ride/$token/live")->assertOk()
            ->assertJsonPath('status', 'accepted')->assertJsonPath('driver.name', 'Khalid')->assertJsonPath('shared_by.name', 'Sara')
            ->assertJsonPath('arrived_safely', false)->json();
        $this->assertNotNull($live['location'], 'where the car is, while the ride runs');
        $this->assertStringNotContainsString('+9665', json_encode($live));

        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/arrive", ['lat' => self::PICKUP['lat'], 'lng' => self::PICKUP['lng']])->assertOk();
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/start", ['lat' => self::PICKUP['lat'], 'lng' => self::PICKUP['lng']])->assertOk();
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/end", ['lat' => self::DROPOFF['lat'], 'lng' => self::DROPOFF['lng']])->assertOk();

        $this->getJson("/ride/$token/live")->assertOk()->assertJsonPath('arrived_safely', true)->assertJsonPath('location', null);
        $this->travel(61)->minutes();
        $this->getJson("/ride/$token/live")->assertNotFound();
        $this->get("/ride/$token")->assertNotFound();
    }

    public function test_a_revoked_link_stops_working_and_the_captain_can_share_too_when_switched_on(): void
    {
        $driver = $this->driver();
        $uuid = $this->bookAndAccept($driver);

        $token = Str::afterLast($this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/$uuid/share")->json('data.url'), '/');
        $this->actingAs($this->rider, 'user_api')->deleteJson("/api/mobile/v1/rides/$uuid/share")->assertOk();
        $this->getJson("/ride/$token/live")->assertNotFound();
        $this->assertEmpty($this->actingAs($this->rider, 'user_api')->getJson("/api/mobile/v1/rides/$uuid/share")->assertOk()->json('data'));

        $captainLink = $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/share")->assertCreated()->json('data.url');
        $this->getJson('/ride/'.Str::afterLast($captainLink, '/').'/live')->assertOk()->assertJsonPath('shared_by.type', 'driver');

        // Someone else's ride is not theirs to share.
        $other = User::query()->create(['name' => 'Omar', 'phone' => '+966500000009', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->actingAs($other, 'user_api')->postJson("/api/mobile/v1/rides/$uuid/share")->assertNotFound();

        RideFeatureRule::query()->where('feature', 'share')->update(['enabled' => false]);
        RideFeatures::forget();
        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/$uuid/share")->assertForbidden()->assertJsonPath('error_code', 'rides_feature_disabled');
    }

    public function test_sos_from_either_side_is_kept_with_its_place_and_handled_by_the_safety_team(): void
    {
        $driver = $this->driver();
        $uuid = $this->bookAndAccept($driver);

        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/$uuid/sos", ['lat' => 24.72, 'lng' => 46.68, 'accuracy_m' => 12])
            ->assertCreated()->assertJsonPath('data.emergency_number', '911');
        // Pressing again within a minute is the same alert.
        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/$uuid/sos", ['lat' => 24.721, 'lng' => 46.681])->assertCreated();
        $this->assertSame(1, RideSafetyIncident::query()->where('reporter_type', 'user')->count());
        $this->assertSame(24.721, RideSafetyIncident::query()->where('reporter_type', 'user')->value('lat') + 0.0);
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/sos", ['note' => 'Aggressive passenger'])->assertCreated();
        $this->assertSame(2, RideSafetyIncident::query()->count());
        $this->assertSame(2, RideEvent::query()->where('type', 'sos')->count());

        $this->asAdmin(['ride-safety.view', 'ride-safety.update']);
        $list = $this->getJson('/api/admin/v1/ride-safety?status=open')->assertOk()->assertJsonCount(2, 'data');
        $incident = collect($list->json('data'))->firstWhere('reporter_type', 'user');
        $this->assertStringContainsString('24.721', (string) $incident['map_url']);
        $this->assertSame('+966500000001', $incident['reporter']['phone']);

        $this->postJson("/api/admin/v1/ride-safety/{$incident['id']}/acknowledge")->assertOk()->assertJsonPath('data.status', 'acknowledged');
        $this->postJson("/api/admin/v1/ride-safety/{$incident['id']}/resolve", ['note' => 'Called the rider, all fine'])->assertOk()
            ->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.resolution_note', 'Called the rider, all fine');
        $this->getJson('/api/admin/v1/ride-safety?status=open')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_safety_page_needs_its_permission(): void
    {
        $this->asAdmin([]);
        $this->getJson('/api/admin/v1/ride-safety')->assertForbidden();
    }

    // ---------------------------------------------------------------- helpers

    private function bookAndAccept(Driver $driver): string
    {
        $quote = $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/quotes', ['origin' => self::PICKUP, 'destination' => self::DROPOFF])->json('data.0');
        $uuid = $this->postJson('/api/mobile/v1/rides', ['quote_uuid' => $quote['uuid'], 'fingerprint' => $quote['fingerprint'], 'payment_method' => 'cash'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->json('data.uuid');
        $offer = RideOffer::query()->where('driver_id', $driver->id)->where('status', 'sent')->sole();
        $this->asDriver($driver)->postJson("/api/driver/v1/offers/{$offer->uuid}/accept")->assertOk();
        $this->assertSame('accepted', Ride::query()->where('uuid', $uuid)->value('status')->value);

        return $uuid;
    }

    private function driver(string $name = 'Captain One'): Driver
    {
        $driver = Driver::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => $name, 'phone' => '+966550000001', 'phone_verified_at' => now(),
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
}
