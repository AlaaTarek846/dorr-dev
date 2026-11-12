<?php

namespace Modules\AI\Services\Monitoring;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\AI\Models\AiSecurityEvent;

/**
 * Writes ai_security_events (admin.ai-security-events.index) for the
 * security decisions the AI engine already takes:
 *  - rate_limit_exceeded: the ai-chat-send / ai-chat-general throttles
 *    refused a request (AIServiceProvider::registerRateLimiters);
 *  - blocked_owner_attempt: an account the admin blocked in AI trial
 *    control (abuse_status = blocked) still tried to use the AI
 *    (AiChatUsageGuard::evaluate, AiTaskRunner).
 *
 * Cheap and failure-safe: one insert, at most one row per event type and
 * owner per minute (a flood of refused requests is one row, not
 * thousands), and any failure is reported, never thrown - the request
 * that triggered it is answered exactly as before.
 */
class AiSecurityEventRecorder
{
    public const EVENT_RATE_LIMIT_EXCEEDED = 'rate_limit_exceeded';

    public const EVENT_BLOCKED_OWNER_ATTEMPT = 'blocked_owner_attempt';

    public function record(
        string $eventType,
        string $severity,
        ?Authenticatable $owner,
        ?string $description = null,
        ?string $correlationId = null,
        ?string $dedupeKey = null,
        int $dedupeSeconds = 60,
    ): void {
        try {
            $dedupeKey ??= $owner ? $owner->getMorphClass().':'.$owner->getAuthIdentifier() : null;

            if ($dedupeKey !== null && ! Cache::add("ai-security-event:{$eventType}:{$dedupeKey}", 1, $dedupeSeconds)) {
                return;
            }

            AiSecurityEvent::query()->create([
                'owner_type' => $owner?->getMorphClass(),
                'owner_id' => $owner?->getAuthIdentifier(),
                'event_type' => $eventType,
                'severity' => $severity,
                'correlation_id' => $correlationId,
                'description' => $description === null ? null : Str::limit($description, 1000),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
