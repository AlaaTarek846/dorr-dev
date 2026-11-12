<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\AI\Exceptions\AiSiteException;
use Modules\AI\Models\AiSiteHosting;
use Modules\AI\Models\AiSiteHostingPlan;
use Modules\AI\Models\AiSiteProject;
use Modules\AI\Services\Sites\AiSiteHostingService;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Site hosting plans as a catalog like countries: translated text per stored language, status toggle, trash /
 * restore, force delete blocked while a site is hosted on it, no new subscriptions or renewals on a trashed or
 * switched-off plan, ai-site-hosting-plans.* permissions - and the apps' plans API in the caller's language.
 */
class AiSiteHostingPlanCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const ACTIONS = ['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete'];

    private Country $country;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $flag = Flag::create(['code' => 'sa']);

        foreach ([['ar', 'rtl', true], ['en', 'ltr', false]] as [$code, $direction, $default]) {
            Language::create([
                'code' => $code, 'direction' => $direction, 'is_default_website' => $default, 'is_default_dashboard' => $default,
                'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id,
            ]);
        }

        $this->currency = Currency::create(['code' => 'SAR', 'symbol' => 'ر.س', 'decimal_places' => 2]);
        $this->country = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9,
            'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $this->currency->id, 'status' => true,
        ]);
    }

    /**
     * @param  list<string>  $actions
     */
    private function actingAsAdmin(array $actions = self::ACTIONS): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ACTIONS as $action) {
            Permission::findOrCreate("ai-site-hosting-plans.{$action}", 'admin_api');
        }

        $admin = Admin::query()->create([
            'name' => 'Hosting Admin', 'email' => 'hosting-'.uniqid().'@example.test', 'password' => 'password', 'status' => true,
        ]);
        $admin->givePermissionTo(array_map(fn ($action) => "ai-site-hosting-plans.{$action}", $actions));

        Sanctum::actingAs($admin, ['*'], 'admin_api');

        return $admin;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return $extra + [
            'code' => 'monthly-'.uniqid(),
            'period' => 'monthly',
            'status' => true,
            'translations' => [
                ['locale' => 'ar', 'name' => 'استضافة شهرية', 'description' => 'تتجدد كل شهر'],
                ['locale' => 'en', 'name' => 'Monthly hosting', 'description' => 'Renewed every month'],
            ],
        ];
    }

    private function user(): User
    {
        return User::query()->create([
            'name' => 'App', 'email' => 'app-'.uniqid().'@example.test', 'password' => bcrypt('x-not-real'), 'status' => UserStatus::Active,
        ]);
    }

    /** A site hosted on the plan (rows written directly - charging is AiSiteHostingTest's job). */
    private function hostOn(int $planId): AiSiteHosting
    {
        $user = $this->user();
        $project = AiSiteProject::query()->create([
            'owner_type' => $user->getMorphClass(), 'owner_id' => $user->id, 'title' => 'Site', 'slug' => Str::random(40),
            'status' => 'ready', 'access_type' => 'plan', 'brief' => ['title' => 'Site'],
        ]);

        return AiSiteHosting::query()->forceCreate([
            'project_id' => $project->id, 'owner_type' => $user->getMorphClass(), 'owner_id' => $user->id, 'plan_id' => $planId,
            'subdomain' => 'site-'.Str::lower(Str::random(8)), 'status' => AiSiteHosting::STATUS_ACTIVE, 'amount' => 29, 'currency' => 'SAR',
            'period' => 'monthly', 'country_id' => $this->country->id, 'auto_renew' => true,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDay(),
        ]);
    }

    private function reason(callable $fn): ?string
    {
        try {
            $fn();
        } catch (AiSiteException $e) {
            return $e->reason;
        }

        return null;
    }

    public function test_admin_creates_a_plan_with_a_translation_per_language(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/v1/ai-site-hosting-plans', $this->payload(), ['X-Locale' => 'en'])->assertCreated();

        $plan = AiSiteHostingPlan::query()->findOrFail($response->json('data.id'));
        $this->assertCount(2, $plan->translations);
        $this->assertSame('استضافة شهرية', $plan->translations->firstWhere('locale', 'ar')->name);
        $this->assertSame('Monthly hosting', $response->json('data.name'));
        $this->assertCount(2, $response->json('data.translations'));
        $this->assertTrue($response->json('data.status'));
    }

    public function test_every_stored_language_needs_a_name(): void
    {
        $this->actingAsAdmin();

        $payload = $this->payload();
        $payload['translations'] = [['locale' => 'ar', 'name' => 'استضافة شهرية']];

        $this->postJson('/api/admin/v1/ai-site-hosting-plans', $payload)->assertUnprocessable()->assertJsonValidationErrors('translations');
    }

    public function test_status_toggle_and_counts(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/admin/v1/ai-site-hosting-plans', $this->payload())->json('data.id');

        $this->patchJson("/api/admin/v1/ai-site-hosting-plans/{$id}/status", ['status' => false])->assertOk();

        $this->assertFalse(AiSiteHostingPlan::query()->findOrFail($id)->status);
        $this->getJson('/api/admin/v1/ai-site-hosting-plans?status_counts=1')->assertOk()
            ->assertJsonPath('status_counts.inactive', 1);
    }

    public function test_trash_keeps_hosted_sites_blocks_new_sales_and_renewals_and_force_delete_while_hosted(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/admin/v1/ai-site-hosting-plans', $this->payload())->json('data.id');
        $this->putJson("/api/admin/v1/ai-site-hosting-plans/{$id}/prices", ['prices' => [['country_id' => $this->country->id, 'price' => 29]]])->assertSuccessful();
        $hosting = $this->hostOn($id);

        $this->deleteJson("/api/admin/v1/ai-site-hosting-plans/{$id}")->assertSuccessful();

        $this->assertSoftDeleted('ai_site_hosting_plans', ['id' => $id]);
        $this->assertNotNull($hosting->fresh()->plan, 'a trashed plan still names what the site is on');
        $this->assertSame(AiSiteHosting::STATUS_ACTIVE, $hosting->fresh()->status, 'the site keeps running');
        $this->assertSame('plan_unavailable', $this->reason(fn () => app(AiSiteHostingService::class)->renew($hosting->fresh())));

        Sanctum::actingAs($this->user(), ['*'], 'user_api');
        $plans = $this->getJson('/api/user/v1/ai-sites/hosting/plans', ['X-Country' => 'SA'])->assertOk()->json('data.plans');
        $this->assertSame([], $plans, 'the apps no longer list it');

        $this->actingAsAdmin();
        $this->deleteJson("/api/admin/v1/ai-site-hosting-plans/{$id}/force")->assertStatus(409);

        $this->postJson("/api/admin/v1/ai-site-hosting-plans/{$id}/restore")->assertSuccessful();
        $this->assertNotSoftDeleted('ai_site_hosting_plans', ['id' => $id]);
    }

    public function test_a_switched_off_plan_is_not_renewed(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/admin/v1/ai-site-hosting-plans', $this->payload())->json('data.id');
        $hosting = $this->hostOn($id);

        $this->patchJson("/api/admin/v1/ai-site-hosting-plans/{$id}/status", ['status' => false])->assertOk();

        $this->assertSame('plan_unavailable', $this->reason(fn () => app(AiSiteHostingService::class)->renew($hosting->fresh())));
    }

    public function test_each_action_needs_its_permission(): void
    {
        $this->actingAsAdmin(['view']);

        $this->getJson('/api/admin/v1/ai-site-hosting-plans')->assertOk();
        $this->postJson('/api/admin/v1/ai-site-hosting-plans', $this->payload())->assertForbidden();

        $id = AiSiteHostingPlan::query()->create(['name' => 'X', 'code' => 'x-'.uniqid(), 'period' => 'monthly', 'is_active' => true])->id;
        $this->getJson("/api/admin/v1/ai-site-hosting-plans/{$id}/prices")->assertOk();
        $this->putJson("/api/admin/v1/ai-site-hosting-plans/{$id}/prices", ['prices' => []])->assertForbidden();

        $this->actingAsAdmin([]);
        $this->getJson('/api/admin/v1/ai-site-hosting-plans')->assertForbidden();
    }

    public function test_the_apps_get_plan_text_in_their_own_language_with_the_same_keys(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/admin/v1/ai-site-hosting-plans', $this->payload())->json('data.id');
        $this->putJson("/api/admin/v1/ai-site-hosting-plans/{$id}/prices", ['prices' => [['country_id' => $this->country->id, 'price' => 29]]])->assertSuccessful();

        Sanctum::actingAs($this->user(), ['*'], 'user_api');

        foreach (['en' => ['Monthly hosting', 'Renewed every month'], 'ar' => ['استضافة شهرية', 'تتجدد كل شهر']] as $locale => [$name, $description]) {
            $row = collect($this->getJson('/api/user/v1/ai-sites/hosting/plans', ['X-Locale' => $locale, 'X-Country' => 'SA'])
                ->assertOk()->json('data.plans'))->firstWhere('id', $id);

            $this->assertSame($name, $row['name']);
            $this->assertSame($description, $row['description']);
            $this->assertSame('monthly', $row['period']);
            $this->assertEquals(29, $row['price']);
            $this->assertSame('SAR', $row['currency']);
            $this->assertTrue($row['available']);
            $this->assertTrue($row['is_active'], 'the apps still read is_active');
            $this->assertArrayNotHasKey('translations', $row, 'only the admin panel gets every language');
        }
    }

    public function test_flat_text_still_works_for_code_that_creates_plans_directly(): void
    {
        $plan = AiSiteHostingPlan::query()->create(['code' => 'flat', 'name' => 'Flat', 'period' => 'yearly', 'is_active' => true]);

        $this->assertSame('Flat', $plan->fresh()->name);
        $this->assertTrue($plan->fresh()->is_active);
        $this->assertCount(2, $plan->fresh()->translations, 'written into every stored language');
    }
}
