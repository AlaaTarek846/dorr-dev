<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiDomainPolicy;
use Modules\AI\Services\AiDomainClassifier;
use Modules\AI\Tests\Feature\Concerns\BuildsAiRoutingScreens;
use Tests\TestCase;

/**
 * AI domain policies as a catalog like countries: a name / description per stored language (the domain key, prompt
 * addition and disclaimer stay technical), status toggle and counts, trash / restore / force delete,
 * ai-domain-policies.* permissions - and a switched-off or trashed policy is never applied by the classifier.
 */
class AiDomainPolicyCatalogTest extends TestCase
{
    use BuildsAiRoutingScreens, RefreshDatabase;

    private const BASE = '/api/admin/v1/ai-domain-policies';

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
            'domain_key' => AiDomainPolicy::DOMAIN_HEALTH,
            'risk_level' => AiDomainPolicy::RISK_CRITICAL,
            'requires_triage' => true,
            'system_prompt_addition' => 'Never present a confident diagnosis.',
            'disclaimer_text' => 'See a doctor.',
            'status' => true,
            'translations' => [
                ['locale' => 'ar', 'name' => 'المسار الصحي', 'description' => 'أسئلة صحية'],
                ['locale' => 'en', 'name' => 'Health', 'description' => 'Health questions'],
            ],
        ];
    }

    public function test_admin_creates_a_policy_with_a_translation_per_language(): void
    {
        $this->actingAsScreenAdmin('ai-domain-policies');

        $response = $this->postJson(self::BASE, $this->payload(), ['X-Locale' => 'en'])->assertCreated();

        $policy = AiDomainPolicy::query()->findOrFail($response->json('data.id'));
        $this->assertCount(2, $policy->translations);
        $this->assertSame('Health', $response->json('data.name'));
        $this->assertSame('Never present a confident diagnosis.', $response->json('data.system_prompt_addition'), 'technical - not translated');
        $this->assertTrue($response->json('data.requires_triage'));
        $this->assertTrue($response->json('data.status'));
        $this->assertTrue($response->json('data.is_active'));
        $this->assertCount(2, $response->json('data.translations'));

        $this->getJson(self::BASE."/{$policy->id}", ['X-Locale' => 'ar'])->assertOk()->assertJsonPath('data.name', 'المسار الصحي');
    }

    public function test_every_language_needs_a_name_and_one_policy_per_domain_the_trash_included(): void
    {
        $this->actingAsScreenAdmin('ai-domain-policies');

        $payload = $this->payload();
        $payload['translations'] = [['locale' => 'ar', 'name' => 'المسار الصحي']];
        $this->postJson(self::BASE, $payload)->assertUnprocessable()->assertJsonValidationErrors('translations');

        $id = $this->postJson(self::BASE, $this->payload())->assertCreated()->json('data.id');
        $this->deleteJson(self::BASE."/{$id}")->assertSuccessful();
        $this->postJson(self::BASE, $this->payload())->assertUnprocessable()->assertJsonValidationErrors('domain_key');
        $this->postJson(self::BASE, $this->payload(['domain_key' => 'astrology']))->assertUnprocessable()->assertJsonValidationErrors('domain_key');
    }

    public function test_status_toggle_counts_search_and_bulk_delete(): void
    {
        $this->actingAsScreenAdmin('ai-domain-policies');
        $id = $this->postJson(self::BASE, $this->payload())->json('data.id');
        $legal = $this->postJson(self::BASE, $this->payload(['domain_key' => 'legal', 'translations' => [
            ['locale' => 'ar', 'name' => 'المسار القانوني'], ['locale' => 'en', 'name' => 'Legal'],
        ]]))->assertCreated()->json('data.id');

        $this->patchJson(self::BASE."/{$id}/status", ['status' => false])->assertOk()->assertJsonPath('data.status', false);

        $this->getJson(self::BASE.'?status_counts=1')->assertOk()
            ->assertJsonPath('status_counts.total', 2)
            ->assertJsonPath('status_counts.inactive', 1);

        $search = json_encode(['searchKey' => 'Legal', 'columns' => ['domain_key'], 'searchInTranslations' => true, 'filterTranslationByLocale' => false]);
        $this->assertSame(['legal'], collect($this->getJson(self::BASE.'?search='.urlencode($search))->json('data'))->pluck('domain_key')->all());

        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$id, $legal]])->assertSuccessful();
        $this->assertSoftDeleted('ai_domain_policies', ['id' => $id]);
        $this->assertSoftDeleted('ai_domain_policies', ['id' => $legal]);
    }

    public function test_trash_restore_and_force_delete(): void
    {
        $this->actingAsScreenAdmin('ai-domain-policies');
        $id = $this->postJson(self::BASE, $this->payload())->json('data.id');

        $this->deleteJson(self::BASE."/{$id}")->assertSuccessful();
        $this->getJson(self::BASE.'?trashed=1')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->postJson(self::BASE."/{$id}/restore")->assertSuccessful();
        $this->assertNotSoftDeleted('ai_domain_policies', ['id' => $id]);

        $this->deleteJson(self::BASE."/{$id}/force")->assertStatus(409);
        $this->deleteJson(self::BASE."/{$id}")->assertSuccessful();
        $this->deleteJson(self::BASE."/{$id}/force")->assertSuccessful();
        $this->assertDatabaseMissing('ai_domain_policies', ['id' => $id]);
        $this->assertDatabaseMissing('ai_domain_policy_translations', ['ai_domain_policy_id' => $id]);
    }

    public function test_each_action_needs_its_permission(): void
    {
        $policy = AiDomainPolicy::query()->create(['domain_key' => 'legal', 'name' => 'Legal', 'is_active' => true]);

        $this->actingAsScreenAdmin('ai-domain-policies', ['view']);
        $this->getJson(self::BASE)->assertOk();
        $this->postJson(self::BASE, $this->payload())->assertForbidden();
        $this->putJson(self::BASE."/{$policy->id}", ['risk_level' => 'low'])->assertForbidden();
        $this->patchJson(self::BASE."/{$policy->id}/status", ['status' => false])->assertForbidden();
        $this->deleteJson(self::BASE."/{$policy->id}")->assertForbidden();
        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$policy->id]])->assertForbidden();

        $this->actingAsScreenAdmin('ai-domain-policies', ['view', 'update', 'change-status']);
        $this->putJson(self::BASE."/{$policy->id}", ['risk_level' => 'low'])->assertOk();
        $this->patchJson(self::BASE."/{$policy->id}/status", ['status' => false])->assertOk();

        $this->actingAsScreenAdmin('ai-domain-policies', []);
        $this->getJson(self::BASE)->assertForbidden();
    }

    public function test_a_switched_off_or_trashed_policy_is_never_applied(): void
    {
        $health = AiDomainPolicy::query()->create(['domain_key' => 'health', 'name' => 'Health', 'is_active' => true]);
        $general = AiDomainPolicy::query()->create(['domain_key' => 'general_info', 'name' => 'General', 'is_active' => true]);
        $classifier = app(AiDomainClassifier::class);

        $this->assertSame($health->id, $classifier->classify('I have a fever and pain')?->id);

        $health->update(['status' => false]);
        $this->assertSame($general->id, $classifier->classify('I have a fever and pain')?->id, 'switched off: falls back to general info');

        $health->update(['status' => true]);
        $health->delete();
        $this->assertSame($general->id, $classifier->classify('I have a fever and pain')?->id, 'trashed: same as switched off');

        $general->delete();
        $this->assertNull($classifier->classify('hello'));
    }

    public function test_flat_text_still_works_for_code_that_creates_policies_directly(): void
    {
        $policy = AiDomainPolicy::query()->create(['domain_key' => 'code', 'name' => 'Code', 'description' => 'D', 'is_active' => true]);

        $this->assertSame('Code', $policy->fresh()->name);
        $this->assertSame('D', $policy->fresh()->description);
        $this->assertTrue($policy->fresh()->is_active);
        $this->assertCount(2, $policy->fresh()->translations, 'written into every stored language');
    }
}
