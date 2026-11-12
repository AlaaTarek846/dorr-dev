<?php

namespace Modules\Freelance\Services\Plans;

use Modules\Freelance\Models\FreelancePlan;
use Modules\Freelance\Models\FreelanceSubscription;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\FreelancerTeam\TeamPlans;

/**
 * Which plan a freelancer is on and what it changes (FL-L01). A freelancer with no live subscription — or in a country where the
 * `subscriptions` switch is off — is on the Free plan, which changes nothing: the country's policy rules as before.
 *
 * What a paid plan changes, and only these two:
 *   - the free offers per month: the plan's number replaces the policy's when it is higher, or lifts the limit;
 *   - the commission: the plan's rate replaces the policy's percent when it is lower — a plan never raises a commission.
 * An agreement copies its commission when it is made (FL-401), so a later change of plan never moves an agreed price.
 */
class FreelancePlans
{
    public function __construct(private readonly FreelanceFeatures $features) {}

    public function enabled(int $countryId): bool
    {
        return $this->features->allows($countryId, 'subscriptions');
    }

    /** The plan with this slug (Free is made up when it was never seeded, so nothing depends on the seeder having run). */
    public function bySlug(string $slug): ?FreelancePlan
    {
        $plan = FreelancePlan::query()->where('slug', $slug)->first();
        if ($plan === null && $slug === FreelancePlan::FREE) {
            $plan = new FreelancePlan(['slug' => FreelancePlan::FREE, 'rank' => 0, 'name' => (array) config('freelance.plans.free.name'), 'status' => true]);
        }

        return $plan;
    }

    /** The subscription whose plan the freelancer has right now (null: none). */
    public function active(Freelancer $freelancer): ?FreelanceSubscription
    {
        $sub = FreelanceSubscription::query()->live()->where('freelancer_id', $freelancer->id)->with('plan')->latest('id')->first();

        return $sub !== null && $this->gives($sub) ? $sub : null;
    }

    /**
     * Whether a live subscription still gives its plan. A trial or paid period gives it until it ends — and past its end while it waits for
     * the renewal to run (auto-renew on); a failed renewal gives it through the grace days only.
     */
    public function gives(FreelanceSubscription $sub): bool
    {
        if ($sub->status === FreelanceSubscription::PAST_DUE) {
            return $sub->grace_until !== null && $sub->grace_until->isFuture();
        }

        return $sub->isLive() && ($sub->period_end->isFuture() || $sub->auto_renew);
    }

    public function planFor(Freelancer $freelancer): FreelancePlan
    {
        if ($freelancer->country_id !== null && $this->enabled((int) $freelancer->country_id)) {
            $plan = $this->active($freelancer)?->plan;
            if ($plan !== null && $plan->status) {
                return $plan;
            }
        }

        return $this->bySlug(FreelancePlan::FREE);
    }

    /**
     * The monthly free offers a freelancer has under their plan: null = no limit, else the larger of the policy's number and the plan's.
     *
     * @param  int  $policyLimit  `offers.free_monthly_quota` of the country
     */
    public function offersLimit(Freelancer $freelancer, int $policyLimit): ?int
    {
        $plan = $this->planFor($freelancer);
        if ($plan->offers_unlimited) {
            return null;
        }

        return $plan->offers_monthly === null ? $policyLimit : max($policyLimit, (int) $plan->offers_monthly);
    }

    /**
     * The policy values with the plan's commission put in (only ever a lower percent). Pass the merged policy of the project's country.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function withCommission(array $values, ?Freelancer $freelancer, ?FreelanceTeam $team = null): array
    {
        if ($freelancer === null && $team === null) {
            return $values;
        }
        // FS-705 (owner): an offer or agreement in a team's name is charged under the TEAM's plan only - never the Owner's personal one.
        $bp = $team !== null ? app(TeamPlans::class)->plan($team)?->commission_bp : $this->planFor($freelancer)->commission_bp;
        $policyIsPercent = ($values['commission.type'] ?? 'percent') === 'percent';
        if ($bp !== null && $policyIsPercent && $bp < (int) ($values['commission.value'] ?? 0)) {
            $values['commission.value'] = $bp;
        }

        return $values;
    }
}
