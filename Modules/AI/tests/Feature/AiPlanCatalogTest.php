<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiSubscription;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * AI plans as a catalog like countries: translated text per stored language, status toggle, trash / restore,
 * force delete blocked while subscribed, ai-plans.* permissions - and the apps' plans API in the caller's language.
 */
class AiPlanCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const ACTIONS = ['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete'];

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

        $sar = Currency::create(['code' => 'SAR', 'symbol' => 'ر.س', 'decimal_places' => 2]);
        Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9,
            'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $sar->id, 'status' => true,
        ]);
    }

    /**
     * @param  list<string>  $actions
     */
    private function actingAsAdmin(array $actions = self::ACTIONS): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ACTIONS as $action) {
            Permission::findOrCreate("ai-plans.{$action}", 'admin_api');
        }

        $admin = Admin::query()->create([
            'name' => 'Plans Admin', 'email' => 'plans-'.uniqid().'@example.test', 'password' => 'password', 'status' => true,
        ]);
        $admin->givePermissionTo(array_map(fn ($action) => "ai-plans.{$action}", $actions));

        Sanctum::actingAs($admin, ['*'], 'admin_api');

        return $admin;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return $extra + [
            'code' => 'pro-'.uniqid(),
            'usage_minutes' => 120,
            'duration_days' => 30,
            'price' => 99,
            'status' => true,
            'translations' => [
                ['locale' => 'ar', 'name' => 'الاحترافية', 'description' => 'وصف', 'badge' => 'الأكثر طلبًا', 'features' => ['ميزة 1', '', 'ميزة 2']],
                ['locale' => 'en', 'name' => 'Pro', 'description' => 'Desc', 'badge' => 'Most popular', 'features' => ['Feature 1', 'Feature 2']],
            ],
        ];
    }

    public function test_admin_creates_a_plan_with_a_translation_per_language(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/v1/ai-plans', $this->payload(), ['X-Locale' => 'en'])->assertSuccessful();

        $plan = AiPlan::query()->findOrFail($response->json('data.id'));
        $this->assertCount(2, $plan->translations);
        $this->assertSame(['ميزة 1', 'ميزة 2'], $plan->translations->firstWhere('locale', 'ar')->features, 'empty feature lines are dropped');
        $this->assertSame('Pro', $response->json('data.name'));
        $this->assertCount(2, $response->json('data.translations'));
        $this->assertTrue($response->json('data.status'));
    }

    public function test_every_stored_language_needs_a_name(): void
    {
        $this->actingAsAdmin();

        $payload = $this->payload();
        $payload['translations'] = [['locale' => 'ar', 'name' => 'الاحترافية']];

        $this->postJson('/api/admin/v1/ai-plans', $payload)->assertUnprocessable()->assertJsonValidationErrors('translations');
    }

    public function test_status_toggle_and_counts(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/admin/v1/ai-plans', $this->payload())->json('data.id');

        $this->patchJson("/api/admin/v1/ai-plans/{$id}/status", ['status' => false])->assertOk();

        $this->assertFalse(AiPlan::query()->findOrFail($id)->status);
        $this->getJson('/api/admin/v1/ai-plans?status_counts=1')->assertOk()
            ->assertJsonPath('status_counts.inactive', 1);
    }

    public function test_trash_keeps_current_subscribers_and_force_delete_is_blocked_while_subscribed(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/admin/v1/ai-plans', $this->payload())->json('data.id');

        $user = User::query()->create([
            'name' => 'Sub', 'email' => 'sub-'.uniqid().'@example.test', 'password' => bcrypt('x-not-real'), 'status' => UserStatus::Active,
        ]);
        $subscription = AiSubscription::query()->create([
            'owner_type' => $user->getMorphClass(), 'owner_id' => $user->id, 'plan_id' => $id,
            'starts_at' => now(), 'ends_at' => now()->addDays(30), 'status' => AiSubscription::STATUS_ACTIVE,
        ]);

        $this->deleteJson("/api/admin/v1/ai-plans/{$id}")->assertSuccessful();

        $this->assertSoftDeleted('ai_plans', ['id' => $id]);
        $this->assertNotNull($subscription->fresh()->plan, 'a trashed plan still names what was bought');
        $this->assertFalse($subscription->fresh()->plan->isAvailable(), 'no renewals or new subscriptions');

        $this->deleteJson("/api/admin/v1/ai-plans/{$id}/force")->assertStatus(409);

        $this->postJson("/api/admin/v1/ai-plans/{$id}/restore")->assertSuccessful();
        $this->assertNotSoftDeleted('ai_plans', ['id' => $id]);
    }

    public function test_each_action_needs_its_permission(): void
    {
        $this->actingAsAdmin(['view']);

        $this->getJson('/api/admin/v1/ai-plans')->assertOk();
        $this->postJson('/api/admin/v1/ai-plans', $this->payload())->assertForbidden();
    }

    public function test_the_plan_picker_works_for_any_admin_and_lists_paused_plans(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/admin/v1/ai-plans', $this->payload(['status' => false]))->json('data.id');

        $this->actingAsAdmin([]);

        $this->getJson('/api/admin/v1/ai-plans')->assertForbidden();
        $row = collect($this->getJson('/api/admin/v1/ai-plans/dropdown', ['X-Locale' => 'en'])->assertOk()->json('data'))->firstWhere('id', $id);

        $this->assertSame('Pro', $row['name']);
        $this->assertFalse($row['status']);
    }

    public function test_the_apps_get_plan_text_in_their_own_language_with_the_same_keys(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/admin/v1/ai-plans', $this->payload())->json('data.id');

        $user = User::query()->create([
            'name' => 'App', 'email' => 'app-'.uniqid().'@example.test', 'password' => bcrypt('x-not-real'), 'status' => UserStatus::Active,
        ]);
        Sanctum::actingAs($user, ['*'], 'user_api');

        foreach (['en' => ['Pro', 'Most popular', ['Feature 1', 'Feature 2']], 'ar' => ['الاحترافية', 'الأكثر طلبًا', ['ميزة 1', 'ميزة 2']]] as $locale => [$name, $badge, $features]) {
            $row = collect($this->getJson('/api/user/v1/ai-subscription/plans', ['X-Locale' => $locale, 'X-Country' => 'SA'])
                ->assertOk()->json('data'))->firstWhere('id', $id);

            $this->assertSame($name, $row['name']);
            $this->assertSame($badge, $row['badge']);
            $this->assertSame($features, $row['features']);
            $this->assertTrue($row['is_active'], 'the apps still read is_active');
            $this->assertArrayNotHasKey('translations', $row, 'only the admin panel gets every language');
        }
    }

    public function test_flat_text_still_works_for_code_that_creates_plans_directly(): void
    {
        $plan = AiPlan::query()->create(['code' => 'flat', 'name' => 'Flat', 'features' => ['A'], 'usage_minutes' => 1, 'is_active' => true]);

        $this->assertSame('Flat', $plan->fresh()->name);
        $this->assertSame(['A'], $plan->fresh()->features);
        $this->assertTrue($plan->fresh()->is_active);
        $this->assertCount(2, $plan->fresh()->translations, 'written into every stored language');
    }
}
