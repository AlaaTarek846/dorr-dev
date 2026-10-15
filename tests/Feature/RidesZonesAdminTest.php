<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RidePickupPoint;
use Modules\Rides\Models\RideZone;
use Modules\Rides\Services\Policy\RidePolicies;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Admin → Rides → Zones & pickup points (R-16..R-18): a zone with its boundary, type and rules, the
 * approved points inside it in both languages, a zone's own prices & rules, and the permission.
 */
class RidesZonesAdminTest extends TestCase
{
    private Country $saudi;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id,
            'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2])->id,
        ]);
        RideCategory::query()->create(['slug' => 'economy', 'pricing_strategy' => 'distance', 'status' => true, 'sort_order' => 1]);
        RidePolicies::forget();
    }

    public function test_an_airport_zone_gets_its_points_and_its_own_waiting_rules(): void
    {
        $this->asAdmin(['ride-zones.view', 'ride-zones.create', 'ride-zones.update', 'ride-zones.delete', 'ride-policies.view', 'ride-policies.update']);
        $this->getJson('/api/admin/v1/ride-zones/options')->assertOk()->assertJsonPath('data.types.2', 'airport')->assertJsonFragment(['gate']);

        $zone = $this->postJson('/api/admin/v1/ride-zones', [
            'country_id' => $this->saudi->id, 'type' => 'airport', 'name' => ['ar' => 'مطار الملك خالد', 'en' => 'King Khalid Airport'],
            'polygon' => [[24.95, 46.68], [24.95, 46.75], [25.00, 46.75], [25.00, 46.68]],
            'config' => ['pickup_required' => true, 'dropoff_required' => false], 'priority' => 2, 'status' => true,
        ])->assertCreated()->assertJsonPath('data.config.pickup_required', true)->assertJsonPath('data.names.ar', 'مطار الملك خالد')->json('data.id');
        $this->assertEqualsWithDelta(24.95, RideZone::query()->findOrFail($zone)->min_lat, 0.0001);

        // A boundary needs at least three corners.
        $this->postJson('/api/admin/v1/ride-zones', [
            'country_id' => $this->saudi->id, 'type' => 'airport', 'name' => ['ar' => 'س', 'en' => 's'], 'polygon' => [[24.95, 46.68], [24.95, 46.75]], 'status' => true,
        ])->assertUnprocessable();

        $point = $this->postJson("/api/admin/v1/ride-zones/{$zone}/points", [
            'lat' => 24.97, 'lng' => 46.70, 'kind' => 'gate', 'name' => ['ar' => 'بوابة 3', 'en' => 'Gate 3'],
            'instructions' => ['ar' => 'عند العمود', 'en' => 'By the pillar'], 'for_pickup' => true, 'for_dropoff' => true, 'status' => true,
        ])->assertCreated()->assertJsonPath('data.names.en', 'Gate 3')->assertJsonPath('data.instructions.ar', 'عند العمود')->json('data.id');

        // Clearing an instruction in the form clears it.
        $this->putJson("/api/admin/v1/ride-zones/{$zone}/points/{$point}", [
            'lat' => 24.97, 'lng' => 46.70, 'kind' => 'parking', 'name' => ['ar' => 'موقف 3', 'en' => 'Parking 3'],
            'instructions' => ['ar' => '', 'en' => 'By the pillar'], 'for_pickup' => true, 'for_dropoff' => false, 'status' => true,
        ])->assertOk()->assertJsonPath('data.kind', 'parking')->assertJsonPath('data.for_dropoff', false)->assertJsonMissingPath('data.instructions.ar');

        $this->getJson('/api/admin/v1/ride-zones')->assertOk()->assertJsonPath('data.0.points.0.name', 'Parking 3');
        $this->getJson('/api/admin/v1/ride-zones?type=blocked')->assertOk()->assertJsonCount(0, 'data');

        // The zone's own waiting rule: saved under its scope, inherited nowhere else.
        $saved = $this->putJson('/api/admin/v1/ride-policies', ['country_id' => $this->saudi->id, 'zone_id' => $zone, 'data' => ['waiting.free_s' => 600]])->assertOk();
        $this->assertSame(['waiting.free_s' => 600], $saved->json('data.own'));
        $this->assertSame(600, $saved->json('data.effective')['waiting.free_s']);
        $country = $this->getJson('/api/admin/v1/ride-policies?country_id='.$this->saudi->id)->assertOk();
        $this->assertSame([], $country->json('data.own'));
        $this->assertSame(180, $country->json('data.effective')['waiting.free_s']);
        $this->getJson('/api/admin/v1/ride-policies/options')->assertOk()->assertJsonPath('data.zones.0.name', 'King Khalid Airport');
        $this->getJson('/api/admin/v1/ride-policies/scopes')->assertOk()->assertJsonPath('data.0.zone_id', $zone);

        $this->deleteJson("/api/admin/v1/ride-zones/{$zone}/points/{$point}")->assertOk();
        $this->assertSame(0, RidePickupPoint::query()->count());
        $this->deleteJson("/api/admin/v1/ride-zones/{$zone}")->assertOk();
        $this->getJson('/api/admin/v1/ride-zones')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_zones_screen_needs_its_permission(): void
    {
        $this->asAdmin([]);
        $this->getJson('/api/admin/v1/ride-zones')->assertForbidden();
        $this->postJson('/api/admin/v1/ride-zones', [])->assertForbidden();

        $this->asAdmin(['ride-zones.view'], 'b@example.com');
        $this->getJson('/api/admin/v1/ride-zones')->assertOk();
        $this->postJson('/api/admin/v1/ride-zones', [])->assertForbidden();
    }

    /** @param list<string> $permissions */
    private function asAdmin(array $permissions, string $email = 'a@example.com'): void
    {
        $admin = Admin::create(['name' => 'A', 'email' => $email, 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo($permissions);
        Sanctum::actingAs($admin, [], 'admin_api');
    }
}
