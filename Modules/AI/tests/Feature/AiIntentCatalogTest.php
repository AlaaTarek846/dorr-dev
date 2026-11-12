<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiIntent;
use Modules\AI\Models\AiRoutingPolicy;
use Modules\AI\Models\AiRoutingRule;
use Modules\AI\Services\AiIntentClassifier;
use Modules\AI\Tests\Feature\Concerns\BuildsAiRoutingScreens;
use Tests\TestCase;

/**
 * AI intents as a catalog like countries: a name / description per stored language (the key stays technical),
 * status toggle and counts, trash / restore, force delete blocked while a routing rule points at it, ai-intents.*
 * permissions with the routing rules screen allowed to read - and a switched-off or trashed intent is never
 * classified.
 */
class AiIntentCatalogTest extends TestCase
{
    use BuildsAiRoutingScreens, RefreshDatabase;

    private const BASE = '/api/admin/v1/ai-intents';

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
            'key' => 'support',
            'status' => true,
            'translations' => [
                ['locale' => 'ar', 'name' => 'دعم فني', 'description' => 'أسئلة الدعم'],
                ['locale' => 'en', 'name' => 'Support', 'description' => 'Support questions'],
            ],
        ];
    }

    public function test_admin_creates_an_intent_with_a_translation_per_language(): void
    {
        $this->actingAsScreenAdmin('ai-intents');

        $response = $this->postJson(self::BASE, $this->payload(), ['X-Locale' => 'en'])->assertCreated();

        $intent = AiIntent::query()->findOrFail($response->json('data.id'));
        $this->assertCount(2, $intent->translations);
        $this->assertSame('دعم فني', $intent->translations->firstWhere('locale', 'ar')->name);
        $this->assertSame('Support', $response->json('data.name'));
        $this->assertSame('support', $response->json('data.key'), 'the key is technical - never translated');
        $this->assertCount(2, $response->json('data.translations'));
        $this->assertTrue($response->json('data.status'));
        $this->assertTrue($response->json('data.is_active'));

        $this->getJson(self::BASE."/{$intent->id}", ['X-Locale' => 'ar'])->assertOk()->assertJsonPath('data.name', 'دعم فني');
    }

    public function test_every_stored_language_needs_a_name_and_the_key_is_unique(): void
    {
        $this->actingAsScreenAdmin('ai-intents');

        $payload = $this->payload();
        $payload['translations'] = [['locale' => 'ar', 'name' => 'دعم فني']];
        $this->postJson(self::BASE, $payload)->assertUnprocessable()->assertJsonValidationErrors('translations');

        $this->postJson(self::BASE, $this->payload())->assertCreated();
        $this->postJson(self::BASE, $this->payload())->assertUnprocessable()->assertJsonValidationErrors('key');
    }

    public function test_status_toggle_counts_and_search(): void
    {
        $this->actingAsScreenAdmin('ai-intents');
        $id = $this->postJson(self::BASE, $this->payload())->json('data.id');
        $this->postJson(self::BASE, $this->payload(['key' => 'chat', 'translations' => [
            ['locale' => 'ar', 'name' => 'محادثة عامة'], ['locale' => 'en', 'name' => 'General chat'],
        ]]))->assertCreated();

        $this->patchJson(self::BASE."/{$id}/status", ['status' => false])->assertOk()->assertJsonPath('data.status', false);

        $this->getJson(self::BASE.'?status_counts=1')->assertOk()
            ->assertJsonPath('status_counts.total', 2)
            ->assertJsonPath('status_counts.inactive', 1);

        $search = json_encode(['searchKey' => 'General', 'columns' => ['key'], 'searchInTranslations' => true, 'filterTranslationByLocale' => false]);
        $this->assertSame(['chat'], collect($this->getJson(self::BASE.'?search='.urlencode($search))->json('data'))->pluck('key')->all());
    }

    public function test_trash_restore_and_force_delete_blocked_while_a_rule_points_at_it(): void
    {
        $this->actingAsScreenAdmin('ai-intents');
        $id = $this->postJson(self::BASE, $this->payload())->json('data.id');
        $policy = AiRoutingPolicy::query()->create(['name' => 'Global', 'scope_type' => 'global', 'is_active' => true]);
        $rule = AiRoutingRule::query()->create(['routing_policy_id' => $policy->id, 'intent_id' => $id, 'priority' => 1, 'is_active' => true]);

        $this->deleteJson(self::BASE."/{$id}")->assertSuccessful();
        $this->assertSoftDeleted('ai_intents', ['id' => $id]);
        $this->assertSame($id, $rule->fresh()->intent?->id, 'a rule still names its trashed intent');

        $this->getJson(self::BASE.'?trashed=1')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->deleteJson(self::BASE."/{$id}/force")->assertStatus(409);

        $rule->delete();
        $this->deleteJson(self::BASE."/{$id}/force")->assertStatus(409);

        $rule->forceDelete();
        $this->postJson(self::BASE."/{$id}/restore")->assertSuccessful();
        $this->assertNotSoftDeleted('ai_intents', ['id' => $id]);

        $this->deleteJson(self::BASE."/{$id}")->assertSuccessful();
        $this->deleteJson(self::BASE."/{$id}/force")->assertSuccessful();
        $this->assertDatabaseMissing('ai_intents', ['id' => $id]);
        $this->assertDatabaseMissing('ai_intent_translations', ['ai_intent_id' => $id]);
    }

    public function test_bulk_delete(): void
    {
        $this->actingAsScreenAdmin('ai-intents');
        $a = $this->postJson(self::BASE, $this->payload())->json('data.id');
        $b = $this->postJson(self::BASE, $this->payload(['key' => 'chat']))->json('data.id');

        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$a, $b]])->assertSuccessful();

        $this->assertSoftDeleted('ai_intents', ['id' => $a]);
        $this->assertSoftDeleted('ai_intents', ['id' => $b]);
    }

    public function test_each_action_needs_its_permission_and_the_routing_rules_screen_may_read(): void
    {
        $intent = AiIntent::query()->create(['key' => 'chat', 'name' => 'Chat', 'is_active' => true]);

        $this->actingAsScreenAdmin('ai-intents', ['view']);
        $this->getJson(self::BASE)->assertOk();
        $this->postJson(self::BASE, $this->payload())->assertForbidden();
        $this->patchJson(self::BASE."/{$intent->id}/status", ['status' => false])->assertForbidden();
        $this->deleteJson(self::BASE."/{$intent->id}")->assertForbidden();
        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$intent->id]])->assertForbidden();

        $this->actingAsScreenAdmin('ai-intents', ['view', 'change-status']);
        $this->patchJson(self::BASE."/{$intent->id}/status", ['status' => false])->assertOk();

        $this->actingAsScreenAdmin('ai-intents', [], ['ai-routing-rules.view']);
        $this->getJson(self::BASE.'/dropdown')->assertOk()->assertJsonPath('data.0.key', 'chat')->assertJsonPath('data.0.status', false);
        $this->postJson(self::BASE, $this->payload())->assertForbidden();

        $this->actingAsScreenAdmin('ai-intents', []);
        $this->getJson(self::BASE)->assertForbidden();
        $this->getJson(self::BASE.'/dropdown')->assertForbidden();
    }

    public function test_a_switched_off_or_trashed_intent_is_never_classified(): void
    {
        $chat = AiIntent::query()->create(['key' => 'chat', 'name' => 'Chat', 'is_active' => true]);
        $support = AiIntent::query()->create(['key' => 'support', 'name' => 'Support', 'is_active' => true]);
        $classifier = app(AiIntentClassifier::class);

        $this->assertSame($support->id, $classifier->classify('I have a problem')?->id);

        $support->update(['status' => false]);
        $this->assertSame($chat->id, $classifier->classify('I have a problem')?->id, 'switched off: falls back to chat');

        $support->update(['status' => true]);
        $support->delete();
        $this->assertSame($chat->id, $classifier->classify('I have a problem')?->id, 'trashed: same as switched off');

        $chat->delete();
        $this->assertNull($classifier->classify('hello'));
    }

    public function test_flat_text_still_works_for_code_that_creates_intents_directly(): void
    {
        $intent = AiIntent::query()->create(['key' => 'flat', 'name' => 'Flat', 'description' => 'D', 'is_active' => true]);

        $this->assertSame('Flat', $intent->fresh()->name);
        $this->assertSame('D', $intent->fresh()->description);
        $this->assertTrue($intent->fresh()->is_active);
        $this->assertCount(2, $intent->fresh()->translations, 'written into every stored language');
    }
}
