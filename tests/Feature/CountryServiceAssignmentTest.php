<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CountryServiceAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Flag $flag;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->flag = Flag::create(['code' => 'eg']);
        $this->currency = Currency::create([
            'code' => 'EGP', 'symbol' => 'E£', 'decimal_places' => 2,
            'exchange_rate' => 1, 'is_default' => true, 'status' => true,
        ]);

        foreach ([
            ['en', 'ltr', true, true],
            ['ar', 'rtl', false, false],
        ] as [$code, $direction, $website, $dashboard]) {
            Language::create([
                'code' => $code, 'direction' => $direction,
                'is_default_website' => $website, 'is_default_dashboard' => $dashboard,
                'stores_translation' => true, 'status' => true, 'flag_id' => $this->flag->id,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create([
            'name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active',
        ]);
        foreach (['countries.view', 'countries.create', 'countries.update'] as $permission) {
            Permission::findOrCreate($permission, 'admin_api');
        }
        $admin->givePermissionTo(['countries.view', 'countries.create', 'countries.update']);
        Sanctum::actingAs($admin, [], 'admin_api');
    }

    public function test_admin_can_assign_leaf_services_when_creating_a_country(): void
    {
        $ride = $this->leaf('passenger_ride');
        $this->leaf('admin');

        $response = $this->postJson('/api/admin/v1/countries', $this->payload([
            'service_ids' => [$ride->id],
        ]))->assertCreated();

        $id = $response->json('data.id');
        $this->assertEqualsCanonicalizing([$ride->id], $response->json('data.service_ids'));
        $this->assertEqualsCanonicalizing([$ride->id], Country::findOrFail($id)->serviceCategories()->pluck('service_categories.id')->all());
    }

    public function test_admin_can_replace_and_clear_assigned_services(): void
    {
        $ride = $this->leaf('passenger_ride');
        $food = $this->leaf('restaurants');
        $country = $this->country([$ride->id, $food->id]);

        $this->putJson('/api/admin/v1/countries/'.$country->id, $this->payload([
            'code' => 'EG',
            'service_ids' => [$food->id],
        ]))->assertOk()->assertJsonPath('data.service_ids', [$food->id]);

        $this->putJson('/api/admin/v1/countries/'.$country->id, $this->payload([
            'code' => 'EG',
            'service_ids' => [],
        ]))->assertOk()->assertJsonPath('data.service_ids', []);

        $this->assertSame(0, $country->fresh()->serviceCategories()->count());
    }

    public function test_omitting_service_ids_on_update_keeps_the_current_assignment(): void
    {
        $ride = $this->leaf('passenger_ride');
        $country = $this->country([$ride->id]);

        $payload = $this->payload(['code' => 'EG']);
        unset($payload['service_ids']);

        $this->putJson('/api/admin/v1/countries/'.$country->id, $payload)
            ->assertOk()
            ->assertJsonPath('data.service_ids', [$ride->id]);
    }

    public function test_admin_only_and_parent_categories_cannot_be_assigned(): void
    {
        $parent = ServiceCategory::create([
            'module_name' => 'transport_group', 'status' => true, 'sort_order' => 1,
        ]);
        ServiceCategory::create([
            'parent_id' => $parent->id, 'module_name' => 'passenger_ride', 'status' => true, 'sort_order' => 1,
        ]);
        $adminOnly = $this->leaf('admin');

        $this->postJson('/api/admin/v1/countries', $this->payload([
            'service_ids' => [$parent->id],
        ]))->assertUnprocessable()->assertJsonValidationErrors('service_ids.0');

        $this->postJson('/api/admin/v1/countries', $this->payload([
            'code' => 'SA',
            'service_ids' => [$adminOnly->id],
        ]))->assertUnprocessable()->assertJsonValidationErrors('service_ids.0');
    }

    public function test_assignable_dropdown_hides_admin_only_modules(): void
    {
        $ride = $this->leaf('passenger_ride');
        $this->leaf('admin');

        $ids = collect($this->getJson('/api/admin/v1/service-categories/dropdown?assignable_for_countries=1')
            ->assertOk()
            ->json('data'))->pluck('id')->all();

        $this->assertContains($ride->id, $ids);
        $this->assertNotContains($this->leafId('admin'), $ids);
    }

    private function leaf(string $module): ServiceCategory
    {
        return ServiceCategory::query()->firstOrCreate(
            ['module_name' => $module],
            ['status' => true, 'sort_order' => 1],
        );
    }

    private function leafId(string $module): int
    {
        return (int) ServiceCategory::query()->where('module_name', $module)->value('id');
    }

    /**
     * @param  list<int>  $serviceIds
     */
    private function country(array $serviceIds): Country
    {
        $country = Country::create([
            'code' => 'EG',
            'dial_code' => '+20',
            'phone_starts_with' => '1',
            'phone_length' => 10,
            'is_default' => true,
            'status' => true,
            'flag_id' => $this->flag->id,
            'currency_id' => $this->currency->id,
        ]);
        $country->translations()->createMany([
            ['locale' => 'en', 'name' => 'Egypt'],
            ['locale' => 'ar', 'name' => 'مصر'],
        ]);
        $country->serviceCategories()->sync($serviceIds);

        return $country;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'JO',
            'dial_code' => '+962',
            'phone_starts_with' => '7',
            'phone_length' => 9,
            'flag_id' => $this->flag->id,
            'currency_id' => $this->currency->id,
            'status' => true,
            'translations' => [
                ['locale' => 'en', 'name' => 'Jordan'],
                ['locale' => 'ar', 'name' => 'الأردن'],
            ],
        ], $overrides);
    }
}
