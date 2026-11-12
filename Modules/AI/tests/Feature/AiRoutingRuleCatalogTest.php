<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiIntent;
use Modules\AI\Models\AiRoutingPolicy;
use Modules\AI\Models\AiRoutingRule;
use Modules\AI\Tests\Feature\Concerns\BuildsAiRoutingScreens;
use Tests\TestCase;

/**
 * AI routing rules as a catalog like countries (no translations - a rule has no text to translate): status toggle
 * and counts, trash / restore / force delete, ai-routing-rules.* permissions - and a switched-off or trashed rule
 * is never used for routing.
 */
class AiRoutingRuleCatalogTest extends TestCase
{
    use BuildsAiRoutingScreens, RefreshDatabase;

    private const BASE = '/api/admin/v1/ai-routing-rules';

    private AiRoutingPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedStoredLanguages();
        $this->policy = AiRoutingPolicy::query()->create(['name' => 'Global', 'scope_type' => 'global', 'priority' => 1, 'is_active' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return $extra + [
            'routing_policy_id' => $this->policy->id,
            'intent_id' => null,
            'provider_id' => $this->usableProvider()->id,
            'model_key' => 'gpt-4.1-mini',
            'priority' => 10,
            'selection_config' => ['max_latency_ms' => 3000],
            'status' => true,
        ];
    }

    public function test_admin_creates_a_rule_with_the_same_keys_as_before(): void
    {
        $this->actingAsScreenAdmin('ai-routing-rules');

        $response = $this->postJson(self::BASE, $this->payload(), ['X-Locale' => 'en'])->assertCreated();

        $this->assertSame($this->policy->id, $response->json('data.policy.id'));
        $this->assertSame('Global', $response->json('data.policy.name'));
        $this->assertSame('gpt-4.1-mini', $response->json('data.model_key'));
        $this->assertSame(['max_latency_ms' => 3000], $response->json('data.selection_config'));
        $this->assertTrue($response->json('data.status'));
        $this->assertTrue($response->json('data.is_active'));
        $this->assertArrayNotHasKey('translations', $response->json('data'), 'nothing to translate');
    }

    public function test_a_trashed_policy_or_intent_cannot_be_picked(): void
    {
        $this->actingAsScreenAdmin('ai-routing-rules');
        $intent = AiIntent::query()->create(['key' => 'support', 'name' => 'Support', 'is_active' => true]);
        $id = $this->postJson(self::BASE, $this->payload(['intent_id' => $intent->id]))->json('data.id');
        $intent->delete();

        $this->postJson(self::BASE, $this->payload(['intent_id' => $intent->id]))->assertUnprocessable()->assertJsonValidationErrors('intent_id');
        $this->putJson(self::BASE."/{$id}", $this->payload(['intent_id' => $intent->id]))->assertOk()
            ->assertJsonPath('data.intent.deleted', true);
    }

    public function test_status_counts_trash_restore_and_force_delete(): void
    {
        $this->actingAsScreenAdmin('ai-routing-rules');
        $a = $this->postJson(self::BASE, $this->payload())->json('data.id');
        $b = $this->postJson(self::BASE, $this->payload(['priority' => 5]))->json('data.id');

        $this->patchJson(self::BASE."/{$a}/status", ['status' => false])->assertOk();
        $this->getJson(self::BASE.'?status_counts=1')->assertOk()
            ->assertJsonPath('status_counts.total', 2)
            ->assertJsonPath('status_counts.active', 1);

        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$a, $b]])->assertSuccessful();
        $this->assertSoftDeleted('ai_routing_rules', ['id' => $a]);
        $this->assertCount(2, $this->getJson(self::BASE.'?trashed=1')->json('data'));

        $this->postJson(self::BASE."/{$a}/restore")->assertSuccessful();
        $this->deleteJson(self::BASE."/{$a}/force")->assertStatus(409);
        $this->deleteJson(self::BASE."/{$b}/force")->assertSuccessful();
        $this->assertDatabaseMissing('ai_routing_rules', ['id' => $b]);
    }

    public function test_search_by_model_provider_intent_or_policy(): void
    {
        $this->actingAsScreenAdmin('ai-routing-rules');
        $intent = AiIntent::query()->create(['key' => 'support', 'name' => 'Support desk', 'is_active' => true]);
        $other = AiRoutingPolicy::query()->create(['name' => 'Saudi policy', 'scope_type' => 'country', 'country_code' => 'SA', 'is_active' => true]);
        $a = AiRoutingRule::query()->create(['routing_policy_id' => $this->policy->id, 'model_key' => 'gpt-4.1-mini', 'priority' => 1, 'is_active' => true]);
        $b = AiRoutingRule::query()->create(['routing_policy_id' => $other->id, 'intent_id' => $intent->id, 'provider_id' => $this->usableProvider('anthropic')->id, 'priority' => 1, 'is_active' => true]);

        $find = function (string $key) {
            $search = json_encode([
                'searchKey' => $key, 'columns' => ['model_key'], 'searchInTranslations' => false, 'filterTranslationByLocale' => false,
                'searchInRelations' => [
                    ['relation' => 'provider', 'columns' => ['name', 'key']],
                    ['relation' => 'intent', 'columns' => ['key'], 'searchInRelationTranslations' => true],
                    ['relation' => 'policy', 'columns' => ['country_code'], 'searchInRelationTranslations' => true],
                ],
            ]);

            return collect($this->getJson(self::BASE.'?search='.urlencode($search), ['X-Locale' => 'en'])->assertOk()->json('data'))->pluck('id')->all();
        };

        $this->assertSame([$a->id], $find('gpt-4.1'));
        $this->assertSame([$b->id], $find('anthropic'));
        $this->assertSame([$b->id], $find('support'));
        $this->assertSame([$b->id], $find('Saudi'));
    }

    public function test_each_action_needs_its_permission(): void
    {
        $rule = AiRoutingRule::query()->create(['routing_policy_id' => $this->policy->id, 'priority' => 1, 'is_active' => true]);

        $this->actingAsScreenAdmin('ai-routing-rules', ['view']);
        $this->getJson(self::BASE)->assertOk();
        $this->postJson(self::BASE, $this->payload())->assertForbidden();
        $this->putJson(self::BASE."/{$rule->id}", ['priority' => 2])->assertForbidden();
        $this->patchJson(self::BASE."/{$rule->id}/status", ['status' => false])->assertForbidden();

        $this->actingAsScreenAdmin('ai-routing-rules', ['view', 'update']);
        $this->putJson(self::BASE."/{$rule->id}", ['priority' => 2])->assertOk();

        $this->actingAsScreenAdmin('ai-routing-rules', []);
        $this->getJson(self::BASE)->assertForbidden();
    }

    public function test_a_switched_off_or_trashed_rule_is_never_used_for_routing(): void
    {
        $openai = $this->usableProvider('openai');
        $anthropic = $this->usableProvider('anthropic');
        $rule = AiRoutingRule::query()->create(['routing_policy_id' => $this->policy->id, 'provider_id' => $anthropic->id, 'model_key' => 'claude-3-5-haiku-latest', 'priority' => 10, 'is_active' => true]);
        AiRoutingRule::query()->create(['routing_policy_id' => $this->policy->id, 'provider_id' => $openai->id, 'model_key' => 'gpt-4.1-mini', 'priority' => 5, 'is_active' => true]);

        $first = fn () => $this->resolveRouting()['candidates'][0]['provider']->id;

        $this->assertSame($anthropic->id, $first());

        $rule->update(['status' => false]);
        $this->assertSame($openai->id, $first());

        $rule->update(['status' => true]);
        $rule->delete();
        $this->assertSame($openai->id, $first());
    }

    public function test_a_rule_aimed_at_a_trashed_intent_never_applies(): void
    {
        $support = AiIntent::query()->create(['key' => 'support', 'name' => 'Support', 'is_active' => true]);
        $openai = $this->usableProvider('openai');
        $anthropic = $this->usableProvider('anthropic');
        AiRoutingRule::query()->create(['routing_policy_id' => $this->policy->id, 'intent_id' => $support->id, 'provider_id' => $anthropic->id, 'priority' => 1, 'is_active' => true]);
        AiRoutingRule::query()->create(['routing_policy_id' => $this->policy->id, 'provider_id' => $openai->id, 'priority' => 10, 'is_active' => true]);

        $first = fn () => $this->resolveRouting('I have a problem')['candidates'][0]['provider']->id;

        $this->assertSame($anthropic->id, $first(), 'the rule for this intent goes first');

        $support->delete();
        $this->assertSame($openai->id, $first());
    }
}
