<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\AI\Services\AiGateway;
use Tests\TestCase;

/**
 * Business gap fix: temperature/max_tokens used to live only on the
 * provider row - one value shared by every model registered under it,
 * even though different models genuinely need different settings (a
 * reasoning model needing far more max_tokens, a coding model wanting a
 * near-zero temperature). This proves AiGateway::chat() actually reads a
 * per-model override from ai_provider_models and sends IT to the real
 * API - not just that the column exists and can be saved - and that a
 * model with no override, or only a partial one, correctly falls back to
 * the provider's own value for whichever field it did not override.
 */
class AiGatewayModelOverrideTest extends TestCase
{
    use RefreshDatabase;

    protected function fakeOpenAiChatCompletion(): void
    {
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'ok']],
                ],
            ], 200),
        ]);
    }

    public function test_a_models_own_temperature_and_max_tokens_override_the_providers_defaults(): void
    {
        $repository = app(AiProviderRepository::class);
        $provider = $repository->updateByKey('openai', [
            'is_enabled' => true,
            'api_key' => 'sk-test-not-real',
            'model' => 'gpt-4o-mini',
            'temperature' => 0.7,
            'max_tokens' => 500,
        ]);

        $provider->models()->create([
            'model_key' => 'gpt-4o',
            'display_name' => 'GPT-4o',
            'capabilities' => ['chat', 'vision'],
            'temperature' => 0.2,
            'max_tokens' => 4000,
            'is_default' => false,
            'is_active' => true,
        ]);

        $this->fakeOpenAiChatCompletion();

        // Simulates what AiChatService::dispatchWithFallback() does when
        // routing picks a specific registered model: clone the provider
        // and point ->model at it before handing it to the gateway.
        $callProvider = tap(clone $provider, fn ($p) => $p->model = 'gpt-4o');

        $gateway = app(AiGateway::class);
        $gateway->chat($callProvider, [['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.openai.com/v1/chat/completions'
                && $request['model'] === 'gpt-4o'
                && $request['temperature'] === 0.2
                && $request['max_tokens'] === 4000;
        });
    }

    public function test_a_registered_model_with_no_override_keeps_using_the_providers_defaults(): void
    {
        $repository = app(AiProviderRepository::class);
        $provider = $repository->updateByKey('openai', [
            'is_enabled' => true,
            'api_key' => 'sk-test-not-real',
            'model' => 'gpt-4o-mini',
            'temperature' => 0.7,
            'max_tokens' => 500,
        ]);

        $provider->models()->create([
            'model_key' => 'gpt-4o-mini',
            'display_name' => 'GPT-4o mini',
            'capabilities' => ['chat'],
            'temperature' => null,
            'max_tokens' => null,
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->fakeOpenAiChatCompletion();

        $gateway = app(AiGateway::class);
        $gateway->chat($provider, [['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(function ($request) {
            return $request['model'] === 'gpt-4o-mini'
                && $request['temperature'] === 0.7
                && $request['max_tokens'] === 500;
        });
    }

    public function test_a_partial_override_only_replaces_the_field_that_was_actually_set(): void
    {
        $repository = app(AiProviderRepository::class);
        $provider = $repository->updateByKey('openai', [
            'is_enabled' => true,
            'api_key' => 'sk-test-not-real',
            'model' => 'gpt-4o-mini',
            'temperature' => 0.7,
            'max_tokens' => 500,
        ]);

        // Only max_tokens is overridden here - temperature is left blank
        // ("inherit"), so the provider's own 0.7 would normally still be
        // sent. gpt-4o is used here (not a reasoning-tier model) so this
        // test stays about override merging, not about the reasoning-tier
        // parameter rules covered separately below.
        $provider->models()->create([
            'model_key' => 'gpt-4o',
            'display_name' => 'GPT-4o',
            'capabilities' => ['chat', 'vision'],
            'temperature' => null,
            'max_tokens' => 8000,
            'is_default' => false,
            'is_active' => true,
        ]);

        $this->fakeOpenAiChatCompletion();

        $callProvider = tap(clone $provider, fn ($p) => $p->model = 'gpt-4o');

        $gateway = app(AiGateway::class);
        $gateway->chat($callProvider, [['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(function ($request) {
            return $request['model'] === 'gpt-4o'
                && $request['temperature'] === 0.7
                && $request['max_tokens'] === 8000;
        });
    }

    /**
     * Real, observed bug: OpenAI's reasoning-tier models (o1/o3/o4, and
     * the gpt-5 family) reject any `temperature` other than their fixed
     * default of 1 - sending 0.7 (the platform's own default) made every
     * single request to one of these models fail outright with
     * "Unsupported value: 'temperature' does not support 0.7 with this
     * model.". These models also require `max_completion_tokens` instead
     * of the classic `max_tokens` parameter name. This proves both are
     * handled: no `temperature` key at all reaches the request, and
     * `max_tokens` is sent under the renamed key instead.
     */
    public function test_a_reasoning_tier_model_never_receives_a_custom_temperature(): void
    {
        $repository = app(AiProviderRepository::class);
        $provider = $repository->updateByKey('openai', [
            'is_enabled' => true,
            'api_key' => 'sk-test-not-real',
            'model' => 'gpt-4o-mini',
            'temperature' => 0.7,
            'max_tokens' => 500,
        ]);

        $provider->models()->create([
            'model_key' => 'o1-preview',
            'display_name' => 'o1 preview',
            'capabilities' => ['chat', 'reasoning'],
            'temperature' => null,
            'max_tokens' => 8000,
            'is_default' => false,
            'is_active' => true,
        ]);

        $this->fakeOpenAiChatCompletion();

        $callProvider = tap(clone $provider, fn ($p) => $p->model = 'o1-preview');

        $gateway = app(AiGateway::class);
        $gateway->chat($callProvider, [['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(function ($request) {
            return $request['model'] === 'o1-preview'
                && ! array_key_exists('temperature', $request->data())
                && ! array_key_exists('max_tokens', $request->data())
                && $request['max_completion_tokens'] === 8000;
        });
    }

    public function test_a_gpt5_family_model_never_receives_a_custom_temperature(): void
    {
        $repository = app(AiProviderRepository::class);
        $provider = $repository->updateByKey('openai', [
            'is_enabled' => true,
            'api_key' => 'sk-test-not-real',
            'model' => 'gpt-4o-mini',
            'temperature' => 0.9,
            'max_tokens' => null,
        ]);

        $callProvider = tap(clone $provider, fn ($p) => $p->model = 'gpt-5');

        $this->fakeOpenAiChatCompletion();

        $gateway = app(AiGateway::class);
        $gateway->chat($callProvider, [['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(function ($request) {
            return $request['model'] === 'gpt-5'
                && ! array_key_exists('temperature', $request->data());
        });
    }
}
