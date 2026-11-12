<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\AiProviderDataRule;
use Modules\AI\Services\AiGateway;
use Modules\AI\Tests\Feature\Concerns\BuildsAiRoutingScreens;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AI provider data rules as a catalog like countries, without translations (a provider, two switches, JSON): status
 * toggle and counts, trash / restore / force delete, bulk delete, one rule per provider (the trash included) and the
 * ai-provider-data-rules.* permissions per action - and a switched-off or trashed rule is never applied by the gateway.
 */
class AiProviderDataRuleCatalogTest extends TestCase
{
    use BuildsAiRoutingScreens, RefreshDatabase;

    private const BASE = '/api/admin/v1/ai-provider-data-rules';

    private function provider(string $key = 'openai'): AiProvider
    {
        return AiProvider::query()->create([
            'key' => $key, 'name' => 'Provider '.$key, 'is_enabled' => true, 'api_key' => 'test-key-not-real', 'model' => 'gpt-4o-mini',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $providerId, array $extra = []): array
    {
        return $extra + [
            'provider_id' => $providerId,
            'sanitize_pii' => true,
            'sanitize_secrets' => false,
            'transformation_rules' => ['mask_fields' => ['email']],
            'status' => true,
        ];
    }

    public function test_crud_status_counts_trash_and_one_rule_per_provider(): void
    {
        $this->actingAsScreenAdmin('ai-provider-data-rules');
        $openai = $this->provider();
        $groq = $this->provider('groq');

        $response = $this->postJson(self::BASE, $this->payload($openai->id))->assertCreated();
        $id = $response->json('data.id');
        $this->assertSame(['mask_fields' => ['email']], $response->json('data.transformation_rules'));
        $this->assertTrue($response->json('data.is_active'));
        $this->assertSame('openai', $response->json('data.provider.key'));
        $b = $this->postJson(self::BASE, $this->payload($groq->id))->json('data.id');

        $this->postJson(self::BASE, $this->payload($openai->id))->assertUnprocessable()->assertJsonValidationErrors('provider_id');
        $this->putJson(self::BASE."/{$id}", $this->payload($openai->id, ['sanitize_secrets' => true]))->assertOk()->assertJsonPath('data.sanitize_secrets', true);

        $this->patchJson(self::BASE."/{$id}/status", ['status' => false])->assertOk()->assertJsonPath('data.status', false);
        $this->getJson(self::BASE.'?status_counts=1')->assertOk()
            ->assertJsonPath('status_counts.total', 2)
            ->assertJsonPath('status_counts.inactive', 1);

        $this->deleteJson(self::BASE."/{$id}")->assertSuccessful();
        $this->assertSoftDeleted('ai_provider_data_rules', ['id' => $id]);
        // A provider whose rule is in the trash can't get a second one: restore it instead.
        $this->postJson(self::BASE, $this->payload($openai->id))->assertUnprocessable()->assertJsonValidationErrors('provider_id');
        $this->postJson(self::BASE."/{$id}/restore")->assertSuccessful();

        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$id, $b]])->assertSuccessful();
        $this->assertSoftDeleted('ai_provider_data_rules', ['id' => $b]);
        $this->deleteJson(self::BASE."/{$id}/force")->assertSuccessful();
        $this->assertDatabaseMissing('ai_provider_data_rules', ['id' => $id]);
        $this->postJson(self::BASE, $this->payload($openai->id))->assertCreated();
    }

    public function test_each_action_needs_its_permission(): void
    {
        $rule = AiProviderDataRule::query()->create(['provider_id' => $this->provider()->id, 'is_active' => true]);
        $other = $this->provider('groq');

        $this->actingAsScreenAdmin('ai-provider-data-rules', ['view']);
        $this->getJson(self::BASE)->assertOk();
        $this->getJson(self::BASE."/{$rule->id}")->assertOk()->assertJsonPath('data.status', true);
        $this->postJson(self::BASE, $this->payload($other->id))->assertForbidden();
        $this->putJson(self::BASE."/{$rule->id}", ['sanitize_pii' => false])->assertForbidden();
        $this->patchJson(self::BASE."/{$rule->id}/status", ['status' => false])->assertForbidden();
        $this->deleteJson(self::BASE."/{$rule->id}")->assertForbidden();
        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$rule->id]])->assertForbidden();

        $this->actingAsScreenAdmin('ai-provider-data-rules', ['view', 'create', 'change-status']);
        $this->postJson(self::BASE, $this->payload($other->id))->assertCreated();
        $this->patchJson(self::BASE."/{$rule->id}/status", ['status' => false])->assertOk();

        $this->actingAsScreenAdmin('ai-provider-data-rules', []);
        $this->getJson(self::BASE)->assertForbidden();
    }

    public function test_a_switched_off_or_trashed_rule_is_never_applied(): void
    {
        $provider = $this->provider();
        $rule = AiProviderDataRule::query()->create(['provider_id' => $provider->id, 'sanitize_pii' => true, 'sanitize_secrets' => false, 'is_active' => true]);
        $apply = function () use ($provider): string {
            $method = new ReflectionMethod(AiGateway::class, 'applyDataRules');
            $method->setAccessible(true);

            return $method->invoke(app(AiGateway::class), $provider, [['role' => 'user', 'content' => 'mail ali@example.com']])[0]['content'];
        };

        $this->assertStringNotContainsString('ali@example.com', $apply());

        $rule->update(['status' => false]);
        $this->assertSame('mail ali@example.com', $apply(), 'switched off');

        $rule->update(['status' => true]);
        $rule->delete();
        $this->assertSame('mail ali@example.com', $apply(), 'trashed: same as switched off');

        $rule->restore();
        $this->assertStringNotContainsString('ali@example.com', $apply());
    }
}
