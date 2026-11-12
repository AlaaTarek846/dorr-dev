<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiSecurityPolicy;
use Modules\AI\Tests\Feature\Concerns\BuildsAiRoutingScreens;
use Tests\TestCase;

/**
 * AI security policies as a catalog like countries: a name / description per stored language (the four switches stay
 * technical), status toggle and counts, search, trash / restore / force delete, bulk delete and the
 * ai-security-policies.* permissions per action.
 */
class AiSecurityPolicyCatalogTest extends TestCase
{
    use BuildsAiRoutingScreens, RefreshDatabase;

    private const BASE = '/api/admin/v1/ai-security-policies';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedStoredLanguages();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return $extra + [
            'authentication_required' => true,
            'authorization_required' => true,
            'tenant_isolation_required' => false,
            'rate_limit_enabled' => true,
            'status' => true,
            'translations' => [
                ['locale' => 'ar', 'name' => 'السياسة الأمنية', 'description' => 'تسجيل دخول وصلاحيات'],
                ['locale' => 'en', 'name' => 'Security policy', 'description' => 'Sign-in and permissions'],
            ],
        ];
    }

    public function test_admin_creates_a_policy_with_a_translation_per_language(): void
    {
        $this->actingAsScreenAdmin('ai-security-policies');

        $response = $this->postJson(self::BASE, $this->payload(), ['X-Locale' => 'en'])->assertCreated();

        $policy = AiSecurityPolicy::query()->findOrFail($response->json('data.id'));
        $this->assertCount(2, $policy->translations);
        $this->assertSame('Security policy', $response->json('data.name'));
        $this->assertFalse($response->json('data.tenant_isolation_required'));
        $this->assertTrue($response->json('data.is_active'));
        $this->assertCount(2, $response->json('data.translations'));

        $this->getJson(self::BASE."/{$policy->id}", ['X-Locale' => 'ar'])->assertOk()->assertJsonPath('data.name', 'السياسة الأمنية');

        $payload = $this->payload();
        $payload['translations'] = [['locale' => 'en', 'name' => 'Only English']];
        $this->postJson(self::BASE, $payload)->assertUnprocessable()->assertJsonValidationErrors('translations');

        $this->putJson(self::BASE."/{$policy->id}", ['rate_limit_enabled' => false, 'translations' => [
            ['locale' => 'ar', 'name' => 'اسم جديد'], ['locale' => 'en', 'name' => 'New name'],
        ]], ['X-Locale' => 'en'])->assertOk()->assertJsonPath('data.name', 'New name')->assertJsonPath('data.rate_limit_enabled', false);
    }

    public function test_status_toggle_counts_and_search(): void
    {
        $this->actingAsScreenAdmin('ai-security-policies');
        $id = $this->postJson(self::BASE, $this->payload())->json('data.id');
        $this->postJson(self::BASE, $this->payload(['translations' => [
            ['locale' => 'ar', 'name' => 'حدود الطلبات'], ['locale' => 'en', 'name' => 'Rate limits'],
        ]]))->assertCreated();

        $this->patchJson(self::BASE."/{$id}/status", ['status' => false])->assertOk()->assertJsonPath('data.status', false);

        $this->getJson(self::BASE.'?status_counts=1')->assertOk()
            ->assertJsonPath('status_counts.total', 2)
            ->assertJsonPath('status_counts.active', 1)
            ->assertJsonPath('status_counts.inactive', 1);

        $search = json_encode(['searchKey' => 'Rate', 'columns' => ['id'], 'searchInTranslations' => true, 'filterTranslationByLocale' => false]);
        $this->assertSame(['Rate limits'], collect($this->getJson(self::BASE.'?search='.urlencode($search), ['X-Locale' => 'en'])->json('data'))->pluck('name')->all());
    }

    public function test_trash_restore_force_delete_and_bulk_delete(): void
    {
        $this->actingAsScreenAdmin('ai-security-policies');
        $a = $this->postJson(self::BASE, $this->payload())->json('data.id');
        $b = $this->postJson(self::BASE, $this->payload())->json('data.id');

        $this->deleteJson(self::BASE."/{$a}")->assertSuccessful();
        $this->assertSoftDeleted('ai_security_policies', ['id' => $a]);
        $this->getJson(self::BASE.'?status_counts=1')->assertJsonPath('status_counts.total', 1);
        $this->assertSame([$a], collect($this->getJson(self::BASE.'?trashed=1')->json('data'))->pluck('id')->all());

        $this->postJson(self::BASE."/{$a}/restore")->assertSuccessful();
        $this->assertNotSoftDeleted('ai_security_policies', ['id' => $a]);

        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$a, $b]])->assertSuccessful();
        $this->assertSoftDeleted('ai_security_policies', ['id' => $b]);

        // Nothing points at a policy: deleting it for good is always allowed (its translations go with it).
        $this->deleteJson(self::BASE."/{$a}/force")->assertSuccessful();
        $this->assertDatabaseMissing('ai_security_policies', ['id' => $a]);
        $this->assertDatabaseMissing('ai_security_policy_translations', ['ai_security_policy_id' => $a]);
    }

    public function test_each_action_needs_its_permission(): void
    {
        $policy = AiSecurityPolicy::query()->create(['name' => 'Default', 'is_active' => true]);
        $this->assertSame('Default', $policy->fresh()->name, 'flat text is written into every stored language');
        $this->assertCount(2, $policy->fresh()->translations);

        $this->actingAsScreenAdmin('ai-security-policies', ['view']);
        $this->getJson(self::BASE)->assertOk();
        $this->getJson(self::BASE."/{$policy->id}")->assertOk();
        $this->postJson(self::BASE, $this->payload())->assertForbidden();
        $this->putJson(self::BASE."/{$policy->id}", $this->payload())->assertForbidden();
        $this->patchJson(self::BASE."/{$policy->id}/status", ['status' => false])->assertForbidden();
        $this->deleteJson(self::BASE."/{$policy->id}")->assertForbidden();
        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$policy->id]])->assertForbidden();

        $this->actingAsScreenAdmin('ai-security-policies', ['view', 'create', 'update', 'change-status']);
        $this->postJson(self::BASE, $this->payload())->assertCreated();
        $this->putJson(self::BASE."/{$policy->id}", $this->payload())->assertOk();
        $this->patchJson(self::BASE."/{$policy->id}/status", ['status' => false])->assertOk();

        $this->actingAsScreenAdmin('ai-security-policies', ['view', 'delete', 'multiple-delete']);
        $this->deleteJson(self::BASE."/{$policy->id}")->assertSuccessful();
        $this->deleteJson(self::BASE."/{$policy->id}/force")->assertSuccessful();

        $this->actingAsScreenAdmin('ai-security-policies', []);
        $this->getJson(self::BASE)->assertForbidden();
    }
}
