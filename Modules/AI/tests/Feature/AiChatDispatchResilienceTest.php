<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiFailover;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\AiRequest;
use Modules\AI\Services\AiChatService;
use Modules\AI\Services\AiGateway;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * v2.0 requirements doc S20.3 (failure/chaos tests for critical paths).
 *
 * A real gap this test caught: AiGateway::connectorFor() throws a plain
 * RuntimeException for a provider whose `key` does not resolve to a
 * known connector (e.g. a misconfigured/legacy row), and
 * AiChatService::dispatchWithFallback() called AiGateway::chat()
 * without a try/catch around it. Every OTHER failure mode (a bad HTTP
 * response, a network timeout) is already normalized into a
 * {success: false, ...} array deep inside each connector's own
 * try/catch (AbstractHttpConnector::attempt()) - only this one throw
 * site was unguarded, and it would have taken down the entire
 * sendMessage() request with an uncaught exception (a raw 500 to the
 * user) instead of failing over to the next candidate provider like
 * every other failure does. This test exercises that exact path and
 * would have failed before the try/catch was added around the
 * dispatch call.
 */
class AiChatDispatchResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function makeProvider(string $key = 'openai'): AiProvider
    {
        return AiProvider::query()->create([
            'key' => $key,
            'name' => $key.' (test)',
            'is_enabled' => true,
            'is_default' => $key === 'openai',
            'api_key' => 'test-key-not-real',
            'model' => 'gpt-4o-mini',
        ]);
    }

    protected function invokeDispatch(array $candidates, bool $fallbackEnabled, AiRequest $aiRequest): array
    {
        $method = new ReflectionMethod(AiChatService::class, 'dispatchWithFallback');
        $method->setAccessible(true);

        return $method->invoke(app(AiChatService::class), $candidates, $fallbackEnabled, [], $aiRequest);
    }

    public function test_a_throwing_gateway_call_fails_over_to_the_next_candidate_instead_of_crashing_the_request(): void
    {
        $primary = $this->makeProvider('openai');
        $fallback = $this->makeProvider('anthropic');

        $this->mock(AiGateway::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->andThrow(new RuntimeException('Unsupported AI provider [openai].'));

            $mock->shouldReceive('chat')
                ->once()
                ->andReturn(['success' => true, 'message' => '', 'content' => 'answer from fallback']);
        });

        $aiRequest = AiRequest::query()->create([
            'owner_type' => 'Modules\\Admin\\Models\\Admin',
            'owner_id' => 1,
            'status' => AiRequest::STATUS_PROCESSING,
        ]);

        [$result, $usedProvider] = $this->invokeDispatch([
            ['provider' => $primary, 'model_key' => null],
            ['provider' => $fallback, 'model_key' => null],
        ], true, $aiRequest);

        $this->assertTrue($result['success']);
        $this->assertSame($fallback->id, $usedProvider->id);

        $failover = AiFailover::query()->where('request_id', $aiRequest->id)->first();
        $this->assertNotNull($failover);
        $this->assertSame(AiFailover::TRIGGER_PROVIDER_ERROR, $failover->trigger_type);
    }

    public function test_a_throwing_gateway_call_with_no_remaining_candidate_returns_a_graceful_failure_not_an_exception(): void
    {
        $onlyProvider = $this->makeProvider('openai');

        $this->mock(AiGateway::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->andThrow(new RuntimeException('Unsupported AI provider [openai].'));
        });

        $aiRequest = AiRequest::query()->create([
            'owner_type' => 'Modules\\Admin\\Models\\Admin',
            'owner_id' => 1,
            'status' => AiRequest::STATUS_PROCESSING,
        ]);

        [$result] = $this->invokeDispatch([
            ['provider' => $onlyProvider, 'model_key' => null],
        ], true, $aiRequest);

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['message']);
    }
}
