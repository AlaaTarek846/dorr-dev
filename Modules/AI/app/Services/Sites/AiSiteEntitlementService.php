<?php

namespace Modules\AI\Services\Sites;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\AI\Exceptions\AiSiteException;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiSiteProject;
use Modules\AI\Models\AiSitePurchase;
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
            ->whereNull('consumed_purchase_id') // paid by an edit pack: does not use the plan's daily allowance
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
     * Whether an existing project may take one more AI build/edit: its own allowance (the purchase, or the
     * plan's daily limit) first, then any edit pack bought for it. Throws a refusal; the caller reserves
     * the allowance itself under a lock (see reserve()).
     */
    public function assertCanGenerate(AiSiteProject $project, Authenticatable $owner): void
    {
        $refusal = $this->baseRefusal($project, $owner);

        if ($refusal !== null && $this->packGenerationsLeft($project) <= 0) {
            throw $refusal;
        }
    }

    /** The refusal from the site's own allowance (null = it still has some). */
    protected function baseRefusal(AiSiteProject $project, Authenticatable $owner): ?AiSiteException
    {
        if ($project->access_type === AiSiteProject::ACCESS_PURCHASE) {
            $purchase = $project->purchase;

            return ($purchase === null || $purchase->status !== 'active' || $purchase->generationsLeft() <= 0)
                ? new AiSiteException('purchase_exhausted', 402)
                : null;
        }

        $plan = $this->activePlanFor($owner);

        if (! $this->planIncludesSites($plan)) {
            return new AiSiteException('not_in_plan', 403);
        }

        return $this->usedToday($owner) >= $plan->site_daily_generations
            ? new AiSiteException('daily_limit_reached', 429)
            : null;
    }

    /** Builds/edits left in the edit packs bought for this site. */
    public function packGenerationsLeft(AiSiteProject $project): int
    {
        return (int) $project->editPacks()->where('status', 'active')
            ->selectRaw('COALESCE(SUM(generations_included - generations_used), 0) as left_total')
            ->value('left_total');
    }

    /**
     * What the customer can still do on this site: `base` is the site's own allowance (purchase left, or the
     * plan's remaining daily builds), `packs` is what the edit packs hold.
     *
     * @return array{base: int, packs: int, total: int, blocked: bool, reason: ?string}
     */
    public function editsLeft(AiSiteProject $project, Authenticatable $owner): array
    {
        $refusal = $this->baseRefusal($project, $owner);

        if ($project->access_type === AiSiteProject::ACCESS_PURCHASE) {
            $base = (int) ($project->purchase?->status === 'active' ? $project->purchase->generationsLeft() : 0);
        } else {
            $plan = $this->activePlanFor($owner);
            $base = $this->planIncludesSites($plan) ? max(0, $plan->site_daily_generations - $this->usedToday($owner)) : 0;
        }

        $packs = $this->packGenerationsLeft($project);

        return [
            'base' => $base,
            'packs' => $packs,
            'total' => $base + $packs,
            'blocked' => $base + $packs <= 0,
            'reason' => $refusal?->reason,
        ];
    }

    /**
     * Takes one build/edit from where it is available - the site's own purchase, else an edit pack - and
     * returns the purchase id it was taken from (null when the plan's daily allowance pays, which is
     * counted through the version itself). Throws the refusal when nothing is left. Call under a lock.
     */
    public function reserve(AiSiteProject $project, Authenticatable $owner): ?int
    {
        $refusal = $this->baseRefusal($project, $owner);

        if ($refusal === null) {
            if ($project->access_type === AiSiteProject::ACCESS_PURCHASE && $project->purchase !== null) {
                $this->consumePurchase($project->purchase);

                return $project->purchase->id;
            }

            return null;
        }

        $pack = $project->editPacks()->where('status', 'active')
            ->whereColumn('generations_used', '<', 'generations_included')
            ->orderBy('id')->lockForUpdate()->first();

        if ($pack === null) {
            throw $refusal;
        }

        $pack->increment('generations_used');

        return $pack->id;
    }

    /** Gives back what a failed build/edit took, to the purchase that paid for it. */
    public function refundVersion(AiSiteVersion $version): void
    {
        if ($version->consumed_purchase_id !== null) {
            AiSitePurchase::query()->whereKey($version->consumed_purchase_id)->where('generations_used', '>', 0)->decrement('generations_used');
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
