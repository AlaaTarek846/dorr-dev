<?php

namespace Modules\Marketing\Services\Packages;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Marketing\Exceptions\MarketingException;
use Modules\Marketing\Models\MarketingJob;
use Modules\Marketing\Models\MarketingPackage;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Models\MarketingResearchRun;
use Modules\Marketing\Models\MarketingSubscription;

/**
 * What the owner's package allows now (D5). The project owner's package pays for the whole team: a manager's research
 * run counts against the owner's period. Reading never needs a package; making something new does.
 */
class MarketingEntitlement
{
    public function current(Model $owner): ?MarketingSubscription
    {
        return MarketingSubscription::query()->current()
            ->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->getKey())
            ->with('package')->orderByDesc('ends_at')->first();
    }

    /** The end of the last paid period, chained periods included — when a new purchase would start. */
    public function paidUntil(Model $owner): ?Carbon
    {
        $last = MarketingSubscription::query()->where('status', MarketingSubscription::ACTIVE)
            ->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->getKey())
            ->where('ends_at', '>', now())->orderByDesc('ends_at')->first();

        return $last?->ends_at;
    }

    /**
     * @return array{projects: int, research_runs: int, plan_generations: int, content_generations: int}
     */
    public function usage(Model $owner, ?MarketingSubscription $subscription): array
    {
        $projects = MarketingProject::query()->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->getKey());
        $since = $subscription?->starts_at;

        return [
            'projects' => (clone $projects)->where('status', MarketingProject::ACTIVE)->count(),
            'research_runs' => $since === null ? 0 : MarketingResearchRun::query()->whereIn('project_id', (clone $projects)->withTrashed()->select('id'))
                ->where('created_at', '>=', $since)->count(),
            'plan_generations' => $since === null ? 0 : MarketingJob::query()->whereIn('project_id', (clone $projects)->withTrashed()->select('id'))
                ->where('job_type', 'plan')->where('created_at', '>=', $since)->count(),
            'content_generations' => $since === null ? 0 : MarketingJob::query()->whereIn('project_id', (clone $projects)->withTrashed()->select('id'))
                ->whereIn('job_type', ['content', 'calendar'])->where('created_at', '>=', $since)->count(),
        ];
    }

    /**
     * Refuses unless the owner holds a current package with room for one more `$limit` (projects, research_runs,
     * plan_generations). `package_required` is 402 — the apps open the packages page.
     */
    public function assertRoom(Model $owner, ?string $limit = null): MarketingSubscription
    {
        $subscription = $this->current($owner) ?? throw new MarketingException('package_required', 402);

        if ($limit !== null) {
            $max = $subscription->package->limit($limit);
            $used = $this->usage($owner, $subscription)[$limit] ?? 0;
            if ($max !== null && $used >= $max) {
                throw new MarketingException('limit_reached', 429, ['limit' => $max], ['limit_key' => $limit, 'limit' => $max, 'used' => $used]);
            }
        }

        return $subscription;
    }

    /** Members per project the owner's package allows (the owner aside); null = no limit. */
    public function memberLimit(Model $owner): ?int
    {
        return $this->current($owner)?->package->limit('members');
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Model $owner): array
    {
        $subscription = $this->current($owner);
        $package = $subscription?->package;

        return [
            'active' => $subscription !== null,
            'package' => $package === null ? null : ['code' => $package->code, 'name' => $package->localized('name')],
            'starts_at' => $subscription?->starts_at?->toIso8601String(),
            'ends_at' => $subscription?->ends_at?->toIso8601String(),
            'paid_until' => $this->paidUntil($owner)?->toIso8601String(),
            'usage' => $this->usage($owner, $subscription),
            'limits' => $package === null ? null : collect(MarketingPackage::LIMITS)->mapWithKeys(fn ($k) => [$k => $package->limit($k)])->all(),
        ];
    }
}
