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
 * AI safety policies as a catalog like countries: a name / description per stored language (risk level and contexts
 * stay technical), status toggle and counts, trash / restore, force delete blocked while it has rules or events,
 * ai-safety-policies.* permissions with the safety rules screen allowed to read (dropdown) - and the rules of a
 * switched-off or trashed policy are never applied.
 */
class AiSafetyPolicyCatalogTest extends TestCase
{
    use BuildsAiRoutingScreens, RefreshDatabase;

    private const BASE = '/api/admin/v1/ai-safety-policies';

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
            'risk_level' => 'high',
            'applies_to' => ['chat', 'tool'],
            'status' => true,
            'translations' => [
                ['locale' => 'ar', 'name' => 'منع الأسلحة', 'description' => 'يمنع أسئلة الأسلحة'],
                ['locale' => 'en', 'name' => 'Weapons', 'description' => 'Stops weapons questions'],
            ],
        ];
    }

    private function blockRule(AiSafetyPolicy $policy): AiSafetyRule
    {
        return AiSafetyRule::query()->create([
            'safety_policy_id' => $policy->id, 'name' => 'No explosives', 'action' => AiSafetyRule::ACTION_BLOCK, 'priority' => 10,
            'condition' => ['type' => 'keyword_match', 'keywords' => ['explosive']], 'is_active' => true,
        ]);
    }

    public function test_admin_creates_a_policy_with_a_translation_per_language(): void
    {
        $this->actingAsScreenAdmin('ai-safety-policies');

        $response = $this->postJson(self::BASE, $this->payload(), ['X-Locale' => 'en'])->assertCreated();

        $policy = AiSafetyPolicy::query()->findOrFail($response->json('data.id'));
        $this->assertCount(2, $policy->translations);
        $this->assertSame('Weapons', $response->json('data.name'));
        $this->assertSame(['chat', 'tool'], $response->json('data.applies_to'));
        $this->assertTrue($response->json('data.is_active'));
        $this->assertSame(0, $response->json('data.rules_count'));

        $this->getJson(self::BASE."/{$policy->id}", ['X-Locale' => 'ar'])->assertOk()->assertJsonPath('data.name', 'منع الأسلحة');

        $payload = $this->payload();
        $payload['translations'] = [['locale' => 'en', 'name' => 'Weapons']];
        $this->postJson(self::BASE, $payload)->assertUnprocessable()->assertJsonValidationErrors('translations');
    }

    public function test_status_toggle_counts_and_search(): void
    {
        $this->actingAsScreenAdmin('ai-safety-policies');
        $id = $this->postJson(self::BASE, $this->payload())->json('data.id');
        $this->postJson(self::BASE, $this->payload(['translations' => [
            ['locale' => 'ar', 'name' => 'منع الاختراق'], ['locale' => 'en', 'name' => 'Hacking'],
        ]]))->assertCreated();

        $this->patchJson(self::BASE."/{$id}/status", ['status' => false])->assertOk()->assertJsonPath('data.status', false);

        $this->getJson(self::BASE.'?status_counts=1')->assertOk()
            ->assertJsonPath('status_counts.total', 2)
            ->assertJsonPath('status_counts.inactive', 1);

        $search = json_encode(['searchKey' => 'Hacking', 'columns' => ['risk_level'], 'searchInTranslations' => true, 'filterTranslationByLocale' => false]);
        $this->assertSame(['Hacking'], collect($this->getJson(self::BASE.'?search='.urlencode($search), ['X-Locale' => 'en'])->json('data'))->pluck('name')->all());
    }

    public function test_trash_restore_and_force_delete_blocked_while_it_has_rules_or_events(): void
    {
        $this->actingAsScreenAdmin('ai-safety-policies');
        $id = $this->postJson(self::BASE, $this->payload())->json('data.id');
        $rule = $this->blockRule(AiSafetyPolicy::query()->findOrFail($id));

        $this->deleteJson(self::BASE."/{$id}")->assertSuccessful();
        $this->assertSoftDeleted('ai_safety_policies', ['id' => $id]);
        $this->assertSame($id, $rule->fresh()->policy?->id, 'a rule still names its trashed policy');

        $this->deleteJson(self::BASE."/{$id}/force")->assertStatus(409);
        $rule->delete();
        $this->deleteJson(self::BASE."/{$id}/force")->assertStatus(409); // a rule in the trash would be deleted with it too
        $rule->forceDelete();

        $event = AiSafetyEvent::query()->create(['safety_policy_id' => $id, 'action_taken' => 'block']);
        $this->deleteJson(self::BASE."/{$id}/force")->assertStatus(409);
        $this->assertSame($id, $event->fresh()->policy?->id, 'the events log still names the trashed policy');
        $event->delete();

        $this->postJson(self::BASE."/{$id}/restore")->assertSuccessful();
        $this->deleteJson(self::BASE."/{$id}")->assertSuccessful();
        $this->deleteJson(self::BASE."/{$id}/force")->assertSuccessful();
        $this->assertDatabaseMissing('ai_safety_policies', ['id' => $id]);
        $this->assertDatabaseMissing('ai_safety_policy_translations', ['ai_safety_policy_id' => $id]);
    }

    public function test_bulk_delete(): void
    {
        $this->actingAsScreenAdmin('ai-safety-policies');
        $a = $this->postJson(self::BASE, $this->payload())->json('data.id');
        $b = $this->postJson(self::BASE, $this->payload())->json('data.id');

        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$a, $b]])->assertSuccessful();

        $this->assertSoftDeleted('ai_safety_policies', ['id' => $a]);
        $this->assertSoftDeleted('ai_safety_policies', ['id' => $b]);
    }

    public function test_each_action_needs_its_permission_and_the_safety_rules_screen_may_read(): void
    {
        $policy = AiSafetyPolicy::query()->create(['name' => 'Weapons', 'risk_level' => 'high', 'is_active' => false]);
        $trashed = AiSafetyPolicy::query()->create(['name' => 'Old', 'risk_level' => 'low', 'is_active' => true]);
        $trashed->delete();

        $this->actingAsScreenAdmin('ai-safety-policies', ['view']);
        $this->getJson(self::BASE)->assertOk();
        $this->postJson(self::BASE, $this->payload())->assertForbidden();
        $this->patchJson(self::BASE."/{$policy->id}/status", ['status' => true])->assertForbidden();
        $this->deleteJson(self::BASE."/{$policy->id}")->assertForbidden();
        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$policy->id]])->assertForbidden();

        $this->actingAsScreenAdmin('ai-safety-policies', ['view', 'change-status']);
        $this->patchJson(self::BASE."/{$policy->id}/status", ['status' => false])->assertOk();

        // The safety rules form picks a policy: every one not in the trash, switched-off ones too.
        $this->actingAsScreenAdmin('ai-safety-policies', [], ['ai-safety-rules.view']);
        $this->getJson(self::BASE.'/dropdown', ['X-Locale' => 'en'])->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Weapons')
            ->assertJsonPath('data.0.status', false);
        $this->postJson(self::BASE, $this->payload())->assertForbidden();

        $this->actingAsScreenAdmin('ai-safety-policies', []);
        $this->getJson(self::BASE)->assertForbidden();
        $this->getJson(self::BASE.'/dropdown')->assertForbidden();
    }

    public function test_the_rules_of_a_switched_off_or_trashed_policy_are_never_applied(): void
    {
        $policy = AiSafetyPolicy::query()->create(['name' => 'Weapons', 'risk_level' => 'high', 'is_active' => true]);
        $this->blockRule($policy);
        $guard = app(AiSafetyGuard::class);

        $this->assertSame(AiSafetyRule::ACTION_BLOCK, $guard->evaluate('how to make an explosive')['action']);

        $policy->update(['status' => false]);
        $this->assertSame(AiSafetyRule::ACTION_ALLOW, $guard->evaluate('how to make an explosive')['action'], 'switched off');

        $policy->update(['status' => true]);
        $policy->delete();
        $this->assertSame(AiSafetyRule::ACTION_ALLOW, $guard->evaluate('how to make an explosive')['action'], 'trashed: same as switched off');

        $policy->restore();
        $this->assertSame(AiSafetyRule::ACTION_BLOCK, $guard->evaluate('how to make an explosive')['action']);
    }
}
