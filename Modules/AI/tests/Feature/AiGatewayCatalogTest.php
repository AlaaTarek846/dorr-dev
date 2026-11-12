<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiGateway;
use Modules\AI\Models\AiRoutingPolicy;
use Modules\AI\Tests\Feature\Concerns\BuildsAiRoutingScreens;
use Tests\TestCase;

/**
 * AI gateways as a catalog like countries: a name per stored language (environment / default policy stay
 * technical), status toggle and counts, trash / restore / force delete, ai-gateways.* permissions - and a
 * switched-off or trashed gateway is never used for routing.
 */
class AiGatewayCatalogTest extends TestCase
{
    use BuildsAiRoutingScreens, RefreshDatabase;

    private const BASE = '/api/admin/v1/ai-gateways';

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
            'environment' => 'production',
            'default_policy_id' => null,
            'status' => true,
            'translations' => [
                ['locale' => 'ar', 'name' => 'البوابة الرئيسية'],
                ['locale' => 'en', 'name' => 'Main gateway'],
            ],
        ];
    }

    public function test_admin_creates_a_gateway_with_a_name_per_language(): void
    {
        $this->actingAsScreenAdmin('ai-gateways');
        $policy = AiRoutingPolicy::query()->create(['name' => 'Global', 'scope_type' => 'global', 'is_active' => true]);

        $response = $this->postJson(self::BASE, $this->payload(['default_policy_id' => $policy->id]), ['X-Locale' => 'en'])->assertCreated();

        $gateway = AiGateway::query()->findOrFail($response->json('data.id'));
        $this->assertCount(2, $gateway->translations);
        $this->assertSame('Main gateway', $response->json('data.name'));
        $this->assertSame($policy->id, $response->json('data.default_policy.id'));
        $this->assertCount(2, $response->json('data.translations'));
        $this->assertTrue($response->json('data.is_active'));

        $this->getJson(self::BASE."/{$gateway->id}", ['X-Locale' => 'ar'])->assertOk()->assertJsonPath('data.name', 'البوابة الرئيسية');
    }

    public function test_a_trashed_policy_cannot_be_picked_unless_it_is_already_the_default(): void
    {
        $this->actingAsScreenAdmin('ai-gateways');
        $policy = AiRoutingPolicy::query()->create(['name' => 'Global', 'scope_type' => 'global', 'is_active' => true]);
        $id = $this->postJson(self::BASE, $this->payload(['default_policy_id' => $policy->id]))->json('data.id');
        $policy->delete();

        $this->putJson(self::BASE."/{$id}", $this->payload(['default_policy_id' => $policy->id]))->assertOk();
        $this->postJson(self::BASE, $this->payload(['default_policy_id' => $policy->id]))->assertUnprocessable()->assertJsonValidationErrors('default_policy_id');
    }

    public function test_status_counts_trash_restore_and_force_delete(): void
    {
        $this->actingAsScreenAdmin('ai-gateways');
        $id = $this->postJson(self::BASE, $this->payload())->json('data.id');

        $this->patchJson(self::BASE."/{$id}/status", ['status' => false])->assertOk();
        $this->getJson(self::BASE.'?status_counts=1')->assertOk()->assertJsonPath('status_counts.inactive', 1);

        $this->deleteJson(self::BASE."/{$id}")->assertSuccessful();
        $this->assertSoftDeleted('ai_gateways', ['id' => $id]);
        $this->postJson(self::BASE."/{$id}/restore")->assertSuccessful();

        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$id]])->assertSuccessful();
        $this->deleteJson(self::BASE."/{$id}/force")->assertSuccessful();
        $this->assertDatabaseMissing('ai_gateways', ['id' => $id]);
        $this->assertDatabaseMissing('ai_gateway_translations', ['ai_gateway_id' => $id]);
    }

    public function test_each_action_needs_its_permission(): void
    {
        $gateway = AiGateway::query()->create(['name' => 'Main', 'environment' => 'production', 'is_active' => true]);

        $this->actingAsScreenAdmin('ai-gateways', ['view']);
        $this->getJson(self::BASE)->assertOk();
        $this->postJson(self::BASE, $this->payload())->assertForbidden();
        $this->deleteJson(self::BASE."/{$gateway->id}")->assertForbidden();

        $this->actingAsScreenAdmin('ai-gateways', ['view', 'delete']);
        $this->deleteJson(self::BASE."/{$gateway->id}")->assertSuccessful();

        $this->actingAsScreenAdmin('ai-gateways', [], ['ai-routing-rules.view']);
        $this->getJson(self::BASE)->assertForbidden();
    }

    public function test_a_switched_off_or_trashed_gateway_is_never_used_for_routing(): void
    {
        $gateway = AiGateway::query()->create(['name' => 'Main', 'environment' => 'production', 'is_active' => true]);

        $this->assertSame($gateway->id, $this->resolveRouting()['gateway']?->id);

        $gateway->update(['status' => false]);
        $this->assertNull($this->resolveRouting()['gateway']);

        $gateway->update(['status' => true]);
        $gateway->delete();
        $this->assertNull($this->resolveRouting()['gateway']);
    }
}
