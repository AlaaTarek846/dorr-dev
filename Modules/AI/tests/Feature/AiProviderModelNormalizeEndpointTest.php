<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\AI\Enums\AiModelCategory;
use Modules\AI\Repositories\AiProviderRepository;
use Tests\TestCase;

/**
 * Dynamic Model Registry, real gap the user reported directly: the admin
 * screen kept showing Sora/Codex/embedding/moderation models stuck under
 * "General Chat" no matter how many times "Test Connection" was clicked.
 * Root cause: AiProviderModelSyncService::sync()'s already-known branch
 * only ever backfills `category` when it is still NULL, specifically to
 * protect a category an admin corrected by hand - but a row registered
 * under an EARLIER, less accurate version of inferCategory() (before the
 * Sora/Codex/Embeddings/Moderation rules existed) already has a category
 * value, just a wrong one, so that protection silently froze it forever.
 * This is the escape hatch: POST {provider}/models/normalize makes no
 * provider API call at all and unconditionally recomputes category
 * (plus model_family/snapshot/alias) for every already-registered row
 * against the CURRENT rules - the only action able to correct an
 * already-set-but-wrong category.
 */
class AiProviderModelNormalizeEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsAdmin(): Admin
    {
        $admin = Admin::query()->create([
            'name' => 'Normalize Endpoint Admin',
            'email' => 'normalize-endpoint-'.uniqid().'@example.test',
            'password' => 'password',
            'status' => true,
        ]);

        Sanctum::actingAs($admin, ['*'], 'admin_api');

        return $admin;
    }

    public function test_it_recategorizes_stale_rows_frozen_under_an_old_wrong_category_with_no_provider_call(): void
    {
        $this->actingAsAdmin();

        $provider = app(AiProviderRepository::class)->updateByKey('openai', [
            'is_enabled' => true,
            'api_key' => 'sk-test-not-real',
        ]);

        // The exact real-world state the user described: registered a
        // while ago, before today's category rules existed, so `category`
        // is not NULL - it is General, just wrong.
        $provider->models()->create([
            'model_key' => 'sora-2',
            'display_name' => 'Sora 2',
            'capabilities' => [],
            'category' => AiModelCategory::General->value,
            'is_default' => false,
            'is_active' => true,
        ]);

        $provider->models()->create([
            'model_key' => 'gpt-5.3-codex',
            'display_name' => 'GPT-5.3 Codex',
            'capabilities' => ['chat', 'coding'],
            'category' => AiModelCategory::General->value,
            'is_default' => false,
            'is_active' => true,
        ]);

        $provider->models()->create([
            'model_key' => 'text-embedding-3-large',
            'display_name' => 'Text Embedding 3 Large',
            'capabilities' => [],
            'category' => null,
            'is_default' => false,
            'is_active' => true,
        ]);

        // A genuinely correct, admin-confirmed category must survive
        // untouched - normalize() recomputing the SAME value it already
        // has is not "clobbering", but this proves it never regresses to
        // something else either.
        $provider->models()->create([
            'model_key' => 'gpt-4o-mini',
            'display_name' => 'GPT-4o mini',
            'capabilities' => ['chat', 'vision'],
            'category' => AiModelCategory::General->value,
            'is_default' => true,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/admin/v1/ai-providers/openai/models/normalize');

        $response->assertOk();
        $response->assertJsonPath('data.summary.updated', 4);
        $response->assertJsonPath('data.summary.recategorized', 3); // sora-2, gpt-5.3-codex, text-embedding-3-large

        $this->assertSame(
            AiModelCategory::VideoGeneration->value,
            $provider->models()->where('model_key', 'sora-2')->value('category'),
        );
        $this->assertSame(
            AiModelCategory::Coding->value,
            $provider->models()->where('model_key', 'gpt-5.3-codex')->value('category'),
        );
        $this->assertSame(
            AiModelCategory::Embeddings->value,
            $provider->models()->where('model_key', 'text-embedding-3-large')->value('category'),
        );
        $this->assertSame(
            AiModelCategory::General->value,
            $provider->models()->where('model_key', 'gpt-4o-mini')->value('category'),
        );

        // Deliberately untouched by this action.
        $this->assertSame(
            ['chat', 'coding'],
            $provider->models()->where('model_key', 'gpt-5.3-codex')->value('capabilities'),
        );
        $this->assertTrue((bool) $provider->models()->where('model_key', 'gpt-4o-mini')->value('is_default'));
    }

    public function test_a_genuinely_unrecognizable_model_id_is_marked_unknown_and_needs_review_not_guessed(): void
    {
        $this->actingAsAdmin();

        $provider = app(AiProviderRepository::class)->updateByKey('openai', [
            'is_enabled' => true,
            'api_key' => 'sk-test-not-real',
        ]);

        $provider->models()->create([
            'model_key' => 'totally-unrecognizable-future-id',
            'display_name' => 'Mystery model',
            'capabilities' => [],
            'category' => AiModelCategory::General->value,
            'is_default' => false,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/admin/v1/ai-providers/openai/models/normalize');

        $response->assertOk();

        $row = $provider->models()->where('model_key', 'totally-unrecognizable-future-id')->first();
        $this->assertSame(AiModelCategory::Unknown->value, $row->category);
        $this->assertTrue((bool) $row->needs_review);
    }

    /**
     * normalize() extends beyond category: it also backfills
     * temperature_supported/context_window/max_output_tokens for every
     * existing row - but, unlike category (100% rule-derived, always
     * safe to recompute), these three can be corrected by an admin by
     * hand, so they must only ever be filled in while still null, never
     * overwritten once set.
     */
    public function test_it_backfills_temperature_support_and_known_limits_without_overwriting_admin_values(): void
    {
        $this->actingAsAdmin();

        $provider = app(AiProviderRepository::class)->updateByKey('openai', [
            'is_enabled' => true,
            'api_key' => 'sk-test-not-real',
        ]);

        // Registered before these fields existed - all three genuinely null.
        $provider->models()->create([
            'model_key' => 'gpt-4o',
            'display_name' => 'GPT-4o',
            'capabilities' => ['chat', 'vision'],
            'category' => AiModelCategory::General->value,
            'temperature_supported' => null,
            'context_window' => null,
            'max_output_tokens' => null,
            'is_default' => true,
            'is_active' => true,
        ]);

        // An admin already corrected this one by hand - must survive untouched.
        $provider->models()->create([
            'model_key' => 'o3-mini',
            'display_name' => 'O3 Mini',
            'capabilities' => ['chat', 'reasoning'],
            'category' => AiModelCategory::Reasoning->value,
            'temperature_supported' => true, // admin insists it works for them
            'context_window' => 500000, // admin's own real figure
            'max_output_tokens' => null,
            'is_default' => false,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/admin/v1/ai-providers/openai/models/normalize');
        $response->assertOk();

        $gpt4o = $provider->models()->where('model_key', 'gpt-4o')->first();
        $this->assertTrue((bool) $gpt4o->temperature_supported);
        $this->assertSame(128000, $gpt4o->context_window);
        $this->assertSame(16384, $gpt4o->max_output_tokens);

        $o3mini = $provider->models()->where('model_key', 'o3-mini')->first();
        $this->assertTrue((bool) $o3mini->temperature_supported, 'admin override must survive even though the deterministic rule would say false');
        $this->assertSame(500000, $o3mini->context_window, 'admin-supplied context_window must never be overwritten');
        // max_output_tokens WAS null, and o3-mini IS in the tiny known-limits
        // table (a genuinely well-established, long-documented family), so
        // it gets filled in - independently of context_window, which was
        // already set and correctly left untouched above.
        $this->assertSame(100000, $o3mini->max_output_tokens);
    }

    public function test_legacy_completions_era_models_are_categorized_legacy_not_general(): void
    {
        $this->actingAsAdmin();

        $provider = app(AiProviderRepository::class)->updateByKey('openai', [
            'is_enabled' => true,
            'api_key' => 'sk-test-not-real',
        ]);

        foreach (['davinci-002', 'babbage-002', 'gpt-3.5-turbo', 'gpt-4-0613'] as $modelKey) {
            $provider->models()->create([
                'model_key' => $modelKey,
                'display_name' => $modelKey,
                'capabilities' => ['chat'],
                'category' => AiModelCategory::General->value,
                'is_default' => false,
                'is_active' => true,
            ]);
        }

        $response = $this->postJson('/api/admin/v1/ai-providers/openai/models/normalize');

        $response->assertOk();

        foreach (['davinci-002', 'babbage-002', 'gpt-3.5-turbo', 'gpt-4-0613'] as $modelKey) {
            $this->assertSame(
                AiModelCategory::Legacy->value,
                $provider->models()->where('model_key', $modelKey)->value('category'),
                "{$modelKey} must be categorized Legacy, not General."
            );
        }
    }
}
