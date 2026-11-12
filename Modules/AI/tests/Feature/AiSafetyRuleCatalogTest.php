<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiSafetyEvent;
use Modules\AI\Models\AiSafetyPolicy;
use Modules\AI\Models\AiSafetyRule;
use Modules\AI\Services\AiSafetyGuard;
use Modules\AI\Tests\Feature\Concerns\BuildsAiRoutingScreens;
use Tests\TestCase;

/**
 * AI safety rules as a catalog like countries: a name per stored language (condition, action and priority stay
 * technical), status toggle and counts, trash / restore, force delete blocked while events name it, a policy in the
 * trash can't be picked, ai-safety-rules.* permissions - and a switched-off or trashed rule is never applied.
 */
class AiSafetyRuleCatalogTest extends TestCase
{
    use BuildsAiRoutingScreens, RefreshDatabase;

    private const BASE = '/api/admin/v1/ai-safety-rules';

    private AiSafetyPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedStoredLanguages();
        $this->policy = AiSafetyPolicy::query()->create(['name' => 'Weapons', 'risk_level' => 'high', 'is_active' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return $extra + [
            'safety_policy_id' => $this->policy->id,
            'condition' => ['type' => 'keyword_match', 'keywords' => ['explosive']],
            'action' => AiSafetyRule::ACTION_BLOCK,
            'priority' => 10,
            'status' => true,
            'translations' => [
                ['locale' => 'ar', 'name' => 'منع المتفجرات'],
                ['locale' => 'en', 'name' => 'No explosives'],
            ],
        ];
    }

    public function test_admin_creates_a_rule_with_a_name_per_language(): void
    {
        $this->actingAsScreenAdmin('ai-safety-rules');

        $response = $this->postJson(self::BASE, $this->payload(), ['X-Locale' => 'en'])->assertCreated();

        $rule = AiSafetyRule::query()->findOrFail($response->json('data.id'));
        $this->assertCount(2, $rule->translations);
        $this->assertSame('No explosives', $response->json('data.name'));
        $this->assertSame(['explosive'], $response->json('data.condition.keywords'), 'the condition is technical - not translated');
        $this->assertSame('Weapons', $response->json('data.policy.name'));
        $this->assertTrue($response->json('data.is_active'));

        $this->getJson(self::BASE."/{$rule->id}", ['X-Locale' => 'ar'])->assertOk()->assertJsonPath('data.name', 'منع المتفجرات');

        $payload = $this->payload();
        $payload['translations'] = [['locale' => 'en', 'name' => 'No explosives']];
        $this->postJson(self::BASE, $payload)->assertUnprocessable()->assertJsonValidationErrors('translations');
        $this->postJson(self::BASE, $this->payload(['action' => 'explode']))->assertUnprocessable()->assertJsonValidationErrors('action');
    }

    public function test_a_policy_in_the_trash_cant_be_picked_but_a_rule_already_in_it_keeps_it(): void
    {
        $this->actingAsScreenAdmin('ai-safety-rules');
        $id = $this->postJson(self::BASE, $this->payload())->json('data.id');

        $this->policy->delete();

        $this->postJson(self::BASE, $this->payload())->assertUnprocessable()->assertJsonValidationErrors('safety_policy_id');
        $this->putJson(self::BASE."/{$id}", $this->payload(['priority' => 20]))->assertOk()
            ->assertJsonPath('data.policy.deleted', true);
    }

    public function test_status_toggle_counts_search_and_bulk_delete(): void
    {
        $this->actingAsScreenAdmin('ai-safety-rules');
        $id = $this->postJson(self::BASE, $this->payload())->json('data.id');
        $other = $this->postJson(self::BASE, $this->payload(['action' => 'review', 'translations' => [
            ['locale' => 'ar', 'name' => 'مراجعة الاختراق'], ['locale' => 'en', 'name' => 'Review hacking'],
        ]]))->assertCreated()->json('data.id');

        $this->patchJson(self::BASE."/{$id}/status", ['status' => false])->assertOk()->assertJsonPath('data.status', false);

        $this->getJson(self::BASE.'?status_counts=1')->assertOk()
            ->assertJsonPath('status_counts.total', 2)
            ->assertJsonPath('status_counts.inactive', 1);

        $search = json_encode(['searchKey' => 'hacking', 'columns' => ['action'], 'searchInTranslations' => true, 'filterTranslationByLocale' => false]);
        $this->assertSame([$other], collect($this->getJson(self::BASE.'?search='.urlencode($search))->json('data'))->pluck('id')->all());

        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$id, $other]])->assertSuccessful();
        $this->assertSoftDeleted('ai_safety_rules', ['id' => $id]);
        $this->assertSoftDeleted('ai_safety_rules', ['id' => $other]);
    }

    public function test_trash_restore_and_force_delete_blocked_while_events_name_it(): void
    {
        $this->actingAsScreenAdmin('ai-safety-rules');
        $id = $this->postJson(self::BASE, $this->payload())->json('data.id');
        $event = AiSafetyEvent::query()->create(['safety_policy_id' => $this->policy->id, 'safety_rule_id' => $id, 'action_taken' => 'block']);

        $this->deleteJson(self::BASE."/{$id}")->assertSuccessful();
        $this->assertSoftDeleted('ai_safety_rules', ['id' => $id]);
        $this->assertSame($id, $event->fresh()->rule?->id, 'the events log still names the trashed rule');
        $this->getJson(self::BASE.'?trashed=1')->assertOk()->assertJsonPath('data.0.id', $id);

        $this->deleteJson(self::BASE."/{$id}/force")->assertStatus(409);
        $event->delete();

        $this->postJson(self::BASE."/{$id}/restore")->assertSuccessful();
        $this->assertNotSoftDeleted('ai_safety_rules', ['id' => $id]);
        $this->deleteJson(self::BASE."/{$id}")->assertSuccessful();
        $this->deleteJson(self::BASE."/{$id}/force")->assertSuccessful();
        $this->assertDatabaseMissing('ai_safety_rules', ['id' => $id]);
        $this->assertDatabaseMissing('ai_safety_rule_translations', ['ai_safety_rule_id' => $id]);
    }

    public function test_each_action_needs_its_permission(): void
    {
        $rule = AiSafetyRule::query()->create(['safety_policy_id' => $this->policy->id, 'name' => 'R', 'action' => 'block', 'is_active' => true]);

        $this->actingAsScreenAdmin('ai-safety-rules', ['view']);
        $this->getJson(self::BASE)->assertOk();
        $this->postJson(self::BASE, $this->payload())->assertForbidden();
        $this->putJson(self::BASE."/{$rule->id}", ['priority' => 5])->assertForbidden();
        $this->patchJson(self::BASE."/{$rule->id}/status", ['status' => false])->assertForbidden();
        $this->deleteJson(self::BASE."/{$rule->id}")->assertForbidden();
        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$rule->id]])->assertForbidden();

        $this->actingAsScreenAdmin('ai-safety-rules', ['view', 'update', 'change-status']);
        $this->putJson(self::BASE."/{$rule->id}", ['priority' => 5])->assertOk();
        $this->patchJson(self::BASE."/{$rule->id}/status", ['status' => false])->assertOk();

        $this->actingAsScreenAdmin('ai-safety-rules', []);
        $this->getJson(self::BASE)->assertForbidden();
    }

    public function test_a_switched_off_or_trashed_rule_is_never_applied(): void
    {
        $rule = AiSafetyRule::query()->create([
            'safety_policy_id' => $this->policy->id, 'name' => 'No explosives', 'action' => AiSafetyRule::ACTION_BLOCK, 'priority' => 10,
            'condition' => ['type' => 'keyword_match', 'keywords' => ['explosive']], 'is_active' => true,
        ]);
        $guard = app(AiSafetyGuard::class);

        $this->assertSame($rule->id, $guard->evaluate('an explosive')['rule']?->id);

        $rule->update(['status' => false]);
        $this->assertSame(AiSafetyRule::ACTION_ALLOW, $guard->evaluate('an explosive')['action'], 'switched off');

        $rule->update(['status' => true]);
        $rule->delete();
        $this->assertSame(AiSafetyRule::ACTION_ALLOW, $guard->evaluate('an explosive')['action'], 'trashed: same as switched off');

        $rule->restore();
        $this->assertSame(AiSafetyRule::ACTION_BLOCK, $guard->evaluate('an explosive')['action']);
    }
}
