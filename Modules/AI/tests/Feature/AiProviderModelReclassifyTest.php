<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\Admin\Models\Admin;
use Tests\TestCase;

/**
 * Business gap fix: capability tags are only ever a naming-pattern guess
 * (AiProviderModelSyncService) - the real, documented gpt-5-codex
 * mis-tagging bug came from exactly that. This proves the admin-triggered
 * "reclassify with AI" endpoint actually asks the provider's own AI model
 * to re-judge each active model's capabilities and persists whatever
 * comes back, rather than just re-running the same string heuristic
 * under a new name.
 */
class AiProviderModelReclassifyTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsAdmin(): Admin
    {
        $admin = Admin::query()->create([
            'name' => 'Test Admin',
            'email' => 'reclassify-admin-'.uniqid().'@example.test',
            'password' => 'password',
            'status' => true,
        ]);

        Sanctum::actingAs($admin, ['*'], 'admin_api');

        return $admin;
    }

    public function test_it_persists_the_ai_classifiers_verdict_for_each_active_model(): void
    {
        $this->actingAsAdmin();

        $repository = app(AiProviderRepository::class);
        $provider = $repository->updateByKey('openai', [
            'is_enabled' => true,
            'api_key' => 'sk-test-not-real',
            'model' => 'gpt-4o-mini',
        ]);

        $provider->models()->create([
            'model_key' => 'gpt-4o-mini',
            'display_name' => 'GPT-4o mini',
            'capabilities' => ['chat', 'vision'],
            'is_default' => true,
            'is_active' => true,
        ]);

        $provider->models()->create([
            'model_key' => 'gpt-5-codex',
            'display_name' => 'GPT-5 Codex',
            'capabilities' => ['chat', 'coding', 'vision'], // the exact wrong tag being corrected
            'is_default' => false,
            'is_active' => true,
        ]);

        // A deactivated model must never be sent to the classifier or
        // touched by its response - deactivating is already how an admin
        // takes a model out of consideration.
        $provider->models()->create([
            'model_key' => 'gpt-4-turbo-deprecated',
            'display_name' => 'Old turbo',
            'capabilities' => ['chat'],
            'is_default' => false,
            'is_active' => false,
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'gpt-4o-mini' => ['chat', 'vision'],
                        'gpt-5-codex' => ['chat', 'coding'],
                    ])]],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/admin/v1/ai-providers/openai/models/reclassify");

        $response->assertOk();

        $this->assertSame(
            ['chat', 'coding'],
            $provider->models()->where('model_key', 'gpt-5-codex')->value('capabilities'),
        );
        $this->assertSame(
            ['chat', 'vision'],
            $provider->models()->where('model_key', 'gpt-4o-mini')->value('capabilities'),
        );
        // Untouched, and never sent to the classifier at all.
        $this->assertSame(
            ['chat'],
            $provider->models()->where('model_key', 'gpt-4-turbo-deprecated')->value('capabilities'),
        );

        Http::assertSent(function ($request) {
            $body = $request->data();
            $prompt = $body['messages'][0]['content'] ?? '';

            return str_contains($prompt, 'gpt-4o-mini')
                && str_contains($prompt, 'gpt-5-codex')
                && ! str_contains($prompt, 'gpt-4-turbo-deprecated');
        });
    }

    public function test_it_rejects_when_there_are_no_active_models_to_reclassify(): void
    {
        $this->actingAsAdmin();

        $repository = app(AiProviderRepository::class);
        $repository->updateByKey('openai', [
            'is_enabled' => true,
            'api_key' => 'sk-test-not-real',
            'model' => 'gpt-4o-mini',
        ]);

        $response = $this->postJson("/api/admin/v1/ai-providers/openai/models/reclassify");

        $response->assertStatus(422);
    }

    /**
     * Real, observed bug: the classifier tagged every gpt-image-family /
     * dall-e-family row "chat, vision, image_generation" - plausible from
     * the model name alone ("it handles images, so it must see/produce
     * them conversationally"), but these are dedicated image-generation
     * endpoints OpenAI rejects outright on a chat/completions call
     * ("No available capacity", "only supported in v1/responses", ...).
     * Wrongly tagged this way, AiRoutingEngine::bestModelFor() matched
     * them for ordinary vision AND plain-text chat requests, and enough
     * consecutive failures against the provider tripped the failover
     * cooldown, blocking unrelated requests too. This proves the fix
     * holds even when the AI classifier itself - not just the old
     * heuristic - insists these models are chat/vision capable: the
     * deterministic override always wins for a known image-generation-
     * only model id.
     */
    public function test_an_image_generation_only_model_is_never_tagged_chat_or_vision_even_if_the_classifier_says_so(): void
    {
        $this->actingAsAdmin();

        $repository = app(AiProviderRepository::class);
        $provider = $repository->updateByKey('openai', [
            'is_enabled' => true,
            'api_key' => 'sk-test-not-real',
            'model' => 'gpt-4o-mini',
        ]);

        $provider->models()->create([
            'model_key' => 'gpt-4o-mini',
            'display_name' => 'GPT-4o mini',
            'capabilities' => ['chat', 'vision'],
            'is_default' => true,
            'is_active' => true,
        ]);

        $provider->models()->create([
            'model_key' => 'gpt-image-1',
            'display_name' => 'GPT Image 1',
            'capabilities' => ['image_generation'],
            'is_default' => false,
            'is_active' => true,
        ]);

        // The classifier itself wrongly insists gpt-image-1 can chat and
        // see images - this is the exact real response shape that
        // produced the bug. The fix must override this, not just the
        // absence of a wrong answer.
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'gpt-4o-mini' => ['chat', 'vision'],
                        'gpt-image-1' => ['chat', 'vision', 'image_generation'],
                    ])]],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/admin/v1/ai-providers/openai/models/reclassify");

        $response->assertOk();

        $this->assertSame(
            ['image_generation'],
            $provider->models()->where('model_key', 'gpt-image-1')->value('capabilities'),
        );
        $this->assertSame(
            ['chat', 'vision'],
            $provider->models()->where('model_key', 'gpt-4o-mini')->value('capabilities'),
        );
    }

    /**
     * Root-cause fix - real, observed bug reported by the user: after
     * clicking "Reclassify with AI", gpt-4o-mini came back tagged only
     * "chat, reasoning" - "vision" silently dropped, even though
     * gpt-4o-mini genuinely accepts image input and the naming-pattern
     * sync heuristic already knows the whole gpt-4o family is multimodal.
     * The live effect: AiGateway strips an attached image from the
     * outgoing request whenever the routed model isn't tagged "vision",
     * so every "اشرحلي الصورة دي" message sent to gpt-4o-mini afterward
     * got answered with "I can't view the attached image" - a real,
     * user-visible regression, not just a cosmetic wrong badge. This
     * proves the classifier can never again silently drop "vision" from
     * a model AiProviderModelSyncService::isKnownMultimodalModel()
     * already recognizes, even when the AI's own answer omits it.
     */
    public function test_a_known_multimodal_model_never_loses_vision_even_if_the_classifier_forgets_it(): void
    {
        $this->actingAsAdmin();

        $repository = app(AiProviderRepository::class);
        $provider = $repository->updateByKey('openai', [
            'is_enabled' => true,
            'api_key' => 'sk-test-not-real',
            'model' => 'gpt-4o-mini',
        ]);

        $provider->models()->create([
            'model_key' => 'gpt-4o-mini',
            'display_name' => 'GPT-4o mini',
            'capabilities' => ['chat', 'vision'],
            'is_default' => true,
            'is_active' => true,
        ]);

        // The exact real, observed wrong verdict: "vision" is missing,
        // "reasoning" was hallucinated in its place.
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'gpt-4o-mini' => ['chat', 'reasoning'],
                    ])]],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/admin/v1/ai-providers/openai/models/reclassify");

        $response->assertOk();

        $capabilities = $provider->models()->where('model_key', 'gpt-4o-mini')->value('capabilities');

        $this->assertContains('vision', $capabilities, 'a known multimodal model must never lose vision to a wrong AI self-report');
        $this->assertContains('chat', $capabilities);
        $this->assertContains('reasoning', $capabilities, 'the classifier is still trusted for tags this override has no opinion on');
    }

    /**
     * Root-cause fix - real, observed bug: the user's exact "Reclassify
     * with AI" click reproduced "This is not a chat model and thus not
     * supported in the v1/chat/completions endpoint" every single time.
     * Cause: no model on the provider was ever explicitly marked
     * is_default, and AiProvider::defaultRegisteredModel()'s fallback
     * used to be a bare "first active model, whatever it is" with no
     * capability filter - so the image-generation-only row (registered
     * active but deliberately never is_default by
     * AiProviderModelSyncService), which happened to sort first, got
     * used as "the provider's default chat model" and the classifier
     * genuinely tried to call OpenAI's chat/completions endpoint with it.
     * This proves the fix: with no explicit default and an
     * image-generation-only model sorting before the real chat model,
     * reclassify must still pick and call the real chat-capable model.
     */
    public function test_reclassify_skips_an_image_only_model_and_uses_the_real_chat_model_when_no_default_is_set(): void
    {
        $this->actingAsAdmin();

        $repository = app(AiProviderRepository::class);
        $provider = $repository->updateByKey('openai', [
            'is_enabled' => true,
            'api_key' => 'sk-test-not-real',
            'model' => 'gpt-4o-mini',
        ]);

        // Image-only model created FIRST (so it sorts first by id/creation
        // order) and NEITHER model is marked is_default - exactly the
        // real-world state that triggered the bug.
        $provider->models()->create([
            'model_key' => 'gpt-image-2.5-flare',
            'display_name' => 'GPT Image 2.5 Flare',
            'capabilities' => ['image_generation'],
            'is_default' => false,
            'is_active' => true,
        ]);

        $provider->models()->create([
            'model_key' => 'gpt-4o-mini',
            'display_name' => 'GPT-4o mini',
            'capabilities' => ['chat', 'vision'],
            'is_default' => false,
            'is_active' => true,
        ]);

        $this->assertSame(
            'gpt-4o-mini',
            $provider->defaultRegisteredModel()?->model_key,
            'defaultRegisteredModel() must never resolve to a non-chat-capable model.',
        );

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'gpt-image-2.5-flare' => ['image_generation'],
                        'gpt-4o-mini' => ['chat', 'vision'],
                    ])]],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/admin/v1/ai-providers/openai/models/reclassify");

        $response->assertOk();

        // The real, decisive assertion: the classifier call actually went
        // out against the chat model, never the image-only one.
        Http::assertSent(function ($request) {
            return ($request['model'] ?? null) === 'gpt-4o-mini';
        });
    }
    /**
     * Admin-requested fix (no new field): "Reclassify with AI" must use
     * the provider's own plain `model` column - the one the admin already
     * sets directly on the provider settings card - even when a
     * DIFFERENT model is flagged is_default in the ai_provider_models
     * registry. This is how the admin now steers the classifier call
     * (e.g. away from a reasoning-tier model that rejects a custom
     * temperature) without any second "classifier model" picker to keep
     * in sync: just change the one field that was already there.
     */
    public function test_reclassify_uses_the_providers_plain_model_field_even_when_a_different_model_is_flagged_default(): void
    {
        $this->actingAsAdmin();

        $repository = app(AiProviderRepository::class);
        $provider = $repository->updateByKey('openai', [
            'is_enabled' => true,
            'api_key' => 'sk-test-not-real',
            'model' => 'gpt-4.1-mini',
        ]);

        // Registered as is_default, but NOT what $provider->model points
        // at - the classifier must still prefer the plain `model` field.
        $provider->models()->create([
            'model_key' => 'gpt-4o-mini',
            'display_name' => 'GPT-4o mini',
            'capabilities' => ['chat', 'vision'],
            'is_default' => true,
            'is_active' => true,
        ]);

        $provider->models()->create([
            'model_key' => 'gpt-4.1-mini',
            'display_name' => 'GPT-4.1 mini',
            'capabilities' => ['chat'],
            'is_default' => false,
            'is_active' => true,
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'gpt-4o-mini' => ['chat', 'vision'],
                        'gpt-4.1-mini' => ['chat'],
                    ])]],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/admin/v1/ai-providers/openai/models/reclassify");

        $response->assertOk();

        // The decisive assertion: the classifier call went out against
        // $provider->model, never the is_default registry row.
        Http::assertSent(function ($request) {
            return ($request['model'] ?? null) === 'gpt-4.1-mini';
        });
    }

    /**
     * A provider with no plain `model` set at all (never configured)
     * must still fall back to the registry's default registered model,
     * exactly as before this change - only ever a gap for a genuinely
     * unconfigured provider, not the normal case.
     */
    public function test_reclassify_falls_back_to_the_registered_default_model_when_the_providers_plain_model_field_is_empty(): void
    {
        $this->actingAsAdmin();

        $repository = app(AiProviderRepository::class);
        $provider = $repository->updateByKey('openai', [
            'is_enabled' => true,
            'api_key' => 'sk-test-not-real',
            'model' => null,
        ]);

        $provider->models()->create([
            'model_key' => 'gpt-4o-mini',
            'display_name' => 'GPT-4o mini',
            'capabilities' => ['chat', 'vision'],
            'is_default' => true,
            'is_active' => true,
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'gpt-4o-mini' => ['chat', 'vision'],
                    ])]],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/admin/v1/ai-providers/openai/models/reclassify");

        $response->assertOk();

        Http::assertSent(function ($request) {
            return ($request['model'] ?? null) === 'gpt-4o-mini';
        });
    }
}
