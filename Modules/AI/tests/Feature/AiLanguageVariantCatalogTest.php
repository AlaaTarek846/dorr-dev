<?php

namespace Modules\AI\Tests\Feature;

use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiLanguageEvaluation;
use Modules\AI\Models\AiLanguageVariant;
use Modules\AI\Models\AiUserLanguagePreference;
use Modules\AI\Services\AiChatLanguageResolver;
use Modules\AI\Tests\Feature\Concerns\BuildsAiRoutingScreens;
use Tests\TestCase;

/**
 * AI language variants as a catalog like countries: a name per stored language (code and style stay technical),
 * status toggle and counts, trash / restore, force delete blocked while a preference or an evaluation uses it,
 * ai-language-variants.* permissions with the evaluations / user-preferences screens allowed to read (dropdown) -
 * and a switched-off or trashed variant is never offered to users nor applied by the chat.
 */
class AiLanguageVariantCatalogTest extends TestCase
{
    use BuildsAiRoutingScreens, RefreshDatabase;

    private const BASE = '/api/admin/v1/ai-language-variants';

    private Language $arabic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedStoredLanguages();
        $this->arabic = Language::query()->where('code', 'ar')->firstOrFail();
        $this->arabic->update(['ai_enabled' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return $extra + [
            'language_id' => $this->arabic->id,
            'code' => 'egyptian_arabic',
            'style' => 'conversational',
            'is_default' => false,
            'status' => true,
            'translations' => [
                ['locale' => 'ar', 'name' => 'اللهجة المصرية'],
                ['locale' => 'en', 'name' => 'Egyptian Arabic'],
            ],
        ];
    }

    private function variant(string $code = 'msa', bool $status = true): AiLanguageVariant
    {
        return AiLanguageVariant::query()->create([
            'language_id' => $this->arabic->id, 'code' => $code, 'name' => 'Variant '.$code, 'style' => 'formal', 'is_active' => $status,
        ]);
    }

    public function test_admin_creates_a_variant_with_a_translation_per_language(): void
    {
        $this->actingAsScreenAdmin('ai-language-variants');

        $response = $this->postJson(self::BASE, $this->payload(), ['X-Locale' => 'en'])->assertCreated();

        $variant = AiLanguageVariant::query()->findOrFail($response->json('data.id'));
        $this->assertCount(2, $variant->translations);
        $this->assertSame('Egyptian Arabic', $response->json('data.name'));
        $this->assertSame('egyptian_arabic', $response->json('data.code'));
        $this->assertTrue($response->json('data.is_active'));
        $this->assertCount(2, $response->json('data.translations'));

        $this->getJson(self::BASE."/{$variant->id}", ['X-Locale' => 'ar'])->assertOk()->assertJsonPath('data.name', 'اللهجة المصرية');

        $payload = $this->payload();
        $payload['translations'] = [['locale' => 'en', 'name' => 'Egyptian']];
        $this->postJson(self::BASE, $payload)->assertUnprocessable()->assertJsonValidationErrors('translations');
    }

    public function test_status_toggle_counts_search_and_one_default_per_language(): void
    {
        $this->actingAsScreenAdmin('ai-language-variants');
        $id = $this->postJson(self::BASE, $this->payload(['is_default' => true]))->json('data.id');
        $second = $this->postJson(self::BASE, $this->payload(['code' => 'saudi', 'is_default' => true, 'translations' => [
            ['locale' => 'ar', 'name' => 'اللهجة السعودية'], ['locale' => 'en', 'name' => 'Saudi Arabic'],
        ]]))->assertCreated()->json('data.id');

        $this->assertFalse(AiLanguageVariant::query()->find($id)->is_default, 'one default per language');
        $this->assertTrue(AiLanguageVariant::query()->find($second)->is_default);

        $this->patchJson(self::BASE."/{$id}/status", ['status' => false])->assertOk()->assertJsonPath('data.status', false);

        $this->getJson(self::BASE.'?status_counts=1')->assertOk()
            ->assertJsonPath('status_counts.total', 2)
            ->assertJsonPath('status_counts.inactive', 1);

        $search = json_encode(['searchKey' => 'Saudi', 'columns' => ['code'], 'searchInTranslations' => true, 'filterTranslationByLocale' => false]);
        $this->assertSame(['Saudi Arabic'], collect($this->getJson(self::BASE.'?search='.urlencode($search), ['X-Locale' => 'en'])->json('data'))->pluck('name')->all());
    }

    public function test_trash_restore_and_force_delete_blocked_while_used(): void
    {
        $this->actingAsScreenAdmin('ai-language-variants');
        $id = $this->postJson(self::BASE, $this->payload())->json('data.id');
        $preference = AiUserLanguagePreference::query()->create([
            'owner_type' => 'user', 'owner_id' => $this->chatOwner()->id, 'language_id' => $this->arabic->id, 'variant_id' => $id,
            'auto_detect' => false, 'response_language_mode' => AiUserLanguagePreference::MODE_FIXED,
        ]);

        $this->deleteJson(self::BASE."/{$id}")->assertSuccessful();
        $this->assertSoftDeleted('ai_language_variants', ['id' => $id]);
        $this->assertSame($id, $preference->fresh()->variant?->id, 'a preference still names its trashed variant');

        $this->deleteJson(self::BASE."/{$id}/force")->assertStatus(409);
        $preference->update(['variant_id' => null]);

        $evaluation = AiLanguageEvaluation::query()->create(['name' => 'Suite', 'language_id' => $this->arabic->id, 'variant_id' => $id]);
        $this->deleteJson(self::BASE."/{$id}/force")->assertStatus(409);
        $evaluation->forceDelete();

        $this->postJson(self::BASE."/{$id}/restore")->assertSuccessful();
        $this->deleteJson(self::BASE."/{$id}")->assertSuccessful();
        $this->deleteJson(self::BASE."/{$id}/force")->assertSuccessful();
        $this->assertDatabaseMissing('ai_language_variants', ['id' => $id]);
        $this->assertDatabaseMissing('ai_language_variant_translations', ['ai_language_variant_id' => $id]);
    }

    public function test_bulk_delete(): void
    {
        $this->actingAsScreenAdmin('ai-language-variants');
        $a = $this->postJson(self::BASE, $this->payload())->json('data.id');
        $b = $this->postJson(self::BASE, $this->payload(['code' => 'other']))->json('data.id');

        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$a, $b]])->assertSuccessful();

        $this->assertSoftDeleted('ai_language_variants', ['id' => $a]);
        $this->assertSoftDeleted('ai_language_variants', ['id' => $b]);
    }

    public function test_each_action_needs_its_permission_and_the_reader_screens_may_read(): void
    {
        $variant = $this->variant('msa', false);
        $this->variant('old')->delete();

        $this->actingAsScreenAdmin('ai-language-variants', ['view']);
        $this->getJson(self::BASE)->assertOk();
        $this->postJson(self::BASE, $this->payload())->assertForbidden();
        $this->putJson(self::BASE."/{$variant->id}", $this->payload())->assertForbidden();
        $this->patchJson(self::BASE."/{$variant->id}/status", ['status' => true])->assertForbidden();
        $this->deleteJson(self::BASE."/{$variant->id}")->assertForbidden();
        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$variant->id]])->assertForbidden();

        $this->actingAsScreenAdmin('ai-language-variants', ['view', 'change-status']);
        $this->patchJson(self::BASE."/{$variant->id}/status", ['status' => false])->assertOk();

        // The evaluations form and the user-preferences screen pick a variant: every one not in the trash.
        foreach (['ai-language-evaluations.view', 'ai-user-language-preferences.view'] as $reader) {
            $this->actingAsScreenAdmin('ai-language-variants', [], [$reader]);
            $this->getJson(self::BASE.'/dropdown', ['X-Locale' => 'en'])->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.code', 'msa')
                ->assertJsonPath('data.0.language_id', $this->arabic->id)
                ->assertJsonPath('data.0.status', false);
            $this->postJson(self::BASE, $this->payload())->assertForbidden();
        }

        $this->actingAsScreenAdmin('ai-language-variants', []);
        $this->getJson(self::BASE)->assertForbidden();
        $this->getJson(self::BASE.'/dropdown')->assertForbidden();
    }

    public function test_a_switched_off_or_trashed_variant_is_not_offered_nor_applied_by_the_chat(): void
    {
        $variant = $this->variant('egyptian_arabic');
        $owner = $this->chatOwner();
        AiUserLanguagePreference::query()->create([
            'owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->id, 'language_id' => $this->arabic->id,
            'variant_id' => $variant->id, 'auto_detect' => false, 'response_language_mode' => AiUserLanguagePreference::MODE_FIXED,
        ]);
        $resolver = app(AiChatLanguageResolver::class);

        $this->assertStringContainsString('Variant egyptian_arabic', $resolver->resolve($owner));

        $variant->update(['status' => false]);
        $this->assertStringNotContainsString('Variant egyptian_arabic', $resolver->resolve($owner), 'switched off');

        $variant->update(['status' => true]);
        $variant->delete();
        $this->assertStringNotContainsString('Variant egyptian_arabic', $resolver->resolve($owner), 'trashed: same as switched off');

        $variant->restore();
        $this->assertStringContainsString('Variant egyptian_arabic', $resolver->resolve($owner));

        // The user's language settings list switched-on variants not in the trash.
        $other = $this->variant('saudi');
        $other->update(['status' => false]);
        $this->actingAs($owner, 'user_api');
        $codes = collect($this->getJson('/api/user/v1/ai-language-preference')->assertOk()->json('data.variants'))->pluck('code')->all();
        $this->assertSame(['egyptian_arabic'], $codes);
    }

    public function test_a_duplicate_code_in_the_same_language_is_a_translated_validation_error_not_a_500(): void
    {
        $this->actingAsScreenAdmin('ai-language-variants');
        $first = $this->postJson(self::BASE, $this->payload())->assertCreated()->json('data.id');

        $this->postJson(self::BASE, $this->payload(), ['X-Locale' => 'en'])->assertUnprocessable()
            ->assertJsonValidationErrors('code')
            ->assertJsonPath('errors.code.0', __('ai.language_variant_code_taken', [], 'en'));

        // The trash keeps the code (the database index covers trashed rows): still refused - restore it instead.
        AiLanguageVariant::query()->findOrFail($first)->delete();
        $this->postJson(self::BASE, $this->payload())->assertUnprocessable()->assertJsonValidationErrors('code');

        // Updating a variant with its own code is fine; taking another one's code is not.
        AiLanguageVariant::withTrashed()->findOrFail($first)->restore();
        $this->putJson(self::BASE."/{$first}", $this->payload())->assertOk();
        $other = $this->variant('saudi');
        $this->putJson(self::BASE."/{$other->id}", ['code' => 'egyptian_arabic'])->assertUnprocessable()->assertJsonValidationErrors('code');

        // SearchFilterTrait: a relation with no columns to search (and no translations) is skipped - it used to
        // match every row that has the relation.
        $search = json_encode(['searchKey' => 'no-such-text', 'columns' => ['code'], 'searchInRelations' => [['relation' => 'language', 'columns' => []]]]);
        $this->assertSame([], $this->getJson(self::BASE.'?search='.urlencode($search))->assertOk()->json('data'));
        $search = json_encode(['searchKey' => 'saud', 'columns' => ['code'], 'searchInRelations' => [['relation' => 'language', 'columns' => []]]]);
        $this->assertSame(['saudi'], collect($this->getJson(self::BASE.'?search='.urlencode($search))->json('data'))->pluck('code')->all());

        // The same code under another language is allowed.
        $english = Language::query()->where('code', 'en')->firstOrFail();
        $this->postJson(self::BASE, $this->payload(['language_id' => $english->id]))->assertCreated();
    }
}
