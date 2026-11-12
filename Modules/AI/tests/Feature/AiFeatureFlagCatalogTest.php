<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiFeatureFlag;
use Modules\AI\Services\AiFeatureFlagGate;
use Modules\AI\Tests\Feature\Concerns\BuildsAiRoutingScreens;
use Tests\TestCase;

/**
 * AI feature flags as a catalog like countries: a description per stored language (key and scope stay technical),
 * status (the old is_enabled) toggle and counts, trash / restore / force delete, ai-feature-flags.* permissions -
 * and the kill switch: status off turns its target off at once, a trashed flag is never applied.
 */
class AiFeatureFlagCatalogTest extends TestCase
{
    use BuildsAiRoutingScreens, RefreshDatabase;

    private const BASE = '/api/admin/v1/ai-feature-flags';

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
            'key' => 'ai-chat-off-sa',
            'target_type' => AiFeatureFlag::TARGET_SERVICE,
            'tool_key' => AiFeatureFlag::SERVICE_AI_CHAT,
            'country_code' => 'sa',
            'environment' => config('app.env'),
            'status' => false,
            'translations' => [
                ['locale' => 'ar', 'description' => 'قفل شات الذكاء الاصطناعي في السعودية'],
                ['locale' => 'en', 'description' => 'AI chat off in Saudi Arabia'],
            ],
        ];
    }

    public function test_admin_creates_a_flag_with_a_description_per_language(): void
    {
        $this->actingAsScreenAdmin('ai-feature-flags');

        $response = $this->postJson(self::BASE, $this->payload(), ['X-Locale' => 'en'])->assertCreated();

        $flag = AiFeatureFlag::query()->findOrFail($response->json('data.id'));
        $this->assertCount(2, $flag->translations);
        $this->assertSame('AI chat off in Saudi Arabia', $response->json('data.description'));
        $this->assertSame('SA', $response->json('data.country_code'), 'the country code is stored upper case, as the apps send it');
        $this->assertSame('ai-chat-off-sa', $response->json('data.key'), 'the key is technical - never translated');
        $this->assertFalse($response->json('data.status'));
        $this->assertFalse($response->json('data.is_enabled'), 'the old key stays in the output');
        $this->assertCount(2, $response->json('data.translations'));

        $this->getJson(self::BASE."/{$flag->id}", ['X-Locale' => 'ar'])->assertOk()
            ->assertJsonPath('data.description', 'قفل شات الذكاء الاصطناعي في السعودية');
    }

    public function test_a_flag_needs_a_known_target_and_older_callers_may_still_send_is_enabled(): void
    {
        $this->actingAsScreenAdmin('ai-feature-flags');

        $this->postJson(self::BASE, $this->payload(['target_type' => 'nope']))->assertUnprocessable()->assertJsonValidationErrors('target_type');

        $payload = $this->payload(['is_enabled' => true]);
        unset($payload['status'], $payload['translations']);
        $id = $this->postJson(self::BASE, $payload)->assertCreated()->assertJsonPath('data.status', true)->json('data.id');

        $this->putJson(self::BASE."/{$id}", ['is_enabled' => false])->assertOk()->assertJsonPath('data.is_enabled', false);
        $this->assertFalse(AiFeatureFlag::query()->findOrFail($id)->is_enabled);
    }

    public function test_status_toggle_counts_and_search(): void
    {
        $this->actingAsScreenAdmin('ai-feature-flags');
        $id = $this->postJson(self::BASE, $this->payload())->json('data.id');
        $this->postJson(self::BASE, $this->payload(['key' => 'sandbox-off', 'target_type' => 'tool', 'tool_key' => 'code_sandbox', 'translations' => [
            ['locale' => 'ar', 'description' => 'إيقاف تشغيل الكود'], ['locale' => 'en', 'description' => 'Code sandbox paused'],
        ]]))->assertCreated();

        $this->patchJson(self::BASE."/{$id}/status", ['status' => true])->assertOk()->assertJsonPath('data.status', true);

        $this->getJson(self::BASE.'?status_counts=1')->assertOk()
            ->assertJsonPath('status_counts.total', 2)
            ->assertJsonPath('status_counts.active', 1)
            ->assertJsonPath('status_counts.inactive', 1);

        $search = json_encode(['searchKey' => 'sandbox paused', 'columns' => ['key'], 'searchInTranslations' => true, 'filterTranslationByLocale' => false]);
        $this->assertSame(['sandbox-off'], collect($this->getJson(self::BASE.'?search='.urlencode($search))->json('data'))->pluck('key')->all());
    }

    public function test_trash_restore_force_delete_and_bulk_delete(): void
    {
        $this->actingAsScreenAdmin('ai-feature-flags');
        $id = $this->postJson(self::BASE, $this->payload())->json('data.id');
        $other = $this->postJson(self::BASE, $this->payload(['key' => 'other']))->json('data.id');

        $this->deleteJson(self::BASE."/{$id}")->assertSuccessful();
        $this->assertSoftDeleted('ai_feature_flags', ['id' => $id]);
        $this->getJson(self::BASE.'?trashed=1')->assertOk()->assertJsonPath('data.0.id', $id);

        $this->postJson(self::BASE."/{$id}/restore")->assertSuccessful();
        $this->assertNotSoftDeleted('ai_feature_flags', ['id' => $id]);

        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$id, $other]])->assertSuccessful();
        $this->assertSoftDeleted('ai_feature_flags', ['id' => $other]);

        // Nothing points at a flag: deleting it for good is always allowed.
        $this->deleteJson(self::BASE."/{$id}/force")->assertSuccessful();
        $this->assertDatabaseMissing('ai_feature_flags', ['id' => $id]);
        $this->assertDatabaseMissing('ai_feature_flag_translations', ['ai_feature_flag_id' => $id]);
    }

    public function test_each_action_needs_its_permission(): void
    {
        $flag = AiFeatureFlag::query()->create(['key' => 'k', 'target_type' => 'tool', 'tool_key' => 't', 'is_enabled' => true]);

        $this->actingAsScreenAdmin('ai-feature-flags', ['view']);
        $this->getJson(self::BASE)->assertOk();
        $this->getJson(self::BASE."/{$flag->id}")->assertOk();
        $this->postJson(self::BASE, $this->payload())->assertForbidden();
        $this->putJson(self::BASE."/{$flag->id}", ['key' => 'x'])->assertForbidden();
        $this->patchJson(self::BASE."/{$flag->id}/status", ['status' => false])->assertForbidden();
        $this->deleteJson(self::BASE."/{$flag->id}")->assertForbidden();
        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$flag->id]])->assertForbidden();

        $this->actingAsScreenAdmin('ai-feature-flags', ['view', 'change-status']);
        $this->patchJson(self::BASE."/{$flag->id}/status", ['status' => false])->assertOk();

        $this->actingAsScreenAdmin('ai-feature-flags', ['view', 'create', 'update', 'delete', 'multiple-delete']);
        $this->postJson(self::BASE, $this->payload())->assertCreated();
        $this->putJson(self::BASE."/{$flag->id}", ['key' => 'renamed'])->assertOk();
        $this->deleteJson(self::BASE."/{$flag->id}")->assertSuccessful();

        $this->actingAsScreenAdmin('ai-feature-flags', []);
        $this->getJson(self::BASE)->assertForbidden();
    }

    public function test_status_off_is_the_kill_switch_at_once_and_a_trashed_flag_is_never_applied(): void
    {
        $this->actingAsScreenAdmin('ai-feature-flags');
        $gate = app(AiFeatureFlagGate::class);

        $this->assertTrue($gate->isServiceAllowed(AiFeatureFlag::SERVICE_AI_CHAT, 'SA'), 'no flag = allowed');

        $id = $this->postJson(self::BASE, $this->payload())->json('data.id');
        $this->assertFalse($gate->isServiceAllowed(AiFeatureFlag::SERVICE_AI_CHAT, 'SA'), 'status off = the chat is off in SA');
        $this->assertTrue($gate->isServiceAllowed(AiFeatureFlag::SERVICE_AI_CHAT, 'EG'), 'other countries are not touched');

        // Each change clears the gate's cache: it applies on the next message, not 30 seconds later.
        $this->patchJson(self::BASE."/{$id}/status", ['status' => true])->assertOk();
        $this->assertTrue($gate->isServiceAllowed(AiFeatureFlag::SERVICE_AI_CHAT, 'SA'), 'status on = the flag does nothing');

        $this->patchJson(self::BASE."/{$id}/status", ['status' => false])->assertOk();
        $this->assertFalse($gate->isServiceAllowed(AiFeatureFlag::SERVICE_AI_CHAT, 'SA'));

        $this->deleteJson(self::BASE."/{$id}")->assertSuccessful();
        $this->assertTrue($gate->isServiceAllowed(AiFeatureFlag::SERVICE_AI_CHAT, 'SA'), 'trashed = never applied (as deleted)');

        $this->postJson(self::BASE."/{$id}/restore")->assertSuccessful();
        $this->assertFalse($gate->isServiceAllowed(AiFeatureFlag::SERVICE_AI_CHAT, 'SA'), 'restored = the kill switch is back');
    }
}
