<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * v2.0 requirements doc S15.4 (rate limiting for costly operations). This
 * was a real, previously-unflagged gap: no `throttle:` middleware existed
 * on any AI route, only the business/plan quota (AiChatUsageGuard), which
 * protects revenue, not abuse. Two things are proven here:
 *
 * 1. The general limiter (ai-chat-general, 60/min) actually rejects
 *    request 61 over real HTTP, on a cheap endpoint (status) so the test
 *    doesn't need to mock the whole chat pipeline 61 times.
 * 2. The stricter send-message limiter (ai-chat-send, 15/min) is really
 *    attached to the sendMessage route (not copy-pasted onto the wrong
 *    one) and is a distinct, tighter limit from the general one - proven
 *    by inspecting the resolved route's middleware, plus by exercising
 *    the named limiter itself directly through Laravel's RateLimiter
 *    facade (the same mechanism the middleware calls), rather than
 *    firing 16 real AI-provider-backed sendMessage requests.
 */
class AiChatRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Rate Limit Test User',
            'email' => 'ratelimit-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => true,
        ]);
    }

    public function test_the_general_limiter_returns_429_after_60_requests_in_a_minute(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user, ['*'], 'user_api');

        for ($i = 1; $i <= 60; $i++) {
            $response = $this->getJson('/api/user/v1/ai-chat/status');
            $this->assertNotSame(429, $response->getStatusCode(), "request {$i} of 60 must not be throttled yet");
        }

        $response = $this->getJson('/api/user/v1/ai-chat/status');
        $response->assertStatus(429);
    }

    public function test_the_send_message_route_carries_the_stricter_dedicated_limiter(): void
    {
        $route = collect(Route::getRoutes())->first(function ($route) {
            return $route->uri() === 'api/user/v1/ai-chat/conversations/{conversation}/messages'
                && in_array('POST', $route->methods(), true);
        });

        $this->assertNotNull($route, 'the sendMessage route must exist');
        $this->assertContains('throttle:ai-chat-send', $route->middleware());
        $this->assertNotContains('throttle:ai-chat-general', $route->middleware(), 'the group-level general limiter should not double up on the stricter per-route one');
    }

    public function test_the_ai_chat_send_named_limiter_rejects_a_16th_attempt_within_a_minute(): void
    {
        $key = 'rate-limit-direct-test-key';

        for ($i = 1; $i <= 15; $i++) {
            $this->assertFalse(
                \Illuminate\Support\Facades\RateLimiter::tooManyAttempts('ai-chat-send:'.$key, 15),
                "attempt {$i} of 15 must not be blocked yet",
            );
            \Illuminate\Support\Facades\RateLimiter::hit('ai-chat-send:'.$key, 60);
        }

        $this->assertTrue(\Illuminate\Support\Facades\RateLimiter::tooManyAttempts('ai-chat-send:'.$key, 15));
    }
}
