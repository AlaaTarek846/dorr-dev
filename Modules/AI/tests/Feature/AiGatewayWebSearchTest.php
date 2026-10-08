<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\AI\Services\AiGateway;
use Tests\TestCase;

/**
 * Phase 8 (web search), first real slice: proves AiGateway::chat()'s new
 * $useWebSearch flag actually reaches the live OpenAI request as the real
 * "web_search_options" parameter (verified against OpenAI's current Chat
 * Completions documentation - it is the parameter that endpoint actually
 * reads to perform a live web search, distinct from the Responses API's
 * "web_search" tool type) - not just that the flag exists and is threaded
 * through the call chain. Also proves the flag is a strict opt-in: a plain
 * chat() call defaults to false and never sends it, and the parameter
 * only ever gets added for the OpenAI connector itself - Groq shares the
 * same SendsOpenAiCompatibleChat trait (both talk to a "/chat/completions"
 * endpoint) but does not support web_search_options at all, so sending it
 * there would be silently ignored at best or a real request error at
 * worst, either way exactly the kind of fake support rule 59 forbids.
 */
class AiGatewayWebSearchTest extends TestCase
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

    protected function fakeGroqChatCompletion(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'ok']],
                ],
            ], 200),
        ]);
    }

    public function test_web_search_options_is_sent_to_openai_when_requested(): void
    {
        $repository = app(AiProviderRepository::class);
        $provider = $repository->updateByKey('openai', [
            'is_enabled' => true,
            'api_key' => 'sk-test-not-real',
            'model' => 'gpt-5-search-api',
        ]);

        $this->fakeOpenAiChatCompletion();

        $gateway = app(AiGateway::class);
        $gateway->chat($provider, [['role' => 'user', 'content' => 'سعر الدولار اليوم كام؟']], null, true);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.openai.com/v1/chat/completions'
                && array_key_exists('web_search_options', $request->data());
        });
    }

    public function test_web_search_options_is_not_sent_when_not_requested(): void
    {
        $repository = app(AiProviderRepository::class);
        $provider = $repository->updateByKey('openai', [
            'is_enabled' => true,
            'api_key' => 'sk-test-not-real',
            'model' => 'gpt-4o-mini',
        ]);

        $this->fakeOpenAiChatCompletion();

        $gateway = app(AiGateway::class);
        $gateway->chat($provider, [['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(function ($request) {
            return ! array_key_exists('web_search_options', $request->data());
        });
    }

    public function test_web_search_options_is_never_sent_to_groq_even_when_requested(): void
    {
        $repository = app(AiProviderRepository::class);
        $provider = $repository->updateByKey('groq', [
            'is_enabled' => true,
            'api_key' => 'gsk-test-not-real',
            'model' => 'llama-3.3-70b-versatile',
        ]);

        $this->fakeGroqChatCompletion();

        $gateway = app(AiGateway::class);
        $gateway->chat($provider, [['role' => 'user', 'content' => 'latest news please']], null, true);

        Http::assertSent(function ($request) {
            return ! array_key_exists('web_search_options', $request->data());
        });
    }
}
