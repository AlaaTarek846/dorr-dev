<?php

namespace Modules\AI\Tests\Feature;

use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiLanguageEvaluation;
use Modules\AI\Models\AiLanguageVariant;
use Modules\AI\Tests\Feature\Concerns\BuildsAiRoutingScreens;
use Tests\TestCase;

/**
 * AI language evaluations as a catalog like countries: a name per stored language (notes stay untranslated), the
 * result kept in `evaluation_status` next to the catalog's on/off `status`, counts, trash / restore / force delete,
 * ai-language-evaluations.* permissions, and the variant picker that skips trashed variants.
 */
class AiLanguageEvaluationCatalogTest extends TestCase
{
    use BuildsAiRoutingScreens, RefreshDatabase;

    private const BASE = '/api/admin/v1/ai-language-evaluations';

    private Language $arabic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedStoredLanguages();
        $this->arabic = Language::query()->where('code', 'ar')->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return $extra + [
            'language_id' => $this->arabic->id,
            'test_case_count' => 40,
            'pass_rate' => 92.5,
            'evaluation_status' => 'passed',
            'notes' => 'Ran on the new model',
            'status' => true,
            'translations' => [
                ['locale' => 'ar', 'name' => 'اختبار دقة العربية'],
                ['locale' => 'en', 'name' => 'Arabic accuracy suite'],
            ],
        ];
    }

    public function test_admin_creates_an_evaluation_with_a_translated_name_and_untranslated_notes(): void
    {
        $this->actingAsScreenAdmin('ai-language-evaluations');

        $response = $this->postJson(self::BASE, $this->payload(), ['X-Locale' => 'en'])->assertCreated();

        $evaluation = AiLanguageEvaluation::query()->findOrFail($response->json('data.id'));
        $this->assertCount(2, $evaluation->translations);
        $response->assertJsonPath('data.name', 'Arabic accuracy suite')
            ->assertJsonPath('data.evaluation_status', 'passed')
            ->assertJsonPath('data.status', true)
            ->assertJsonPath('data.notes', 'Ran on the new model')
            ->assertJsonPath('data.pass_rate', 92.5);
        $this->assertSame('passed', $evaluation->evaluation_status);

        $this->getJson(self::BASE."/{$evaluation->id}", ['X-Locale' => 'ar'])->assertOk()->assertJsonPath('data.name', 'اختبار دقة العربية');

        $this->postJson(self::BASE, $this->payload(['evaluation_status' => 'done']))->assertUnprocessable()->assertJsonValidationErrors('evaluation_status');
        $payload = $this->payload();
        $payload['translations'] = [['locale' => 'en', 'name' => 'Suite']];
        $this->postJson(self::BASE, $payload)->assertUnprocessable()->assertJsonValidationErrors('translations');
    }

    public function test_status_toggle_counts_search_and_the_result_is_kept(): void
    {
        $this->actingAsScreenAdmin('ai-language-evaluations');
        $id = $this->postJson(self::BASE, $this->payload())->json('data.id');
        $this->postJson(self::BASE, $this->payload(['evaluation_status' => 'failed', 'translations' => [
            ['locale' => 'ar', 'name' => 'اختبار اللهجة'], ['locale' => 'en', 'name' => 'Dialect suite'],
        ]]))->assertCreated();

        $this->patchJson(self::BASE."/{$id}/status", ['status' => false])->assertOk()
            ->assertJsonPath('data.status', false)
            ->assertJsonPath('data.evaluation_status', 'passed');

        $this->getJson(self::BASE.'?status_counts=1')->assertOk()
            ->assertJsonPath('status_counts.total', 2)
            ->assertJsonPath('status_counts.inactive', 1);

        $search = json_encode(['searchKey' => 'Dialect', 'columns' => ['notes'], 'searchInTranslations' => true, 'filterTranslationByLocale' => false]);
        $this->assertSame(['Dialect suite'], collect($this->getJson(self::BASE.'?search='.urlencode($search), ['X-Locale' => 'en'])->json('data'))->pluck('name')->all());
    }

    public function test_trash_restore_force_delete_and_bulk_delete(): void
    {
        $this->actingAsScreenAdmin('ai-language-evaluations');
        $id = $this->postJson(self::BASE, $this->payload())->json('data.id');
        $other = $this->postJson(self::BASE, $this->payload())->json('data.id');

        $this->deleteJson(self::BASE."/{$id}")->assertSuccessful();
        $this->assertSoftDeleted('ai_language_evaluations', ['id' => $id]);
        $this->getJson(self::BASE.'?trashed=1')->assertOk()->assertJsonCount(1, 'data');

        $this->postJson(self::BASE."/{$id}/restore")->assertSuccessful();
        $this->deleteJson(self::BASE."/{$id}/force")->assertStatus(409); // not in the trash
        $this->deleteJson(self::BASE."/{$id}")->assertSuccessful();
        $this->deleteJson(self::BASE."/{$id}/force")->assertSuccessful();
        $this->assertDatabaseMissing('ai_language_evaluations', ['id' => $id]);
        $this->assertDatabaseMissing('ai_language_evaluation_translations', ['ai_language_evaluation_id' => $id]);

        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$other]])->assertSuccessful();
        $this->assertSoftDeleted('ai_language_evaluations', ['id' => $other]);
    }

    public function test_a_trashed_variant_cannot_be_picked_unless_already_used(): void
    {
        $this->actingAsScreenAdmin('ai-language-evaluations');
        $variant = AiLanguageVariant::query()->create(['language_id' => $this->arabic->id, 'code' => 'msa', 'name' => 'MSA', 'is_active' => true]);
        $id = $this->postJson(self::BASE, $this->payload(['variant_id' => $variant->id]))->assertCreated()->json('data.id');

        $variant->delete();

        $this->postJson(self::BASE, $this->payload(['variant_id' => $variant->id]))->assertUnprocessable()->assertJsonValidationErrors('variant_id');
        $this->putJson(self::BASE."/{$id}", $this->payload(['variant_id' => $variant->id]))->assertOk()
            ->assertJsonPath('data.variant.id', $variant->id)
            ->assertJsonPath('data.variant.deleted', true);
    }

    public function test_each_action_needs_its_permission(): void
    {
        $evaluation = AiLanguageEvaluation::query()->create(['name' => 'Suite', 'language_id' => $this->arabic->id, 'is_active' => true]);

        $this->actingAsScreenAdmin('ai-language-evaluations', ['view']);
        $this->getJson(self::BASE)->assertOk()->assertJsonPath('data.0.evaluation_status', 'pending');
        $this->postJson(self::BASE, $this->payload())->assertForbidden();
        $this->putJson(self::BASE."/{$evaluation->id}", $this->payload())->assertForbidden();
        $this->patchJson(self::BASE."/{$evaluation->id}/status", ['status' => false])->assertForbidden();
        $this->deleteJson(self::BASE."/{$evaluation->id}")->assertForbidden();
        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$evaluation->id]])->assertForbidden();

        $this->actingAsScreenAdmin('ai-language-evaluations', ['view', 'update']);
        $this->putJson(self::BASE."/{$evaluation->id}", $this->payload())->assertOk();

        $this->actingAsScreenAdmin('ai-language-evaluations', ['view', 'delete', 'multiple-delete']);
        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$evaluation->id]])->assertSuccessful();
        $this->deleteJson(self::BASE."/{$evaluation->id}/force")->assertSuccessful();

        $this->actingAsScreenAdmin('ai-language-evaluations', []);
        $this->getJson(self::BASE)->assertForbidden();
    }
}
