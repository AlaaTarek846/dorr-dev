<?php

namespace Modules\AI\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiSubscription;
use Modules\AI\Models\AiTrialControl;
use Modules\AI\Models\AiUsageSession;

/**
 * Gate-keeps every chat message against the platform's plan/trial/cooldown
 * business rules (Phase 1/2 tables) before a single token is spent talking
 * to a real AI provider:
 *
 * - First-ever user gets a "free_trial" AiPlan auto-provisioned the moment
 *   they open the chat (no manual activation needed).
 * - Every plan grants a rolling "usage_minutes" window; once it is used up,
 *   the current AiUsageSession is closed and the owner must wait out the
 *   plan's "cooldown_minutes" before a new window opens.
 * - An owner flagged/blocked in ai_trial_control (abuse control) is refused
 *   outright, and a trial that has been fully consumed stays "ended" -
 *   it does not silently re-open.
 */
class AiChatUsageGuard
{
    public const REASON_BLOCKED = 'blocked';

    public const REASON_TRIAL_ENDED = 'trial_ended';

    public const REASON_NO_PLAN = 'no_plan';

    public const REASON_COOLDOWN = 'cooldown';

    public const REASON_LIMIT_REACHED = 'limit_reached';

    /** A paid subscription that existed but was suspended after a
     * renewal charge failed and its 3-day grace period passed
     * (AiSubscriptionPurchaseService::processDueSubscriptions()) - kept
     * distinct from REASON_TRIAL_ENDED so the owner is told the true,
     * actionable reason (top up and resubscribe) instead of a generic
     * "your trial ended" message that never applied to them. */
    public const REASON_SUBSCRIPTION_SUSPENDED = 'subscription_suspended';

    /**
     * @return array{
     *     allowed: bool,
     *     reason: ?string,
     *     plan: ?AiPlan,
     *     subscription: ?AiSubscription,
     *     session: ?AiUsageSession,
     *     remaining_seconds: ?int,
     *     cooldown_seconds_left: ?int,
     *     trial_status: string,
     * }
     */
    public function evaluate(Authenticatable $owner): array
    {
        $ownerType = $owner->getMorphClass();
        $ownerId = $owner->getAuthIdentifier();

        $trial = AiTrialControl::query()->firstOrCreate(
            ['owner_type' => $ownerType, 'owner_id' => $ownerId],
            ['trial_status' => AiTrialControl::TRIAL_ELIGIBLE, 'abuse_status' => AiTrialControl::ABUSE_CLEAR],
        );

        if ($trial->abuse_status === AiTrialControl::ABUSE_BLOCKED) {
            return $this->deny(self::REASON_BLOCKED, trialStatus: $trial->trial_status);
        }

        $subscription = AiSubscription::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('status', AiSubscription::STATUS_ACTIVE)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->with('plan')
            ->latest('id')
            ->first();

        if (! $subscription) {
            $suspended = AiSubscription::query()
                ->where('owner_type', $ownerType)
                ->where('owner_id', $ownerId)
                ->where('status', AiSubscription::STATUS_SUSPENDED)
                ->with('plan')
                ->latest('id')
                ->first();

            if ($suspended) {
                return $this->deny(self::REASON_SUBSCRIPTION_SUSPENDED, plan: $suspended->plan, subscription: $suspended, trialStatus: $trial->trial_status);
            }

            if ($trial->trial_status !== AiTrialControl::TRIAL_ELIGIBLE) {
                // Trial already used up (or something ended their only
                // subscription) and there is nothing active to fall back on.
                return $this->deny(self::REASON_TRIAL_ENDED, trialStatus: $trial->trial_status);
            }

            $plan = AiPlan::query()
                ->where('is_trial', true)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->first();

            if (! $plan) {
                return $this->deny(self::REASON_NO_PLAN, trialStatus: $trial->trial_status);
            }

            $subscription = AiSubscription::query()->create([
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'plan_id' => $plan->id,
                'starts_at' => now(),
                'ends_at' => null,
                'status' => AiSubscription::STATUS_ACTIVE,
            ]);
            $subscription->setRelation('plan', $plan);

            $trial->trial_status = AiTrialControl::TRIAL_ACTIVE;
            $trial->save();
        }

        $plan = $subscription->plan ?? $subscription->plan()->first();

        if (! $plan) {
            return $this->deny(self::REASON_NO_PLAN, trialStatus: $trial->trial_status);
        }

        $session = AiUsageSession::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('subscription_id', $subscription->id)
            ->whereNull('ended_at')
            ->latest('id')
            ->first();

        if (! $session) {
            $lastSession = AiUsageSession::query()
                ->where('owner_type', $ownerType)
                ->where('owner_id', $ownerId)
                ->where('subscription_id', $subscription->id)
                ->whereNotNull('ended_at')
                ->latest('ended_at')
                ->first();

            if ($lastSession && $plan->cooldown_minutes > 0) {
                /** @var Carbon $cooldownEnds */
                $cooldownEnds = $lastSession->ended_at->copy()->addMinutes($plan->cooldown_minutes);

                if (now()->lt($cooldownEnds)) {
                    return $this->deny(
                        self::REASON_COOLDOWN,
                        plan: $plan,
                        subscription: $subscription,
                        trialStatus: $trial->trial_status,
                        cooldownSecondsLeft: (int) round(now()->diffInSeconds($cooldownEnds)),
                    );
                }
            }

            $session = AiUsageSession::query()->create([
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'subscription_id' => $subscription->id,
                'started_at' => now(),
            ]);
        }

        $limitSeconds = max(0, $plan->usage_minutes) * 60;
        // Carbon 3's diff methods can return a float (sub-second
        // precision) - rounding to a whole second here is what keeps
        // every downstream consumer (the API response, the frontend's
        // mm:ss countdown) working with a clean integer instead of a
        // value like 2771.2152000000001 leaking straight into the UI.
        $elapsedSeconds = (int) round($session->started_at->diffInSeconds(now()));

        if ($limitSeconds > 0 && $elapsedSeconds >= $limitSeconds) {
            $session->ended_at = now();
            $session->duration_seconds = $elapsedSeconds;
            $session->save();

            if ($plan->is_trial) {
                $trial->trial_status = AiTrialControl::TRIAL_ENDED;
                $trial->save();
            }

            return $this->deny(
                self::REASON_LIMIT_REACHED,
                plan: $plan,
                subscription: $subscription,
                trialStatus: $trial->trial_status,
                cooldownSecondsLeft: $plan->cooldown_minutes * 60,
            );
        }

        return [
            'allowed' => true,
            'reason' => null,
            'plan' => $plan,
            'subscription' => $subscription,
            'session' => $session,
            'remaining_seconds' => $limitSeconds > 0 ? max(0, $limitSeconds - $elapsedSeconds) : null,
            'cooldown_seconds_left' => null,
            'trial_status' => $trial->trial_status,
        ];
    }

    /**
     * Called once a message has actually been processed, so the reported
     * remaining time reflects the extra seconds this turn just consumed
     * without waiting for the *next* evaluate() call to notice.
     */
    public function recordConsumption(AiUsageSession $session): void
    {
        $session->duration_seconds = (int) round($session->started_at->diffInSeconds(now()));
        $session->save();
    }

    /**
     * @return array{
     *     allowed: bool,
     *     reason: ?string,
     *     plan: ?AiPlan,
     *     subscription: ?AiSubscription,
     *     session: ?AiUsageSession,
     *     remaining_seconds: ?int,
     *     cooldown_seconds_left: ?int,
     *     trial_status: string,
     * }
     */
    protected function deny(
        string $reason,
        ?AiPlan $plan = null,
        ?AiSubscription $subscription = null,
        string $trialStatus = AiTrialControl::TRIAL_ELIGIBLE,
        ?int $cooldownSecondsLeft = null,
    ): array {
        return [
            'allowed' => false,
            'reason' => $reason,
            'plan' => $plan,
            'subscription' => $subscription,
            'session' => null,
            'remaining_seconds' => 0,
            'cooldown_seconds_left' => $cooldownSecondsLeft,
            'trial_status' => $trialStatus,
        ];
    }
}
