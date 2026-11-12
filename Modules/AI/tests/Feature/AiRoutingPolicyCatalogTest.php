<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiGateway;
use Modules\AI\Models\AiRoutingPolicy;
use Modules\AI\Models\AiRoutingRule;
use Modules\AI\Tests\Feature\Concerns\BuildsAiRoutingScreens;
use Tests\TestCase;

/**
 * AI routing policies as a catalog like countries: a name per stored language (scope / codes / strategy stay
 * technical), status toggle and counts, trash / restore, force delete blocked while it has rules or is a gateway's
 * default, ai-routing-policies.* permissions with the gateways and routing rules screens allowed to read - and a
 * switched-off or trashed policy is never used for routing.
 */
class AiRoutingPolicyCatalogTest extends TestCase
{
    use BuildsAiRoutingScreens, RefreshDatabase;

    private const BASE = '/api/admin/v1/ai-routing-policies';

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
            'scope_type' => 'global',
            'selection_strategy' => 'priority',
            'fallback_enabled' => true,
            'priority' => 5,
            'status' => true,
            'translations' => [
                ['locale' => 'ar', 'name' => 'السياسة العامة'],
                ['locale' => 'en', 'name' => 'Global policy'],
            ],
        ];
    }

    public function test_admin_creates_a_policy_with_a_name_per_language(): void
    {
        $this->actingAsScreenAdmin('ai-routing-policies');

        $response = $this->postJson(self::BASE, $this->payload(['country_code' => '']), ['X-Locale' => 'en'])->assertCreated();

        $policy = AiRoutingPolicy::query()->findOrFail($response->json('data.id'));
        $this->assertCount(2, $policy->translations);
        $this->assertSame('Global policy', $response->json('data.name'));
        $this->assertSame('global', $response->json('data.scope_type'));
        $this->assertNull($response->json('data.country_code'));
        $this->assertCount(2, $response->json('data.translations'));
        $this->assertTrue($response->json('data.is_active'));

        $payload = $this->payload();
        $payload['translations'] = [['locale' => 'en', 'name' => 'Only English']];
        $this->postJson(self::BASE, $payload)->assertUnprocessable()->assertJsonValidationErrors('translations');
    }

    public function test_status_toggle_and_counts(): void
    {
        $this->actingAsScreenAdmin('ai-routing-policies');
        $id = $this->postJson(self::BASE, $this->payload())->json('data.id');

        $this->patchJson(self::BASE."/{$id}/status", ['status' => false])->assertOk();

        $this->assertFalse(AiRoutingPolicy::query()->findOrFail($id)->status);
        $this->getJson(self::BASE.'?status_counts=1')->assertOk()
            ->assertJsonPath('status_counts.total', 1)
            ->assertJsonPath('status_counts.inactive', 1);
    }

    public function test_trash_restore_and_force_delete_rules(): void
    {
        $this->actingAsScreenAdmin('ai-routing-policies');
        $id = $this->postJson(self::BASE, $this->payload())->json('data.id');
        $rule = AiRoutingRule::query()->create(['routing_policy_id' => $id, 'priority' => 1, 'is_active' => true]);
        $gateway = AiGateway::query()->create(['name' => 'Main', 'environment' => 'production', 'default_policy_id' => $id, 'is_active' => true]);

        $this->deleteJson(self::BASE."/{$id}")->assertSuccessful();
        $this->assertSoftDeleted('ai_routing_policies', ['id' => $id]);
        $this->assertNotNull($gateway->fresh()->defaultPolicy, 'the gateway still names its trashed default policy');

        // Deleting it for good would delete its rules (cascade) and unlink the gateway: blocked.
        $this->deleteJson(self::BASE."/{$id}/force")->assertStatus(409);
        $rule->forceDelete();
        $this->deleteJson(self::BASE."/{$id}/force")->assertStatus(409);
        $gateway->update(['default_policy_id' => null]);

        $this->postJson(self::BASE."/{$id}/restore")->assertSuccessful();
        $this->assertNotSoftDeleted('ai_routing_policies', ['id' => $id]);

        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$id]])->assertSuccessful();
        $this->deleteJson(self::BASE."/{$id}/force")->assertSuccessful();
        $this->assertDatabaseMissing('ai_routing_policies', ['id' => $id]);
        $this->assertDatabaseMissing('ai_routing_policy_translations', ['ai_routing_policy_id' => $id]);
    }

    public function test_each_action_needs_its_permission_and_gateways_and_rules_may_read(): void
    {
        $policy = AiRoutingPolicy::query()->create(['name' => 'Global', 'scope_type' => 'global', 'is_active' => true]);

        $this->actingAsScreenAdmin('ai-routing-policies', ['view']);
        $this->getJson(self::BASE)->assertOk();
        $this->postJson(self::BASE, $this->payload())->assertForbidden();
        $this->putJson(self::BASE."/{$policy->id}", $this->payload())->assertForbidden();
        $this->postJson(self::BASE."/{$policy->id}/restore")->assertForbidden();

        foreach (['ai-gateways.view', 'ai-routing-rules.view'] as $reader) {
            $this->actingAsScreenAdmin('ai-routing-policies', [], [$reader]);
            $this->getJson(self::BASE.'/dropdown')->assertOk()->assertJsonPath('data.0.id', $policy->id)->assertJsonPath('data.0.name', 'Global');
            $this->deleteJson(self::BASE."/{$policy->id}")->assertForbidden();
        }

        $this->actingAsScreenAdmin('ai-routing-policies', []);
        $this->getJson(self::BASE.'/dropdown')->assertForbidden();
    }

    public function test_a_switched_off_or_trashed_policy_is_never_used_for_routing(): void
    {
        $policy = AiRoutingPolicy::query()->create(['name' => 'Global', 'scope_type' => 'global', 'priority' => 1, 'is_active' => true]);

        $this->assertSame($policy->id, $this->resolveRouting()['policy']?->id);

        $policy->update(['status' => false]);
        $this->assertNull($this->resolveRouting()['policy']);

        $policy->update(['status' => true]);
        $policy->delete();
        $this->assertNull($this->resolveRouting()['policy']);
    }

    public function test_a_gateways_default_policy_is_skipped_once_switched_off_or_trashed(): void
    {
        // A country policy that never matches the owner: only the gateway's default can apply.
        $policy = AiRoutingPolicy::query()->create(['name' => 'Fallback', 'scope_type' => 'country', 'country_code' => 'EG', 'is_active' => true]);
        AiGateway::query()->create(['name' => 'Main', 'environment' => 'production', 'default_policy_id' => $policy->id, 'is_active' => true]);

        $this->assertSame($policy->id, $this->resolveRouting()['policy']?->id);

        $policy->update(['status' => false]);
        $this->assertNull($this->resolveRouting()['policy']);

        $policy->update(['status' => true]);
        $policy->delete();
        $this->assertNull($this->resolveRouting()['policy']);
    }
}
