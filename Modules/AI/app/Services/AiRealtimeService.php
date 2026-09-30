<?php

namespace Modules\AI\Services;

use App\Support\Api\ApiResponse;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Modules\AI\Enums\AiModelCapability;
use Modules\AI\Models\AiRealtimeSession;
use Modules\AI\Repositories\AiConversationRepository;
use Modules\AI\Repositories\AiProviderRepository;

/**
 * Phase 7 (master spec section 20/21): the Laravel half of realtime voice.
 * Deliberately thin and deliberately NEVER touches the live audio stream
 * itself - its one job is minting a short-lived Realtime session
 * credential the Android client then uses to open its OWN WebRTC
 * connection straight to OpenAI (see OpenAiConnector::createRealtimeSession()'s
 * docblock for exactly why that split exists and how it keeps
 * OPENAI_API_KEY off the client, per section 36).
 *
 * What this service IS responsible for, matching section 20's split
 * exactly ("Laravel should be responsible for: authentication, session
 * creation, authorization, conversation tracking, usage tracking,
 * configuration"):
 *
 * - authentication/authorization: only a real, quota-eligible owner
 *   (same AiChatUsageGuard every text message already goes through -
 *   voice minutes draw from the same plan budget, matching section 35's
 *   "voice limits") can mint a session at all.
 * - model selection: via the same generic AiModelResolver every other
 *   capability uses (not a hardcoded model name), so a newly-registered
 *   realtime model is picked up automatically - matching section 57.
 * - conversation tracking / usage tracking: every mint is persisted as
 *   an AiRealtimeSession row, and endSession() records how long it
 *   actually ran once the client reports it over.
 */
class AiRealtimeService
{
    public function __construct(
        protected AiProviderRepository $providers,
        protected AiConversationRepository $conversations,
        protected AiModelResolver $modelResolver,
        protected AiGateway $gateway,
        protected AiChatUsageGuard $usageGuard,
        protected AiAuditTrail $auditTrail,
    ) {}

    public function createSession(Authenticatable $owner, int|string|null $conversationId = null, ?string $instructions = null): JsonResponse
    {
        $usage = $this->usageGuard->evaluate($owner);

        if (! $usage['allowed']) {
            return ApiResponse::error(
                $this->usageDenialMessage($usage['reason']),
                $usage['reason'] === AiChatUsageGuard::REASON_BLOCKED ? 403 : 402,
            );
        }

        $conversation = null;

        if ($conversationId !== null) {
            // Ownership check only - a realtime session tied to someone
            // else's conversation id must 404 before it ever reaches the
            // usage/provider logic above, the same IDOR-safety ordering
            // AiChatService::sendMessage() already follows (though here
            // the usage check already ran - a voice session is billed
            // the same whether or not it is linked to a conversation, so
            // there is no billing-state leak in checking usage first).
            $conversation = $this->conversations->findForOwner($owner, $conversationId);
        }

        $match = $this->modelResolver->resolve($this->providers, [AiModelCapability::Realtime->value]);

        if ($match === null) {
            return ApiResponse::error(__('ai.realtime_no_model'), 422);
        }

        $provider = $match['provider'];
        $model = $match['model'];

        $result = $this->gateway->createRealtimeSession($provider, $model->model_key, [
            'instructions' => $instructions,
        ]);

        if (! $result['success'] || $result['session'] === null) {
            return ApiResponse::error($result['message'] ?: __('ai.realtime_session_failed'), 502);
        }

        $session = AiRealtimeSession::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getAuthIdentifier(),
            'conversation_id' => $conversation?->id,
            'provider_id' => $provider->id,
            'model_key' => $model->model_key,
            'status' => AiRealtimeSession::STATUS_CREATED,
            'expires_at' => $result['session']['expires_at'] !== null
                ? now()->setTimestamp($result['session']['expires_at'])
                : null,
        ]);

        $this->auditTrail->record(
            AiAuditTrail::EVENT_REALTIME_SESSION_CREATED,
            owner: $owner,
            subjectType: 'ai_realtime_session',
            subjectId: $session->id,
            metadata: ['provider_id' => $provider->id, 'model_key' => $model->model_key],
        );

        return ApiResponse::success([
            'session_id' => $session->id,
            'client_secret' => $result['session']['client_secret'],
            'expires_at' => $result['session']['expires_at'],
            'model' => $model->model_key,
            // Section 20's split made explicit for the client: this is
            // NOT a URL on our own backend - it is OpenAI's own WebRTC
            // SDP-exchange endpoint the app posts its offer to directly,
            // carrying the client_secret above as its Bearer token, per
            // OpenAiConnector::createRealtimeSession()'s docblock.
            'webrtc_endpoint' => 'https://api.openai.com/v1/realtime/calls',
        ], __('api.created'));
    }

    /**
     * The Android client calls this once the live call actually ends, so
     * ai_realtime_sessions reflects real usage (section 34's "voice
     * usage" tracking) rather than only ever recording that a credential
     * was minted - a minted session the app never actually connects with
     * (dropped connection, user backed out) must not look identical to a
     * real multi-minute conversation.
     */
    public function endSession(Authenticatable $owner, int|string $sessionId, ?int $durationSeconds = null): JsonResponse
    {
        $session = AiRealtimeSession::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getAuthIdentifier())
            ->findOrFail($sessionId);

        $session->update([
            'status' => AiRealtimeSession::STATUS_ENDED,
            'ended_at' => now(),
            'duration_seconds' => $durationSeconds,
            'started_at' => $session->started_at ?? now(),
        ]);

        return ApiResponse::success(['session_id' => $session->id], __('api.updated'));
    }

    protected function usageDenialMessage(?string $reason): string
    {
        return match ($reason) {
            AiChatUsageGuard::REASON_BLOCKED => __('ai.usage_blocked'),
            AiChatUsageGuard::REASON_TRIAL_ENDED => __('ai.usage_trial_ended'),
            AiChatUsageGuard::REASON_SUBSCRIPTION_SUSPENDED => __('ai.subscription_suspended'),
            AiChatUsageGuard::REASON_COOLDOWN => __('ai.usage_cooldown'),
            AiChatUsageGuard::REASON_LIMIT_REACHED => __('ai.usage_limit_reached'),
            default => __('ai.usage_unavailable'),
        };
    }
}
