<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use App\Models\Region;
use App\Models\ServiceAvailability;
use App\Models\ServiceCategory;
use App\Services\General\ServiceAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Dashboard → Service control: per country and per region, a service is switched on or off as a whole, for the customer app
 * and for the provider app — each on its own. A region follows its country until it sets a switch itself.
 */
class ServiceAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Country $egypt;

    private Region $cairo;

    private Region $giza;

    private ServiceCategory $ride;

    private ServiceCategory $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $flag = Flag::create(['code' => 'eg']);
        $currency = Currency::create(['code' => 'EGP', 'symbol' => 'EGP']);
        $this->egypt = Country::create([
            'code' => 'EG', 'code_alpha3' => 'EGY', 'dial_code' => '+20', 'phone_starts_with' => '1', 'phone_length' => 10,
            'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $currency->id, 'status' => true,
        ]);
        $this->cairo = Region::create(['country_id' => $this->egypt->id, 'status' => true]);
        $this->giza = Region::create(['country_id' => $this->egypt->id, 'status' => true]);

        // A ride service (customers and providers) and a shop service (customers only).
        $this->ride = ServiceCategory::create(['audiences' => ['user', 'provider'], 'requires_provider' => true, 'status' => true]);
        $this->shop = ServiceCategory::create(['audiences' => ['user'], 'status' => true]);
        $this->egypt->serviceCategories()->attach([$this->ride->id, $this->shop->id]);
    }

    /** @param list<string> $permissions */
    private function admin(array $permissions): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->app['auth']->forgetGuards();

        $admin = Admin::create(['name' => 'Agent '.uniqid(), 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'admin_api');
        }
        $admin->givePermissionTo($permissions);
        Sanctum::actingAs($admin, [], 'admin_api');
    }

    private function set(array $body, ?Region $region = null): TestResponse
    {
        return $this->putJson('/api/admin/v1/service-availability', $body + ['country_id' => $this->egypt->id] + ($region ? ['region_id' => $region->id] : []));
    }

    private function resolve(ServiceCategory $service, ?Region $region = null): array
    {
        return app(ServiceAvailabilityService::class)->resolve($service, $this->egypt->id, $region?->id);
    }

    public function test_with_no_row_a_service_the_country_carries_is_on_for_customers_and_providers(): void
    {
        $ride = $this->resolve($this->ride, $this->cairo);

        $this->assertTrue($ride['service_open']);
        $this->assertSame('enabled', $ride['customer_state']);
        $this->assertTrue($ride['customer_can_order']);
        $this->assertTrue($ride['provider_can_use']);
    }

    public function test_a_service_the_country_does_not_carry_is_off(): void
    {
        $other = ServiceCategory::create(['audiences' => ['user'], 'status' => true]);
        $resolved = $this->resolve($other);

        $this->assertFalse($resolved['service_open']);
        $this->assertFalse($resolved['customer_visible']);
    }

    public function test_the_customer_and_provider_switches_are_independent(): void
    {
        $this->admin(['service-availability.update', 'service-availability.view']);

        // Provider app open, customer app closed ("coming soon"): drivers can sign up before customers can order.
        $this->set(['service_category_id' => $this->ride->id, 'customer_enabled' => false, 'customer_mode' => 'coming_soon'])->assertOk();

        $ride = $this->resolve($this->ride);
        $this->assertSame('coming_soon', $ride['customer_state']);
        $this->assertTrue($ride['customer_visible']);
        $this->assertFalse($ride['customer_can_order']);
        $this->assertTrue($ride['provider_can_use']);

        // And the other way round: the provider app closed, customers unaffected.
        $this->set(['service_category_id' => $this->ride->id, 'customer_enabled' => true, 'provider_enabled' => false])->assertOk();

        $ride = $this->resolve($this->ride);
        $this->assertSame('enabled', $ride['customer_state']);
        $this->assertTrue($ride['customer_can_order']);
        $this->assertFalse($ride['provider_can_use']);
    }

    public function test_a_closed_customer_switch_can_hide_the_service_or_show_it_without_ordering(): void
    {
        $this->admin(['service-availability.update']);

        $this->set(['service_category_id' => $this->shop->id, 'customer_enabled' => false, 'customer_mode' => 'hidden'])->assertOk();
        $this->assertFalse($this->resolve($this->shop)['customer_visible']);

        $this->set(['service_category_id' => $this->shop->id, 'customer_mode' => 'view_only'])->assertOk();
        $shop = $this->resolve($this->shop);
        $this->assertTrue($shop['customer_visible']);
        $this->assertFalse($shop['customer_can_order']);
        $this->assertSame('view_only', $shop['customer_state']);
    }

    public function test_closing_the_service_in_a_region_closes_it_for_customers_and_providers_there_only(): void
    {
        $this->admin(['service-availability.update']);

        $this->set(['service_category_id' => $this->ride->id, 'service_enabled' => false], $this->cairo)->assertOk();

        $cairo = $this->resolve($this->ride, $this->cairo);
        $this->assertFalse($cairo['service_open']);
        $this->assertFalse($cairo['customer_visible']);
        $this->assertFalse($cairo['customer_can_order']);
        $this->assertFalse($cairo['provider_can_use']);

        // Giza and the country as a whole are untouched.
        $this->assertTrue($this->resolve($this->ride, $this->giza)['provider_can_use']);
        $this->assertTrue($this->resolve($this->ride)['customer_can_order']);
    }

    public function test_a_region_follows_its_country_until_it_sets_a_switch_and_can_go_back(): void
    {
        $this->admin(['service-availability.update', 'service-availability.view']);

        $this->set(['service_category_id' => $this->ride->id, 'provider_enabled' => false])->assertOk();
        $this->assertFalse($this->resolve($this->ride, $this->cairo)['provider_can_use'], 'the region follows the country');

        // The region opens the provider app for itself.
        $this->set(['service_category_id' => $this->ride->id, 'provider_enabled' => true], $this->cairo)->assertOk();
        $this->assertTrue($this->resolve($this->ride, $this->cairo)['provider_can_use']);
        $this->assertFalse($this->resolve($this->ride, $this->giza)['provider_can_use']);

        $list = $this->getJson("/api/admin/v1/service-availability?country_id={$this->egypt->id}&region_id={$this->cairo->id}")->assertOk()->json('data');
        $ride = collect($list)->firstWhere('id', $this->ride->id);
        $this->assertTrue($ride['overridden']['provider_enabled']);
        $this->assertFalse($ride['overridden']['customer_enabled']);

        // Back to following the country.
        $this->postJson('/api/admin/v1/service-availability/reset', ['country_id' => $this->egypt->id, 'region_id' => $this->cairo->id, 'service_category_id' => $this->ride->id])->assertOk();
        $this->assertFalse($this->resolve($this->ride, $this->cairo)['provider_can_use']);
        $this->assertSame(0, ServiceAvailability::query()->whereNotNull('region_id')->count());
    }

    public function test_clearing_the_last_switch_of_a_region_removes_its_row(): void
    {
        $this->admin(['service-availability.update']);

        $this->set(['service_category_id' => $this->shop->id, 'customer_enabled' => false], $this->giza)->assertOk();
        $this->assertSame(1, ServiceAvailability::query()->where('region_id', $this->giza->id)->count());

        $this->set(['service_category_id' => $this->shop->id, 'customer_enabled' => null], $this->giza)->assertOk();
        $this->assertSame(0, ServiceAvailability::query()->where('region_id', $this->giza->id)->count());
    }

    public function test_the_provider_switch_exists_only_for_services_with_providers(): void
    {
        $this->admin(['service-availability.update', 'service-availability.view']);

        $this->set(['service_category_id' => $this->shop->id, 'provider_enabled' => false])->assertUnprocessable()->assertJsonValidationErrors('provider_enabled');

        $list = collect($this->getJson("/api/admin/v1/service-availability?country_id={$this->egypt->id}")->assertOk()->json('data'));
        $this->assertTrue($list->firstWhere('id', $this->ride->id)['has_provider']);
        $this->assertFalse($list->firstWhere('id', $this->shop->id)['has_provider']);
        $this->assertFalse($this->resolve($this->shop)['provider_can_use']);
    }

    public function test_a_country_cannot_leave_a_switch_unset_and_a_region_must_belong_to_it(): void
    {
        $this->admin(['service-availability.update']);

        $this->set(['service_category_id' => $this->ride->id, 'customer_enabled' => null])->assertUnprocessable();

        $otherCountry = Country::create([
            'code' => 'SA', 'code_alpha3' => 'SAU', 'dial_code' => '+966', 'phone_starts_with' => '5', 'phone_length' => 9,
            'is_default' => false, 'flag_id' => Flag::query()->value('id'), 'currency_id' => Currency::query()->value('id'), 'status' => true,
        ]);
        $riyadh = Region::create(['country_id' => $otherCountry->id, 'status' => true]);

        $this->set(['service_category_id' => $this->ride->id, 'customer_enabled' => false], $riyadh)->assertUnprocessable();
    }

    public function test_the_dashboard_needs_the_permissions(): void
    {
        $this->admin(['countries.view']);

        $this->getJson("/api/admin/v1/service-availability?country_id={$this->egypt->id}")->assertForbidden();
        $this->set(['service_category_id' => $this->ride->id, 'service_enabled' => false])->assertForbidden();

        $this->admin(['service-availability.view']);
        $this->getJson("/api/admin/v1/service-availability?country_id={$this->egypt->id}")->assertOk();
        $this->set(['service_category_id' => $this->ride->id, 'service_enabled' => false])->assertForbidden();
    }

    public function test_regions_are_managed_per_country(): void
    {
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $code => $direction) {
            Language::create(['code' => $code, 'direction' => $direction, 'is_default_website' => $code === 'en', 'is_default_dashboard' => $code === 'en', 'stores_translation' => true, 'status' => true, 'flag_id' => Flag::query()->value('id')]);
        }
        $this->admin(['regions.view', 'regions.create', 'regions.update', 'regions.delete']);

        $created = $this->postJson('/api/admin/v1/regions', [
            'country_id' => $this->egypt->id,
            'translations' => [['locale' => 'en', 'name' => 'Alexandria'], ['locale' => 'ar', 'name' => 'الإسكندرية']],
        ])->assertSuccessful();
        $id = $created->json('data.id');

        $this->getJson("/api/admin/v1/regions/dropdown?country_id={$this->egypt->id}")->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/admin/v1/regions')->assertOk()->assertJsonPath('data.0.country_id', $this->egypt->id);

        // The list narrows to one country.
        $other = Country::create(['code' => 'SA', 'code_alpha3' => 'SAU', 'dial_code' => '+966', 'phone_starts_with' => '5', 'phone_length' => 9, 'is_default' => false, 'flag_id' => Flag::query()->value('id'), 'currency_id' => Currency::query()->value('id'), 'status' => true]);
        Region::create(['country_id' => $other->id, 'status' => true]);
        $this->getJson('/api/admin/v1/regions?country_id='.$other->id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/v1/regions?country_id='.$this->egypt->id)->assertOk()->assertJsonCount(3, 'data');

        $this->deleteJson("/api/admin/v1/regions/{$id}")->assertSuccessful();
        $this->assertSoftDeleted('regions', ['id' => $id]);
    }
}
