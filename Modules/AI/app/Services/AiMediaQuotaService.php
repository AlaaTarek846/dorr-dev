<?php

namespace Modules\AI\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Modules\AI\Models\AiMediaGeneration;
use Modules\AI\Models\AiPlan;

/**
 * Applies the plan's media limits (ai_plans.image_daily_limit,
 * video_daily_limit, video_max_seconds) and keeps the ledger they are counted
 * from (ai_media_generations).
 *
 * "Today" is the calendar day in the application timezone. A generation that
 * failed does not count against the owner, so a provider outage never eats the
 * daily allowance.
 */
class AiMediaQuotaService
{
    public const REASON_NOT_IN_PLAN = 'not_in_plan';

    public const REASON_DAILY_LIMIT = 'daily_limit_reached';

    /**
     * Whether one more image/video fits the plan today. Read-only: use
     * reserve() to check and record in one atomic step.
     *
     * @return array{allowed: bool, reason: ?string, limit: ?int, used: int, remaining: ?int, max_seconds: ?int}
     */
    public function check(Authenticatable $owner, ?AiPlan $plan, string $kind): array
    {
        $limit = $this->dailyLimit($plan, $kind);
        $used = $this->usedToday($owner, $kind);
        $maxSeconds = $kind === AiMediaGeneration::KIND_VIDEO ? max(0, (int) ($plan?->video_max_seconds ?? 0)) : null;

        if ($limit !== null && $limit <= 0) {
            return ['allowed' => false, 'reason' => self::REASON_NOT_IN_PLAN, 'limit' => 0, 'used' => $used, 'remaining' => 0, 'max_seconds' => $maxSeconds];
        }

        if ($kind === AiMediaGeneration::KIND_VIDEO && $maxSeconds !== null && $maxSeconds <= 0) {
            return ['allowed' => false, 'reason' => self::REASON_NOT_IN_PLAN, 'limit' => $limit, 'used' => $used, 'remaining' => 0, 'max_seconds' => 0];
        }

        if ($limit !== null && $used >= $limit) {
            return ['allowed' => false, 'reason' => self::REASON_DAILY_LIMIT, 'limit' => $limit, 'used' => $used, 'remaining' => 0, 'max_seconds' => $maxSeconds];
        }

        return [
            'allowed' => true,
            'reason' => null,
            'limit' => $limit,
            'used' => $used,
            'remaining' => $limit === null ? null : $limit - $used,
            'max_seconds' => $maxSeconds,
        ];
    }

    /**
     * Check and record under one lock, so two simultaneous requests cannot both
     * take the last slot.
     *
     * @param  array<string, mixed>  $attributes  extra ai_media_generations columns
     * @return array{decision: array{allowed: bool, reason: ?string, limit: ?int, used: int, remaining: ?int, max_seconds: ?int}, generation: ?AiMediaGeneration}
     */
    public function reserve(Authenticatable $owner, ?AiPlan $plan, string $kind, array $attributes = []): array
    {
        $lock = Cache::lock('ai-media-quota:'.$owner->getMorphClass().':'.$owner->getAuthIdentifier(), 10);

        try {
            $lock->block(5);
        } catch (\Throwable) {
            // Could not get the lock in time: refuse rather than risk going over the limit.
            $decision = $this->check($owner, $plan, $kind);

            return ['decision' => array_merge($decision, ['allowed' => false, 'reason' => self::REASON_DAILY_LIMIT]), 'generation' => null];
        }

        try {
            $decision = $this->check($owner, $plan, $kind);

            if (! $decision['allowed']) {
                return ['decision' => $decision, 'generation' => null];
            }

            $generation = AiMediaGeneration::query()->create($attributes + [
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getAuthIdentifier(),
                'kind' => $kind,
                'status' => AiMediaGeneration::STATUS_PENDING,
                'started_at' => now(),
            ]);

            return ['decision' => $decision, 'generation' => $generation];
        } finally {
            optional($lock)->release();
        }
    }

    public function markCompleted(AiMediaGeneration $generation): void
    {
        $generation->forceFill(['status' => AiMediaGeneration::STATUS_COMPLETED, 'completed_at' => now(), 'error_code' => null, 'error_message' => null])->save();
    }

    public function markFailed(AiMediaGeneration $generation, string $code, ?string $message = null): void
    {
        $generation->forceFill([
            'status' => AiMediaGeneration::STATUS_FAILED,
            'completed_at' => now(),
            'error_code' => $code,
            'error_message' => $message !== null ? mb_substr($message, 0, 500) : null,
        ])->save();
    }

    public function usedToday(Authenticatable $owner, string $kind): int
    {
        return AiMediaGeneration::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getAuthIdentifier())
            ->where('kind', $kind)
            ->where('status', '!=', AiMediaGeneration::STATUS_FAILED)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }

    /**
     * NULL means unlimited. Only images can be unlimited: a plan with no
     * explicit video allowance simply has none.
     */
    protected function dailyLimit(?AiPlan $plan, string $kind): ?int
    {
        if ($kind === AiMediaGeneration::KIND_VIDEO) {
            return max(0, (int) ($plan?->video_daily_limit ?? 0));
        }

        return $plan?->image_daily_limit;
    }
}
