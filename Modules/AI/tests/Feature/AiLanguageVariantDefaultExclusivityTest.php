<?php

namespace Modules\AI\Tests\Feature;

use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Models\AiLanguageVariant;
use Modules\Admin\Models\Admin;
use Tests\TestCase;

/**
 * Business gap fix (admin dashboard review, 27 Sep): nothing enforced "only
 * one default variant per language" - AiLanguageVariantService just
 * delegated straight to the generic BaseService, so setting is_default on
 * a second variant of the same language silently left both rows
 * is_default=true, with no defined winner. AiLanguageVariantService::store()/
 * update() now unset every other variant of the same language first, the
 * same way AiProviderModelService already guarantees one default model per
 * provider.
 */
class AiLanguageVariantDefaultExclusivityTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsAdmin(): Admin
    {
        $admin = Admin::query()->create([
            'name' => 'Test Admin',
            'email' => 'variant-admin-'.uniqid().'@example.test',
            'password' => 'password',
            'status' => true,
        ]);

        Sanctum::actingAs($admin, ['*'], 'admin_api');

        return $admin;
    }

    // Root-cause fix (languages consolidation): variants now belong to the
    // platform's general Language model, which requires a flag_id.
    protected function makeLanguage(): Language
    {
        $flag = Flag::query()->create(['code' => 'xx-'.uniqid(), 'status' => true]);

        return Language::query()->create([
            'code' => 'ar-'.uniqid(),
            'direction' => 'rtl',
            'is_default_website' => false,
            'is_default_dashboard' => false,
            'stores_translation' => false,
            'status' => true,
            'ai_enabled' => true,
            'flag_id' => $flag->id,
        ]);
    }

    public function test_creating_a_new_default_variant_unsets_the_previous_default_for_the_same_language(): void
    {
        $this->actingAsAdmin();

        $language = $this->makeLanguage();

        $existingDefault = AiLanguageVariant::query()->create([
            'language_id' => $language->id,
            'code' => 'ar-EG',
            'name' => 'Egyptian Arabic',
            'style' => 'conversational',
            'is_default' => true,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/admin/v1/ai-language-variants', [
            'language_id' => $language->id,
            'code' => 'ar-SA',
            'name' => 'Saudi Arabic',
            'style' => 'formal',
            'is_default' => true,
            'is_active' => true,
        ]);

        $response->assertCreated();

        $this->assertFalse($existingDefault->fresh()->is_default);
        $this->assertTrue(AiLanguageVariant::query()->where('code', 'ar-SA')->value('is_default'));
    }

    public function test_updating_a_variant_to_default_unsets_every_other_default_for_the_same_language(): void
    {
        $this->actingAsAdmin();

        $language = $this->makeLanguage();

        $first = AiLanguageVariant::query()->create([
            'language_id' => $language->id,
            'code' => 'ar-EG',
            'name' => 'Egyptian Arabic',
            'style' => 'conversational',
            'is_default' => true,
            'is_active' => true,
        ]);

        $second = AiLanguageVariant::query()->create([
            'language_id' => $language->id,
            'code' => 'ar-SA',
            'name' => 'Saudi Arabic',
            'style' => 'formal',
            'is_default' => false,
            'is_active' => true,
        ]);

        $response = $this->putJson("/api/admin/v1/ai-language-variants/{$second->id}", [
            'is_default' => true,
        ]);

        $response->assertOk();

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
    }

    public function test_a_default_variant_of_a_different_language_is_never_touched(): void
    {
        $this->actingAsAdmin();

        $languageOne = $this->makeLanguage();
        $languageTwo = $this->makeLanguage();

        $unrelatedDefault = AiLanguageVariant::query()->create([
            'language_id' => $languageTwo->id,
            'code' => 'fr-FR',
            'name' => 'French',
            'style' => 'formal',
            'is_default' => true,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/admin/v1/ai-language-variants', [
            'language_id' => $languageOne->id,
            'code' => 'ar-EG',
            'name' => 'Egyptian Arabic',
            'style' => 'conversational',
            'is_default' => true,
            'is_active' => true,
        ]);

        $response->assertCreated();

        $this->assertTrue($unrelatedDefault->fresh()->is_default);
    }
}
