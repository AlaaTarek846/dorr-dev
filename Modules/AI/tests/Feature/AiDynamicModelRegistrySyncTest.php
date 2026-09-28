<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Enums\AiModelCategory;
use Modules\AI\Models\AiProvider;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\AI\Services\AiProviderModelSyncService;
use Tests\TestCase;

/**
 * Dynamic Model Registry (OpenAI model management rebuild), section 22 -
 * exercises AiProviderModelSyncService::sync() directly against a real
 * database (RefreshDatabase), covering every explicit scenario the spec
 * called out: new model, already-known model, a model that vanished
 * (deprecated, never deleted), a model that came back (reactivated), a
 * dated snapshot, and every category-detection rule that specifically
 * must NOT fall back to "General Chat".
 */
class AiDynamicModelRegistrySyncTest extends TestCase
{
    use RefreshDatabase;

    protected function openAiProvider(): AiProvider
    {
        return app(AiProviderRepository::class)->updateByKey('openai', ['api_key' => 'sk-test-not-real']);
    }

    protected function sync(): AiProviderModelSyncService
    {
        return app(AiProviderModelSyncService::class);
    }

    public function test_a_new_model_id_is_created_and_active(): void
    {
        $provider = $this->openAiProvider();

        $result = $this->sync()->sync($provider, ['gpt-4o']);

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['updated']);

        $row = $provider->models()->where('model_key', 'gpt-4o')->first();
        $this->assertNotNull($row);
        $this->assertTrue($row->is_active);
        $this->assertSame('active', $row->status);
        $this->assertSame(AiModelCategory::General->value, $row->category);
        $this->assertNotNull($row->last_seen_at);
    }

    public function test_an_already_known_model_is_updated_not_duplicated(): void
    {
        $provider = $this->openAiProvider();

        $this->sync()->sync($provider, ['gpt-4o']);
        $result = $this->sync()->sync($provider, ['gpt-4o']);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $provider->models()->where('model_key', 'gpt-4o')->count());
    }

    public function test_a_model_missing_from_the_latest_sync_is_deprecated_never_deleted(): void
    {
        $provider = $this->openAiProvider();

        $this->sync()->sync($provider, ['gpt-4o', 'gpt-4o-mini']);
        $result = $this->sync()->sync($provider, ['gpt-4o']);

        $this->assertSame(1, $result['deprecated']);

        $row = $provider->models()->where('model_key', 'gpt-4o-mini')->first();
        $this->assertNotNull($row, 'a vanished model must still exist in the database - never deleted');
        $this->assertSame('deprecated', $row->status);
        $this->assertFalse($row->is_active);
        $this->assertNotNull($row->deprecated_at);
    }

    public function test_a_deprecated_model_that_reappears_is_reactivated(): void
    {
        $provider = $this->openAiProvider();

        $this->sync()->sync($provider, ['gpt-4o', 'gpt-4o-mini']);
        $this->sync()->sync($provider, ['gpt-4o']); // gpt-4o-mini deprecated here
        $result = $this->sync()->sync($provider, ['gpt-4o', 'gpt-4o-mini']); // reappears

        $this->assertSame(1, $result['reactivated']);

        $row = $provider->models()->where('model_key', 'gpt-4o-mini')->first();
        $this->assertSame('active', $row->status);
        $this->assertTrue($row->is_active);
        $this->assertNull($row->deprecated_at);
    }

    public function test_a_dated_snapshot_id_is_recognized_with_its_canonical_family(): void
    {
        $provider = $this->openAiProvider();

        $this->sync()->sync($provider, ['gpt-5.4-2026-03-05']);

        $row = $provider->models()->where('model_key', 'gpt-5.4-2026-03-05')->first();
        $this->assertTrue($row->is_snapshot);
        $this->assertSame('2026-03-05', $row->release_date->toDateString());
        $this->assertSame('gpt-5.4', $row->canonical_model_id);
        $this->assertSame('gpt-5.4', $row->model_family);
    }

    /**
     * Section 3/4's explicit worked examples - NONE of these may end up
     * category=general just because the id starts with "gpt-".
     */
    public function test_specialized_models_are_never_misclassified_as_general_chat(): void
    {
        $provider = $this->openAiProvider();

        $this->sync()->sync($provider, [
            'gpt-image-2', 'gpt-realtime-2.1', 'gpt-transcribe', 'gpt-4o-transcribe',
            'gpt-5.3-codex', 'sora-2', 'text-embedding-3-large', 'omni-moderation-latest',
        ]);

        $byKey = $provider->models()->get()->keyBy('model_key');

        $this->assertSame(AiModelCategory::ImageGeneration->value, $byKey->get('gpt-image-2')->category);
        $this->assertSame(AiModelCategory::RealtimeVoice->value, $byKey->get('gpt-realtime-2.1')->category);
        $this->assertSame(AiModelCategory::SpeechToText->value, $byKey->get('gpt-transcribe')->category);
        $this->assertSame(AiModelCategory::SpeechToText->value, $byKey->get('gpt-4o-transcribe')->category);
        $this->assertSame(AiModelCategory::Coding->value, $byKey->get('gpt-5.3-codex')->category);
        $this->assertSame(AiModelCategory::VideoGeneration->value, $byKey->get('sora-2')->category);
        $this->assertSame(AiModelCategory::Embeddings->value, $byKey->get('text-embedding-3-large')->category);
        $this->assertSame(AiModelCategory::Moderation->value, $byKey->get('omni-moderation-latest')->category);
    }

    public function test_an_unrecognizable_model_id_is_flagged_unknown_and_needs_review_never_guessed(): void
    {
        $provider = $this->openAiProvider();

        $this->sync()->sync($provider, ['totally-unrecognizable-widget-9000']);

        $row = $provider->models()->where('model_key', 'totally-unrecognizable-widget-9000')->first();
        $this->assertSame(AiModelCategory::Unknown->value, $row->category);
        $this->assertTrue($row->needs_review);
    }

    /**
     * Real, observed gap the user hit directly: a model registered
     * before category/model_family existed as columns (or from an
     * earlier version of this sync service's rules) had category=null
     * forever - clicking "Test Connection" again only ever bumped
     * last_seen_at, never actually classified it. Confirms re-syncing an
     * already-known-but-never-classified row backfills it.
     */
    public function test_re_syncing_backfills_category_for_a_legacy_row_that_was_never_classified(): void
    {
        $provider = $this->openAiProvider();

        // Simulates a row that existed before category/model_family were
        // introduced - created directly, bypassing sync(), with no
        // registry fields set at all.
        $provider->models()->create([
            'model_key' => 'gpt-4o',
            'capabilities' => ['chat'],
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $result = $this->sync()->sync($provider, ['gpt-4o']);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);

        $row = $provider->models()->where('model_key', 'gpt-4o')->first();
        $this->assertSame(AiModelCategory::General->value, $row->category);
        $this->assertSame('gpt-4o', $row->model_family);
    }

    public function test_re_syncing_never_overwrites_a_category_already_assigned(): void
    {
        $provider = $this->openAiProvider();

        $this->sync()->sync($provider, ['gpt-transcribe']);

        // An admin corrects a classification by hand.
        $provider->models()->where('model_key', 'gpt-transcribe')->update([
            'category' => AiModelCategory::General->value,
        ]);

        $this->sync()->sync($provider, ['gpt-transcribe']);

        $row = $provider->models()->where('model_key', 'gpt-transcribe')->first();
        $this->assertSame(AiModelCategory::General->value, $row->category, 'a category the admin already set must never be silently overwritten by a later sync');
    }

    public function test_a_bare_alias_id_is_linked_to_its_dated_sibling(): void
    {
        $provider = $this->openAiProvider();

        $this->sync()->sync($provider, ['gpt-5.6', 'gpt-5.6-sol-2026-08-01']);

        $row = $provider->models()->where('model_key', 'gpt-5.6')->first();
        $this->assertTrue($row->is_alias);
        $this->assertSame('gpt-5.6-sol-2026-08-01', $row->canonical_model_id);
    }

    /**
     * Real gap fixed directly for the admin: embedding models used to be
     * silently dropped from the registry (counted as $skipped, no row at
     * all), even though AiGateway::embed() is a real, already-used
     * connector call (the RAG knowledge pipeline). Now gets a real row
     * with the new "embeddings" capability - never made the default chat
     * model, never active for chat routing, since the knowledge pipeline
     * does not consult this registry (it picks its embedding model from
     * config), but no longer invisible to the admin either.
     */
    public function test_an_embedding_model_is_registered_with_a_real_embeddings_capability(): void
    {
        $provider = $this->openAiProvider();

        $result = $this->sync()->sync($provider, ['text-embedding-3-small']);

        $this->assertSame(1, $result['created']);

        $row = $provider->models()->where('model_key', 'text-embedding-3-small')->first();
        $this->assertSame(AiModelCategory::Embeddings->value, $row->category);
        $this->assertSame(['embeddings'], $row->capabilities);
        $this->assertFalse($row->is_active);
        $this->assertFalse($row->is_default);
        $this->assertFalse($row->temperature_supported);
    }

    /**
     * Real gap fixed directly for the admin: the table used to show a
     * Temperature input for every model, including o-series reasoning
     * models whose real API rejects the parameter outright.
     */
    public function test_reasoning_models_are_registered_as_not_supporting_temperature(): void
    {
        $provider = $this->openAiProvider();

        $this->sync()->sync($provider, ['o3-mini', 'gpt-4o']);

        $this->assertFalse(
            $provider->models()->where('model_key', 'o3-mini')->value('temperature_supported'),
        );
        $this->assertTrue(
            $provider->models()->where('model_key', 'gpt-4o')->value('temperature_supported'),
        );
    }

    /**
     * The tiny, deliberately conservative known-limits lookup must only
     * ever fill in a well-established family's real published figures -
     * never guess for anything not explicitly listed.
     */
    public function test_context_window_and_max_output_tokens_are_filled_for_a_well_known_family_and_left_null_otherwise(): void
    {
        $provider = $this->openAiProvider();

        $this->sync()->sync($provider, ['gpt-4o', 'some-totally-unrecognized-future-model-id']);

        $known = $provider->models()->where('model_key', 'gpt-4o')->first();
        $this->assertSame(128000, $known->context_window);
        $this->assertSame(16384, $known->max_output_tokens);

        $unknown = $provider->models()->where('model_key', 'some-totally-unrecognized-future-model-id')->first();
        $this->assertNull($unknown->context_window);
        $this->assertNull($unknown->max_output_tokens);
    }

    /**
     * Mirrors the category null-guard exactly: normalize()/sync()'s
     * backfill must never silently overwrite a real figure an admin
     * already typed in by hand, even though our own tiny lookup table
     * does not know this particular id.
     */
    public function test_re_syncing_never_overwrites_an_admin_supplied_context_window(): void
    {
        $provider = $this->openAiProvider();

        $this->sync()->sync($provider, ['gpt-4o']);

        $provider->models()->where('model_key', 'gpt-4o')->update(['context_window' => 999999]);

        $this->sync()->sync($provider, ['gpt-4o']);

        $this->assertSame(
            999999,
            $provider->models()->where('model_key', 'gpt-4o')->value('context_window'),
            'an admin-supplied context_window must never be silently overwritten by a later sync',
        );
    }
}
