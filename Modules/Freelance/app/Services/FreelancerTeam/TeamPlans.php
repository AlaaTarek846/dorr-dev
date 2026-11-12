<?php

namespace Modules\Freelance\Services\FreelancerTeam;

use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelancePlan;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceTeamMember as Member;
use Modules\Freelance\Models\FreelanceTeamSubscription;
use Modules\Freelance\Services\Plans\PlanFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;

/**
 * FS-211: what a freelancers' team may hold, with its plan. A team with no live subscription is under the country's policy (the free baseline:
 * `teams.members_max`, `teams.projects_max`, `teams.offers_monthly`, each "empty = no cap"). A team on a plan (Team Starter...) gets the plan's
 * room instead - but a plan only ever raises what the policy gives, never lowers it:
 *
 *   - policy empty (no cap)  -> still no cap, whatever the plan says;
 *   - plan empty (members / projects) or `offers_unlimited` (offers) -> no cap;
 *   - otherwise the larger of the policy's number and the plan's.
 *
 * What a limit stops is the *new* thing - a member joining, an offer going out - and never what is already there: lowering a plan or a policy
 * does not remove a person or cut a running project. A subscription gives its plan while it is paid for (see `FreelanceTeamSubscription::gives`),
 * even if the admin has since stopped offering that plan; it ends at its next renewal.
 */
class TeamPlans
{
    public function __construct(private readonly FreelancePolicies $policies) {}

    /** The subscription whose plan the team has right now (null: none - it is on the free baseline). */
    public function active(FreelanceTeam $team): ?FreelanceTeamSubscription
    {
        $sub = FreelanceTeamSubscription::query()->live()->where('team_id', $team->id)->with('plan')->latest('id')->first();

        return $sub !== null && $sub->gives() && $sub->plan !== null ? $sub : null;
    }

    public function plan(FreelanceTeam $team): ?FreelancePlan
    {
        return $this->active($team)?->plan;
    }

    /**
     * The room the team has now; `null` = no cap.
     *
     * @return array{members: ?int, projects: ?int, offers: ?int}
     */
    public function limits(FreelanceTeam $team): array
    {
        return $this->limitsFor($team, $this->plan($team));
    }

    /**
     * The room the team would have on a plan (null = the free baseline: the policy alone). `null` = no cap.
     *
     * @return array{members: ?int, projects: ?int, offers: ?int}
     */
    public function limitsFor(FreelanceTeam $team, ?FreelancePlan $plan): array
    {
        // FS-705: the same rule as every plan feature (`PlanFeatures`): a plan raises the policy and never lowers it.
        $features = app(PlanFeatures::class);
        $value = fn (string $key): ?int => $features->value($plan, $key, FreelancePlan::TEAM, (int) $team->country_id);

        return ['members' => $value('team_members_max'), 'projects' => $value('team_projects_max'), 'offers' => $value('offers_monthly')];
    }

    /**
     * Whether a plan gives the team more than the free baseline on something that works today. A plan that opens nothing (every policy
     * still empty, so there is no cap to lift, and its other perks not wired yet) is not sold: nobody should pay for what they already have.
     */
    public function raises(FreelanceTeam $team, FreelancePlan $plan): bool
    {
        return app(PlanFeatures::class)->sellable($plan, FreelancePlan::TEAM, (int) $team->country_id);
    }

    /** The most active people the team holds (null = no cap). */
    public function membersMax(FreelanceTeam $team): ?int
    {
        return $this->limits($team)['members'];
    }

    /** The most offers the team sends in a calendar month (null = no cap). */
    public function offersLimit(FreelanceTeam $team): ?int
    {
        return $this->limits($team)['offers'];
    }

    /** The most projects the team runs at the same time (null = no cap). */
    public function projectsMax(FreelanceTeam $team): ?int
    {
        return $this->limits($team)['projects'];
    }

    /** Projects running now: agreements of the team that are `active`. */
    public function running(FreelanceTeam $team): int
    {
        return Agreement::query()->where('team_id', $team->id)->where('status', Agreement::ACTIVE)->count();
    }

    /**
     * 422 team_projects_limit when the team already runs the most projects it may. Stops a new offer in the team's name; the ones running and
     * the offers already out are not touched.
     */
    public function assertProjectsRoom(FreelanceTeam $team): void
    {
        $max = $this->projectsMax($team);
        if ($max !== null && $this->running($team) >= $max) {
            throw new FreelanceException('team_projects_limit', 422, ['max' => $max], ['max' => $max] + app(PlanFeatures::class)->upgradeHint($team, 'team_projects_max', $max));
        }
    }

    /**
     * What the team holds against its room, for its screen and the admin's.
     *
     * @return array{plan: array{slug: ?string, name: ?string, free: bool}, members: array{used: int, max: ?int}, projects: array{used: int, max: ?int}, offers: array{used: int, limit: ?int, left: ?int}}
     */
    public function usage(FreelanceTeam $team): array
    {
        $limits = $this->limits($team);
        $plan = $this->plan($team);
        $offers = app(TeamOffers::class)->quota($team);

        return [
            'plan' => ['slug' => $plan?->slug, 'name' => $plan?->text('name'), 'free' => $plan === null],
            'members' => ['used' => Member::query()->where('team_id', $team->id)->where('status', Member::ACTIVE)->count(), 'max' => $limits['members']],
            'projects' => ['used' => $this->running($team), 'max' => $limits['projects']],
            'offers' => ['used' => $offers['used'], 'limit' => $limits['offers'], 'left' => $offers['left']],
        ];
    }
}
