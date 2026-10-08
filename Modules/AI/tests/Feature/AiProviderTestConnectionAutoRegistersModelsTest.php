<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\AI\Models\AiProvider;
use Modules\AI\Repositories\AiProviderRepository;
use Tests\TestCase;

/**
 * Business gap fix: "test connection" already called the provider's real
 * /models API and cached the flat id list (AiProvider::available_models),
 * but the "connected models" section (ai_provider_models) stayed empty
 * until the admin re-typed every model by hand. This proves a successful
 * test now registers the real, chat-capable models automatically - and
 * that it stays safe to click "test" repeatedly (no duplicates, and a
 * model the admin deliberately deactivated is never silently reactivated
 * or duplicated).
 */
class AiProviderTestConnectionAutoRegistersModelsTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsAdmin(): Admin
    {
        $admin = Admin::query()->create([
            'name' => 'Sync Test Admin',
            'email' => 'sync-admin-'.uniqid().'@example.test',
            'password' => 'password',
            'status' => true,
        ]);

        Sanctum::actingAs($admin, ['*'], 'admin_api');

        return $admin;
    }

    protected function fakeOpenAiModelsList(array $ids): void
    {
        Http::fake([
            'api.openai.com/v1/models' => Http::response([
                'data' => array_map(fn (string $id) => ['id' => $id], $ids),
            ], 200),
        ]);
    }

    public function test_a_successful_test_registers_chat_capable_models_and_skips_the_rest(): void
    {
        $this->actingAsAdmin();

        app(AiProviderRepository::class)->updateByKey('openai', ['api_key' => 'sk-test-not-real']);

        $this->fakeOpenAiModelsList([
            'gpt-4o', 'gpt-4o-mini', 'o1-preview',
            'whisper-1', 'text-embedding-3-small', 'dall-e-3',
        ]);

        $response = $this->postJson('/api/admin/v1/ai-providers/openai/test');

        $response->assertOk();

        $registered = $response->json('data.registered_models');
        // Dynamic model registry update: EVERY id the provider actually
        // returned is now registered as a real row (so it shows up in the
        // admin screen with its real category, instead of some models
        // silently vanishing) - the 3 chat-capable models, dall-e-3
        // (image_generation-only, never chat), whisper-1
        // (speech_to_text - AiGateway::transcribeAudio() now has a real
        // implementation), and text-embedding-3-small (embeddings - no
        // connector call implemented for this category yet, so it is
        // registered but INACTIVE with no capabilities, never selectable
        // by routing). All 6 - none dropped, none guessed.
        $this->assertCount(6, $registered, 'every id the provider returned should be registered, each with its own capabilities/activation');

        $byKey = collect($registered)->keyBy('model_key');
        $this->assertTrue($byKey->has('gpt-4o'));
        $this->assertTrue($byKey->has('gpt-4o-mini'));
        $this->assertTrue($byKey->has('o1-preview'));
        $this->assertTrue($byKey->has('whisper-1'));
        $this->assertTrue($byKey->has('text-embedding-3-small'));
        $this->assertTrue($byKey->has('dall-e-3'));

        $this->assertContains('vision', $byKey->get('gpt-4o')['capabilities']);
        $this->assertContains('reasoning', $byKey->get('o1-preview')['capabilities']);
        $this->assertSame(['image_generation'], $byKey->get('dall-e-3')['capabilities']);
        $this->assertFalse($byKey->get('dall-e-3')['is_default'], 'an image-only model must never become the chat default');

        $this->assertSame(['speech_to_text'], $byKey->get('whisper-1')['capabilities']);
        $this->assertTrue($byKey->get('whisper-1')['is_active'], 'a speech-to-text model has real connector support and should be usable right away');

        $this->assertSame(['embeddings'], $byKey->get('text-embedding-3-small')['capabilities'], 'AiGateway::embed() is a real embeddings call now (used by knowledge indexing), so the capability is registered');
        $this->assertFalse($byKey->get('text-embedding-3-small')['is_active'], 'a category with no real capability implementation must never be auto-activated');

        // Exactly one auto-registered model should end up flagged default,
        // since none existed before this sync.
        $this->assertSame(1, $byKey->filter(fn ($row) => $row['is_default'])->count());
    }

    public function test_retesting_never_duplicates_or_resurrects_a_deactivated_model(): void
    {
        $this->actingAsAdmin();

        app(AiProviderRepository::class)->updateByKey('openai', ['api_key' => 'sk-test-not-real']);

        // Http::fake() does not override an existing stub for the same URL
        // within one test - the first-registered match always wins - so a
        // "the upstream model list changes between two test-connection
        // clicks" scenario needs a real fakeSequence() to return a
        // different response on each successive call.
        Http::fakeSequence('api.openai.com/v1/models')
            ->push(['data' => [['id' => 'gpt-4o'], ['id' => 'gpt-4o-mini']]])
            ->push(['data' => [['id' => 'gpt-4o'], ['id' => 'gpt-4o-mini'], ['id' => 'o1-preview']]]);

        $this->postJson('/api/admin/v1/ai-providers/openai/test')->assertOk();

        $provider = AiProvider::query()->where('key', 'openai')->firstOrFail();
        $this->assertCount(2, $provider->models);

        // The admin deliberately turns one model off.
        $provider->models()->where('model_key', 'gpt-4o-mini')->update(['is_active' => false]);

        // A new model shows up upstream on the next test; the deactivated
        // one is still present in the provider's real model list.
        $response = $this->postJson('/api/admin/v1/ai-providers/openai/test');
        $response->assertOk();

        $registered = collect($response->json('data.registered_models'))->keyBy('model_key');

        $this->assertCount(3, $registered, 'gpt-4o and gpt-4o-mini must not be duplicated, and o1-preview must be added');
        $this->assertFalse($registered->get('gpt-4o-mini')['is_active'], 'a deliberately deactivated model must stay inactive, not be silently re-enabled');
    }
}
