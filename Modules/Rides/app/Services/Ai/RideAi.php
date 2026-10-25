<?php

namespace Modules\Rides\Services\Ai;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Modules\AI\Services\AiTaskRunner;
use Modules\Rides\Exceptions\RidesException;

/**
 * DORR AI inside rides (Block 06) — through the one AI system, never a model of our own.
 *
 *  - {@see ask()}: a tool a rider or a captain uses (understand my destination, the captain's assistant…).
 *    It needs a DORR AI plan like every AI tool (ADR-013): a refusal is `rides_ai_plan_{reason}` (402)
 *    with `reason` in the data, which the apps turn into "see the plans".
 *  - {@see system()}: DORR's own reading for its team (a case's triage, the quality report) — nobody's
 *    plan pays for it, like the support's automatic answers.
 */
class RideAi
{
    public function __construct(private readonly AiTaskRunner $runner) {}

    /** Whether this person's plan allows AI now, read-only — to lock a button before it's tapped. */
    public function entitlement(Authenticatable $owner): array
    {
        return $this->runner->entitlement($owner);
    }

    /**
     * A tool the person uses, answered in JSON.
     *
     * @return array<string, mixed>
     */
    public function ask(Authenticatable $owner, string $system, string $input, string $source): array
    {
        $result = $this->runner->chat([['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $input]], $source, $owner);
        if (! $result['success']) {
            throw $this->failure($result);
        }

        return self::json((string) $result['content']);
    }

    /**
     * DORR's own reading for its team; null when AI isn't there or failed (the work goes on without it).
     *
     * @return array<string, mixed>|null
     */
    public function system(string $system, string $input, string $source): ?array
    {
        if (! $this->runner->available()) {
            return null;
        }
        $result = $this->runner->chat([['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $input]], $source, null, true);
        if (! $result['success']) {
            Log::info('[Rides AI] '.$source.' skipped: '.($result['reason'] ?? '-'));

            return null;
        }
        $json = self::json((string) $result['content']);

        return $json === [] ? null : $json;
    }

    /** The plan refusal the apps understand, or a plain "AI isn't available now". */
    public function failure(array $result): RidesException
    {
        $detail = $result['detail'] ?? [];

        return match ($result['reason'] ?? null) {
            AiTaskRunner::REASON_PLAN => new RidesException('ai_plan_'.($detail['usage_reason'] ?? 'no_plan'), 402, [], [
                'reason' => $detail['usage_reason'] ?? 'no_plan',
                'cooldown_seconds_left' => $detail['cooldown_seconds_left'] ?? null,
            ]),
            AiTaskRunner::REASON_OWNER_BLOCKED => new RidesException('ai_plan_blocked', 403, [], ['reason' => 'blocked']),
            AiTaskRunner::REASON_CONTENT_BLOCKED => new RidesException('ai_message_blocked', 422),
            AiTaskRunner::REASON_UNAVAILABLE => new RidesException('ai_unavailable', 503),
            default => (function () use ($result) {
                Log::warning('[Rides AI] '.($result['provider']?->key ?? '-').': '.($result['message'] ?? ''));

                return new RidesException('ai_unavailable', 503);
            })(),
        };
    }

    /** The plan's refusal before any call, when the button is locked by the plan. */
    public function refuseUnlessEntitled(Authenticatable $owner): void
    {
        $entitlement = $this->entitlement($owner);
        if (! $entitlement['allowed']) {
            $reason = (string) ($entitlement['reason'] ?? 'no_plan');
            throw new RidesException('ai_plan_'.$reason, $reason === 'blocked' ? 403 : 402, [], ['reason' => $reason]);
        }
    }

    /** @return array<string, mixed> */
    public static function json(string $content): array
    {
        $content = trim((string) preg_replace('/^```(?:json)?|```$/m', '', $content));
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start === false || $end === false || $end < $start) {
            return [];
        }
        $decoded = json_decode(substr($content, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : [];
    }
}
