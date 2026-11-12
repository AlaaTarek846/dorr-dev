<?php

namespace Modules\Marketing\Tests\Feature;

use App\Models\ServiceAvailability;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Marketing\Models\MarketingFeatureRule;
use Modules\Marketing\Models\MarketingSubscription;
use Modules\Marketing\Services\Features\MarketingFeatures;
use Modules\Marketing\Tests\Feature\Concerns\MarketingSetup;
use Modules\Provider\Models\Provider;
use Modules\Wallet\Database\Seeders\FinancialCategorySeeder;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Services\PinService;
use Modules\Wallet\Services\WalletService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Availability per country (D4) and the separate package (D5): status, the switches, the dashboard's service switch,
 * buying a period on the payment screen, chained periods, limits, and the admin side.
 */
class MarketingSetupTest extends TestCase
{
    use MarketingSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMarketing();
    }

    public function test_status_shows_the_service_and_the_packages_priced_in_the_callers_country(): void
    {
        $this->as($this->owner);
        $this->getJson($this->api('status'))->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.features.research', true)
            ->assertJsonPath('data.subscription.active', false)
            ->assertJsonPath('data.packages.0.code', 'pro')
            ->assertJsonPath('data.packages.0.price_minor', 9900)
            ->assertJsonPath('data.packages.0.currency_code', 'SAR')
            ->assertJsonPath('data.packages.0.limits.projects', 3)
            ->assertJsonPath('data.checkout_purpose', 'marketing_package');

        // Egypt has no switches: nothing is offered and every other route is closed.
        $egyptian = $this->user('Omar', '+201000000001', $this->egypt);
        $this->as($egyptian);
        $this->getJson($this->api('status'))->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.packages', []);
        $this->getJson($this->api('projects'))->assertForbidden()->assertJsonPath('error_code', 'marketing_feature_off');
    }

    public function test_the_country_comes_from_the_account_never_from_a_header(): void
    {
        $egyptian = $this->user('Omar', '+201000000001', $this->egypt);
        $this->as($egyptian);
        $this->getJson($this->api('projects'), ['X-Country' => 'SA'])->assertForbidden();
    }

    public function test_each_feature_has_its_own_switch_and_the_service_switch_closes_all(): void
    {
        $id = $this->readyProject();
        MarketingFeatureRule::query()->where('feature', 'research')->update(['enabled' => false]);
        MarketingFeatures::forget();
        $this->postJson($this->api("projects/{$id}/research-runs"), ['kind' => 'market'])->assertForbidden()->assertJsonPath('data.feature', 'research');
        $this->getJson($this->api("projects/{$id}"))->assertOk();

        MarketingFeatureRule::query()->where('feature', 'marketing')->update(['enabled' => false]);
        MarketingFeatures::forget();
        $this->getJson($this->api("projects/{$id}"))->assertForbidden();
        $this->getJson($this->api('status'))->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.features.plans', false);
    }

    public function test_the_dashboard_service_availability_page_closes_the_service_in_a_country(): void
    {
        $this->as($this->owner);
        $this->getJson($this->api('projects'))->assertOk();

        $service = ServiceCategory::query()->create(['module_name' => 'marketing', 'status' => true, 'sort_order' => 99]);
        ServiceAvailability::query()->create(['service_category_id' => $service->id, 'country_id' => $this->saudi->id, 'service_enabled' => false]);
        MarketingFeatures::forget();

        $this->getJson($this->api('projects'))->assertForbidden();
        $this->getJson($this->api('status'))->assertJsonPath('data.available', false);
    }

    public function test_the_package_is_bought_on_the_payment_screen_and_a_second_period_follows_the_first(): void
    {
        $this->seed(FinancialCategorySeeder::class);
        app(PinService::class)->set($this->owner, '1234');
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->firstOrCreateWallet($this->owner, $this->saudi), 30000, WalletBucket::Withdrawable, WalletTransactionType::Topup);
        $this->as($this->owner);

        // Nothing works before buying (402: the apps open the packages page).
        $this->createProject()->assertStatus(402)->assertJsonPath('error_code', 'marketing_package_required');

        // An unknown package, or one not priced here, is not sold.
        $this->postJson('/api/mobile/v1/wallet/checkouts', ['purpose' => 'marketing_package', 'reference' => ['package' => 'nope']], ['X-Country' => 'SA'])->assertStatus(422);

        $checkout = $this->postJson('/api/mobile/v1/wallet/checkouts', ['purpose' => 'marketing_package', 'reference' => ['package' => 'pro']], ['X-Country' => 'SA'])
            ->assertCreated()->assertJsonPath('data.amount_minor', 9900)->json('data.id');
        $this->postJson("/api/mobile/v1/wallet/checkouts/{$checkout}/pay", ['method' => 'wallet'], ['X-Country' => 'SA', 'X-Wallet-Pin' => '1234'])->assertOk();

        $first = MarketingSubscription::query()->firstOrFail();
        $this->assertSame(9900, $first->price_minor);
        $this->assertSame('SAR', $first->currency_code);
        $this->assertEqualsWithDelta(30, $first->starts_at->diffInDays($first->ends_at), 0.01);
        $this->getJson($this->api('status'))->assertJsonPath('data.subscription.active', true)->assertJsonPath('data.subscription.package.code', 'pro');
        $this->createProject()->assertCreated();

        // Bought again: the next period starts when this one ends, not today.
        $again = $this->postJson('/api/mobile/v1/wallet/checkouts', ['purpose' => 'marketing_package', 'reference' => ['package' => 'pro']], ['X-Country' => 'SA'])->json('data.id');
        $this->postJson("/api/mobile/v1/wallet/checkouts/{$again}/pay", ['method' => 'wallet'], ['X-Country' => 'SA', 'X-Wallet-Pin' => '1234'])->assertOk();
        $second = MarketingSubscription::query()->latest('id')->firstOrFail();
        $this->assertTrue($second->starts_at->equalTo($first->ends_at));
        $this->assertSame(30000 - 2 * 9900, $wallets->firstOrCreateWallet($this->owner, $this->saudi)->fresh()->spendableMinor());
        $this->assertDatabaseHas('marketing_audit_events', ['action' => 'package.purchased']);
    }

    public function test_the_package_is_not_sold_where_the_service_is_off(): void
    {
        $this->seed(FinancialCategorySeeder::class);
        $egyptian = $this->user('Omar', '+201000000001', $this->egypt);
        $this->package->prices()->create(['country_id' => $this->egypt->id, 'price_minor' => 50000]);
        $this->as($egyptian);
        $this->postJson('/api/mobile/v1/wallet/checkouts', ['purpose' => 'marketing_package', 'reference' => ['package' => 'pro']], ['X-Country' => 'EG'])->assertForbidden();
    }

    public function test_an_expired_period_blocks_new_work_but_keeps_reading(): void
    {
        $id = $this->readyProject();
        MarketingSubscription::query()->update(['ends_at' => now()->subMinute()]);

        $this->getJson($this->api("projects/{$id}"))->assertOk();
        $this->getJson($this->api("projects/{$id}/plans"))->assertOk();
        $this->postJson($this->api("projects/{$id}/research-runs"), ['kind' => 'market'])->assertStatus(402);
        $this->postJson($this->api("projects/{$id}/plans"), ['type' => 'gtm'])->assertStatus(402);
    }

    public function test_package_limits_count_per_period(): void
    {
        $this->subscribe($this->owner);
        $this->as($this->owner);
        foreach (range(1, 3) as $i) {
            $this->createProject(['name' => "Project {$i}"])->assertCreated();
        }
        $this->createProject(['name' => 'Fourth'])->assertStatus(429)
            ->assertJsonPath('error_code', 'marketing_limit_reached')->assertJsonPath('data.limit_key', 'projects')->assertJsonPath('data.used', 3);

        // Archiving one frees a place; bringing it back needs a place again.
        $first = $this->getJson($this->api('projects'))->json('data.2.id');
        $this->postJson($this->api("projects/{$first}/archive"), ['archived' => true])->assertOk();
        $this->createProject(['name' => 'Fourth'])->assertCreated();
        $this->postJson($this->api("projects/{$first}/archive"), ['archived' => false])->assertStatus(429);
    }

    public function test_a_provider_uses_the_same_api_under_provider_v1(): void
    {
        $provider = Provider::create(['name' => 'Shop', 'email' => 'shop@example.com', 'phone' => '+966511111111', 'country_id' => $this->saudi->id, 'status' => 'active']);
        $this->subscribe($provider);
        $this->as($provider, 'provider_api');

        $id = $this->postJson('/api/provider/v1/marketing/projects', ['name' => 'Shop growth', 'industry' => 'retail'])->assertCreated()
            ->assertJsonPath('data.my_role', 'owner')->assertJsonPath('data.currency', 'SAR')->json('data.id');
        $this->getJson("/api/provider/v1/marketing/projects/{$id}")->assertOk();

        // A user does not see a provider's project.
        $this->as($this->owner);
        $this->getJson($this->api("projects/{$id}"))->assertForbidden();
        $this->getJson($this->api('projects'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_admin_switches_countries_and_prices_packages(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active']);
        $permissions = ['marketing-feature-rules.view', 'marketing-feature-rules.update', 'marketing-packages.view', 'marketing-packages.create', 'marketing-packages.update', 'marketing-packages.delete'];
        foreach ($permissions as $p) {
            Permission::findOrCreate($p, 'admin_api');
        }
        $admin->givePermissionTo($permissions);
        Sanctum::actingAs($admin, [], 'admin_api');

        $this->getJson('/api/admin/v1/marketing/feature-rules/options')->assertOk()->assertJsonPath('data.features.0', 'marketing');
        $this->getJson('/api/admin/v1/marketing/feature-rules?country_id='.$this->egypt->id)->assertOk()->assertJsonPath('data.0.enabled', false);
        $this->postJson('/api/admin/v1/marketing/feature-rules/copy', ['from_country_id' => $this->saudi->id, 'to_country_id' => $this->egypt->id])->assertOk();
        $this->getJson('/api/admin/v1/marketing/feature-rules?country_id='.$this->egypt->id)->assertJsonPath('data.0.enabled', true);
        $this->postJson('/api/admin/v1/marketing/feature-rules', ['feature' => 'research', 'country_id' => $this->egypt->id, 'enabled' => false])->assertOk();
        $this->assertFalse(app(MarketingFeatures::class)->allows($this->egypt->id, 'research'));
        $this->assertTrue(app(MarketingFeatures::class)->allows($this->egypt->id, 'plans'));
        $this->postJson('/api/admin/v1/marketing/feature-rules', ['feature' => 'nope', 'country_id' => $this->egypt->id, 'enabled' => true])->assertUnprocessable();

        $created = $this->postJson('/api/admin/v1/marketing/packages', [
            'code' => 'starter', 'name' => ['ar' => 'بداية', 'en' => 'Starter'], 'period_days' => 30,
            'limits' => ['projects' => 1, 'research_runs' => 2, 'plan_generations' => 2, 'members' => 0],
            'prices' => [['country_id' => $this->saudi->id, 'price_minor' => 4900], ['country_id' => $this->egypt->id, 'price_minor' => 15000]],
        ])->assertCreated()->assertJsonPath('data.prices.1.price_minor', 15000)->json('data.id');
        $this->postJson('/api/admin/v1/marketing/packages', ['code' => 'starter', 'name' => ['ar' => 'x', 'en' => 'x'], 'period_days' => 30])->assertUnprocessable();
        $this->putJson("/api/admin/v1/marketing/packages/{$created}", ['prices' => [['country_id' => $this->saudi->id, 'price_minor' => 5900]]])
            ->assertOk()->assertJsonCount(1, 'data.prices')->assertJsonPath('data.prices.0.price_minor', 5900);
        $this->deleteJson("/api/admin/v1/marketing/packages/{$created}")->assertOk();

        // Without the permission: refused.
        $other = Admin::create(['name' => 'B', 'email' => 'b@example.com', 'password' => 'secret123', 'status' => 'active']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($other, [], 'admin_api');
        $this->getJson('/api/admin/v1/marketing/packages')->assertForbidden();
    }
}
