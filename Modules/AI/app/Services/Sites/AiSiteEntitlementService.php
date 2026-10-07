<?php

namespace Modules\AI\Services\Sites;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\AI\Exceptions\AiSiteException;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiSitePurchase;
use Modules\AI\Models\AiSiteProject;
use Modules\AI\Models\AiSiteVersion;
use Modules\AI\Models\AiSubscription;

/**
 * Who may build or change a site: either through a plan that includes the
 * website builder (ai_plans.site_projects_limit / site_daily_generations), or
 * through a one-time purchase (ai_site_purchases) with its own generation
 * allowance.
 */
class AiSiteEntitlementService
{
    public function activePlanFor(Authenticatable $owner): ?AiPlan
    {
        return AiSubscription::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getAuthIdentifier())
            ->where('status', AiSubscription::STATUS_ACTIVE)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->with('plan')
            ->latest('id')
            ->first()?->plan;
    }

    public function planIncludesSites(?AiPlan $plan): bool
    {
        return $plan !== null && $plan->site_projects_limit > 0 && $plan->site_daily_generations > 0;
    }

    /** Builds + edits that count against the plan today (failed ones and restores do not). */
    public function usedToday(Authenticatable $owner): int
    {
        return AiSiteVersion::query()
            ->where('counted', true)
            ->where('status', '!=', AiSiteVersion::STATUS_FAILED)
            ->where('created_at', '>=', now()->startOfDay())
            ->whereHas('project', fn ($query) => $query
                ->withTrashed()
                ->where('owner_type', $owner->getMorphClass())
                ->where('owner_id', $owner->getAuthIdentifier())
                ->where('access_type', AiSiteProject::ACCESS_PLAN))
            ->count();
    }

    public function planProjectsOwned(Authenticatable $owner): int
    {
        return AiSiteProject::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getAuthIdentifier())
            ->where('access_type', AiSiteProject::ACCESS_PLAN)
            ->count();
    }

    /**
     * Reason code why the plan cannot start a new site, or null when it can.
     */
    public function planCreateRefusal(Authenticatable $owner, ?AiPlan $plan): ?string
    {
        if (! $this->planIncludesSites($plan)) {
            return 'not_in_plan';
        }

        if ($this->planProjectsOwned($owner) >= $plan->site_projects_limit) {
            return 'projects_limit_reached';
        }

        if ($this->usedToday($owner) >= $plan->site_daily_generations) {
            return 'daily_limit_reached';
        }

        return null;
    }

    /**
     * Whether an existing project may take one more AI build/edit. Throws a
     * refusal; the caller consumes purchase allowance itself under a lock.
     */
    public function assertCanGenerate(AiSiteProject $project, Authenticatable $owner): void
    {
        if ($project->access_type === AiSiteProject::ACCESS_PURCHASE) {
            $purchase = $project->purchase;

            if ($purchase === null || $purchase->status !== 'active' || $purchase->generationsLeft() <= 0) {
                throw new AiSiteException('purchase_exhausted', 402);
            }

            return;
        }

        $plan = $this->activePlanFor($owner);

        if (! $this->planIncludesSites($plan)) {
            throw new AiSiteException('not_in_plan', 403);
        }

        if ($this->usedToday($owner) >= $plan->site_daily_generations) {
            throw new AiSiteException('daily_limit_reached', 429);
        }
    }

    public function consumePurchase(AiSitePurchase $purchase): void
    {
        $locked = AiSitePurchase::query()->lockForUpdate()->findOrFail($purchase->id);

        if ($locked->status !== 'active' || $locked->generationsLeft() <= 0) {
            throw new AiSiteException('purchase_exhausted', 402);
        }

        $locked->increment('generations_used');
    }

    public function refundPurchase(AiSitePurchase $purchase): void
    {
        AiSitePurchase::query()->whereKey($purchase->id)->where('generations_used', '>', 0)->decrement('generations_used');
    }
}
