<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiRealtimeSession;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Phase 7 (realtime voice), first real slice: exercises the actual HTTP
 * route (not the service directly), because the single most important
 * thing to prove end-to-end is section 36's explicit "never expose
 * OPENAI_API_KEY to Android" rule - only a real request/response round
 * trip through the real route + real JsonResource/response shaping can
 * catch the raw key leaking in somewhere unexpected (a debug field, an
 * exception message, etc.), which a direct service-level test would not
 * reliably catch.
 */
class AiRealtimeSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Realtime Test User',
            'email' => 'realtime-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);
    }

    protected function makeTrialPlan(): AiPlan
    {
        return AiPlan::query()->create([
            'name' => 'Trial Plan (realtime test)',
            'code' => 'trial-realtime-test-'.uniqid(),
            'is_trial' => true,
            'is_active' => true,
            'sort_order' => 1,
            'usage_minutes' => 60,
            'cooldown_minutes' => 5,
        ]);
    }

    protected function registerRealtimeModel(): void
    {
        $repository = app(AiProviderRepository::class);
        $provider = $repository->updateByKey('openai', [
            'is_enabled' => true,
            'api_key' => 'sk-REAL-SECRET-MUST-NEVER-LEAK',
        ]);

        $provider->models()->create([
            'model_key' => 'gpt-realtime-2.1',
            'display_name' => 'GPT Realtime',
            'capabilities' => ['realtime'],
            'is_active' => true,
        ]);
    }

    public function test_a_realtime_session_is_created_and_the_real_api_key_never_leaks_into_the_response(): void
    {
        $this->registerRealtimeModel();
        $this->makeTrialPlan();

        Http::fake([
            'api.openai.com/v1/realtime/client_secrets' => Http::response([
                'client_secret' => ['value' => 'ek_test_ephemeral_token', 'expires_at' => now()->addMinutes(10)->timestamp],
                'session' => ['model' => 'gpt-realtime-2.1'],
            ], 200),
        ]);

        $user = $this->makeUser();
        Sanctum::actingAs($user, ['*'], 'user_api');

        $response = $this->postJson('/api/user/v1/ai-realtime/session', []);

        $response->assertOk();
        $payload = $response->getData(true);

        $this->assertTrue($payload['success'], json_encode($payload));
        $this->assertSame('ek_test_ephemeral_token', $payload['data']['client_secret']);
        $this->assertSame('gpt-realtime-2.1', $payload['data']['model']);
        $this->assertArrayHasKey('session_id', $payload['data']);

        // The decisive security assertion: the raw provider API key must
        // not appear ANYWHERE in the response body, under any key name.
        $this->assertStringNotContainsString('sk-REAL-SECRET-MUST-NEVER-LEAK', $response->getContent());

        $this->assertDatabaseHas('ai_realtime_sessions', [
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->id,
            'model_key' => 'gpt-realtime-2.1',
            'status' => AiRealtimeSession::STATUS_CREATED,
        ]);

        // The real OpenAI API key (server-side only) must have been the
        // one actually sent to OpenAI - proves the connector used it,
        // while the assertion above proves it never came back out.
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-REAL-SECRET-MUST-NEVER-LEAK'));
    }

    /**
     * Real, observed bug fix: AiVoiceScreen.kt (Android) always calls this
     * endpoint with instructions=null - before this fix, that meant OpenAI's
     * Realtime API got NO language guidance at all for a voice call, unlike
     * every text/voice-message chat turn (which always goes through
     * AiChatLanguageResolver). This proves the session request actually
     * carries that same dialect/register directive now, even with the
     * client sending nothing.
     */
    public function test_the_language_directive_is_sent_to_openai_even_with_no_client_instructions(): void
    {
        $this->registerRealtimeModel();
        $this->makeTrialPlan();

        Http::fake([
            'api.openai.com/v1/realtime/client_secrets' => Http::response([
                'client_secret' => ['value' => 'ek_test_ephemeral_token', 'expires_at' => now()->addMinutes(10)->timestamp],
                'session' => ['model' => 'gpt-realtime-2.1'],
            ], 200),
        ]);

        $user = $this->makeUser();
        Sanctum::actingAs($user, ['*'], 'user_api');

        $response = $this->postJson('/api/user/v1/ai-realtime/session', []);

        $response->assertOk();

        Http::assertSent(function ($request) {
            $instructions = $request->data()['session']['instructions'] ?? null;

            return $instructions !== null
                && str_contains($instructions, "register/dialect of the user")
                && str_contains($instructions, 'Saudi/Gulf');
        });
    }

    /**
     * A client-supplied instructions string (future use - the client
     * doesn't send one today) must layer ON TOP OF the language directive,
     * not replace it - same rule the text chat's own conversation-level
     * instructions already follow in systemMessages().
     */
    public function test_a_client_supplied_instructions_string_is_appended_to_the_language_directive(): void
    {
        $this->registerRealtimeModel();
        $this->makeTrialPlan();

        Http::fake([
            'api.openai.com/v1/realtime/client_secrets' => Http::response([
                'client_secret' => ['value' => 'ek_test_ephemeral_token', 'expires_at' => now()->addMinutes(10)->timestamp],
                'session' => ['model' => 'gpt-realtime-2.1'],
            ], 200),
        ]);

        $user = $this->makeUser();
        Sanctum::actingAs($user, ['*'], 'user_api');

        $response = $this->postJson('/api/user/v1/ai-realtime/session', [
            'instructions' => 'Keep answers under 20 seconds.',
        ]);

        $response->assertOk();

        Http::assertSent(function ($request) {
            $instructions = $request->data()['session']['instructions'] ?? null;

            return $instructions !== null
                && str_contains($instructions, "register/dialect of the user")
                && str_contains($instructions, 'Keep answers under 20 seconds.');
        });
    }

    public function test_no_session_is_created_when_no_realtime_capable_model_is_registered(): void
    {
        $repository = app(AiProviderRepository::class);
        $repository->updateByKey('openai', ['is_enabled' => true, 'api_key' => 'sk-test-not-real']);
        // Deliberately no realtime-capable model registered.

        $this->makeTrialPlan();

        $user = $this->makeUser();
        Sanctum::actingAs($user, ['*'], 'user_api');

        $response = $this->postJson('/api/user/v1/ai-realtime/session', []);

        $response->assertStatus(422);
        $this->assertSame(0, AiRealtimeSession::query()->count());
    }

    public function test_a_user_with_no_usable_plan_is_denied_before_any_provider_call(): void
    {
        $this->registerRealtimeModel();
        // Deliberately no plan created - REASON_NO_PLAN.

        Http::fake([
            'api.openai.com/v1/realtime/client_secrets' => Http::response([], 500),
        ]);

        $user = $this->makeUser();
        Sanctum::actingAs($user, ['*'], 'user_api');

        $response = $this->postJson('/api/user/v1/ai-realtime/session', []);

        $response->assertStatus(402);
        $this->assertSame(0, AiRealtimeSession::query()->count());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'realtime/client_secrets'));
    }

    public function test_ending_a_session_records_its_duration(): void
    {
        $this->registerRealtimeModel();
        $this->makeTrialPlan();

        Http::fake([
            'api.openai.com/v1/realtime/client_secrets' => Http::response([
                'client_secret' => ['value' => 'ek_test_token', 'expires_at' => now()->addMinutes(10)->timestamp],
            ], 200),
        ]);

        $user = $this->makeUser();
        Sanctum::actingAs($user, ['*'], 'user_api');

        $created = $this->postJson('/api/user/v1/ai-realtime/session', [])->getData(true);
        $sessionId = $created['data']['session_id'];

        $response = $this->postJson("/api/user/v1/ai-realtime/session/{$sessionId}/end", ['duration_seconds' => 187]);

        $response->assertOk();

        $this->assertDatabaseHas('ai_realtime_sessions', [
            'id' => $sessionId,
            'status' => AiRealtimeSession::STATUS_ENDED,
            'duration_seconds' => 187,
        ]);
    }

    public function test_a_user_cannot_end_another_users_session(): void
    {
        $this->registerRealtimeModel();
        $this->makeTrialPlan();

        Http::fake([
            'api.openai.com/v1/realtime/client_secrets' => Http::response([
                'client_secret' => ['value' => 'ek_test_token'],
            ], 200),
        ]);

        $owner = $this->makeUser();
        Sanctum::actingAs($owner, ['*'], 'user_api');
        $created = $this->postJson('/api/user/v1/ai-realtime/session', [])->getData(true);
        $sessionId = $created['data']['session_id'];

        $intruder = $this->makeUser();
        Sanctum::actingAs($intruder, ['*'], 'user_api');

        $response = $this->postJson("/api/user/v1/ai-realtime/session/{$sessionId}/end", ['duration_seconds' => 999]);

        $response->assertStatus(404);
    }
}
