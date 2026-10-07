<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiFailover;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\AiProviderHealth;
use Modules\AI\Models\AiRequest;
use Modules\AI\Services\AiCircuitBreaker;
use Modules\AI\Services\AiChatService;
use Modules\AI\Services\AiGateway;
use ReflectionMethod;
use Tests\TestCase;

/**
 * v2.0 requirements doc S15.4/S20.3 (circuit breaker for critical paths).
 *
 * Part 1 tests AiCircuitBreaker directly - it is a small, self-contained
 * service with no network dependency, so it is exercised end to end
 * (real ai_provider_health rows, no mocking).
 *
 * Part 2 proves the breaker is actually wired into the provider dispatch
 * loop (AiChatService::dispatchWithFallback(), reached by reflection for
 * the same reason as the rest of this suite's reflection-based tests:
 * it is an internal step of sendMessage(), and exercising the full
 * public flow would need a large, unrelated fixture setup that has
 * nothing to do with what this feature adds). A provider whose circuit
 * is already open must be skipped with no call to AiGateway::chat() at
 * all, not merely called-and-failed.
 */
class AiCircuitBreakerTest extends TestCase
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

    public function test_breaker_is_closed_by_default_with_no_health_history(): void
    {
        $breaker = app(AiCircuitBreaker::class);
        $provider = $this->makeProvider();

        $this->assertFalse($breaker->isOpen($provider));
    }

    public function test_breaker_opens_after_reaching_the_configured_failure_threshold(): void
    {
        config(['ai.circuit_breaker.failure_threshold' => 3, 'ai.circuit_breaker.open_seconds' => 60]);

        $breaker = app(AiCircuitBreaker::class);
        $provider = $this->makeProvider();

        $breaker->recordFailure($provider);
        $this->assertFalse($breaker->isOpen($provider), 'one failure should not open the circuit');

        $breaker->recordFailure($provider);
        $this->assertFalse($breaker->isOpen($provider), 'two failures should not open the circuit (threshold is 3)');

        $breaker->recordFailure($provider);
        $this->assertTrue($breaker->isOpen($provider), 'the third consecutive failure should open the circuit');

        $latest = AiProviderHealth::query()->where('provider_id', $provider->id)->latest('id')->first();
        $this->assertSame(AiProviderHealth::STATUS_UNHEALTHY, $latest->status);
        $this->assertSame(3, $latest->details['consecutive_failures']);
    }

    public function test_a_success_resets_the_consecutive_failure_count(): void
    {
        config(['ai.circuit_breaker.failure_threshold' => 3]);

        $breaker = app(AiCircuitBreaker::class);
        $provider = $this->makeProvider();

        $breaker->recordFailure($provider);
        $breaker->recordFailure($provider);
        $breaker->recordSuccess($provider);

        // Two more failures right after the success should NOT open the
        // circuit, because the success reset the streak back to zero.
        $breaker->recordFailure($provider);
        $breaker->recordFailure($provider);

        $this->assertFalse($breaker->isOpen($provider));
    }

    public function test_the_circuit_closes_again_once_the_cooldown_window_has_elapsed(): void
    {
        config(['ai.circuit_breaker.failure_threshold' => 1, 'ai.circuit_breaker.open_seconds' => 60]);

        $breaker = app(AiCircuitBreaker::class);
        $provider = $this->makeProvider();

        $breaker->recordFailure($provider);
        $this->assertTrue($breaker->isOpen($provider));

        // Force the stored open_until into the past instead of sleeping
        // 60 real seconds in a test.
        $latest = AiProviderHealth::query()->where('provider_id', $provider->id)->latest('id')->first();
        $latest->details = ['consecutive_failures' => 1, 'open_until' => now()->subMinute()->toISOString()];
        $latest->save();

        $this->assertFalse($breaker->isOpen($provider), 'an elapsed cooldown should let a probe call through');
    }

    public function test_the_breaker_can_be_disabled_via_config(): void
    {
        config(['ai.circuit_breaker.enabled' => false, 'ai.circuit_breaker.failure_threshold' => 1]);

        $breaker = app(AiCircuitBreaker::class);
        $provider = $this->makeProvider();

        $breaker->recordFailure($provider);

        $this->assertFalse($breaker->isOpen($provider), 'a disabled breaker must never report open, regardless of history');
    }

    public function test_dispatch_with_fallback_skips_a_provider_whose_circuit_is_already_open_without_calling_the_gateway(): void
    {
        config(['ai.circuit_breaker.failure_threshold' => 1, 'ai.circuit_breaker.open_seconds' => 60]);

        $primary = $this->makeProvider('openai');
        $fallback = $this->makeProvider('anthropic');

        app(AiCircuitBreaker::class)->recordFailure($primary);
        $this->assertTrue(app(AiCircuitBreaker::class)->isOpen($primary));

        $aiRequest = AiRequest::query()->create([
            'owner_type' => 'Modules\\Admin\\Models\\Admin',
            'owner_id' => 1,
            'status' => AiRequest::STATUS_PROCESSING,
        ]);

        $this->mock(AiGateway::class, function ($mock) {
            // The primary must never be called - if it were, this
            // expectation (exactly one call, implicitly for the
            // fallback only) would fail the test.
            $mock->shouldReceive('chat')->once()->andReturn([
                'success' => true,
                'message' => '',
                'content' => 'answer from fallback',
            ]);
        });

        $service = app(AiChatService::class);
        $method = new ReflectionMethod(AiChatService::class, 'dispatchWithFallback');
        $method->setAccessible(true);

        $candidates = [
            ['provider' => $primary, 'model_key' => null],
            ['provider' => $fallback, 'model_key' => null],
        ];

        [$result, $usedProvider] = $method->invoke($service, $candidates, true, [], $aiRequest);

        $this->assertTrue($result['success']);
        $this->assertSame($fallback->id, $usedProvider->id);

        $failover = AiFailover::query()->where('request_id', $aiRequest->id)->first();
        $this->assertNotNull($failover);
        $this->assertSame(AiFailover::TRIGGER_HEALTH_THRESHOLD, $failover->trigger_type);
        $this->assertSame($primary->id, $failover->primary_provider_id);
        $this->assertSame($fallback->id, $failover->fallback_provider_id);
    }
}
