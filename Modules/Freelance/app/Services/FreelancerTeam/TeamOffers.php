<?php

namespace Modules\Freelance\Services\FreelancerTeam;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Database\Eloquent\Collection;
use Modules\Freelance\Enums\FreelanceOfferStatus as OfferStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceAgreementTeamMember;
use Modules\Freelance\Models\FreelanceOffer as Offer;
use Modules\Freelance\Models\FreelanceOfferTeamMember as Staff;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceTeamMember as Member;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceCountry;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;

/**
 * FS-205: an offer in a freelancers' team's name. The Owner who sends it is the one who signs and is paid (D2) - the offer and the agreement
 * keep him as `freelancer_id` and add the team. An Owner sends (and signs, or names another Owner), or a PM sends and names the Owner who signs (FS-206c: `submitted_by_freelancer_id`).
 *
 *  - Who works on it is chosen when it is sent: active people of the team, at most one PM of the project, each with what they take on. That
 *    list is a snapshot - the team changing later does not change the offer - and it is copied to the agreement when the agreement starts.
 *  - The offer is counted against the team's own monthly quota (policy `teams.offers_monthly`, empty = no cap, raised by the team's plan - FS-211),
 *    never the Owner's personal one; an offer taken back before the client saw it gives its place back. A team that already runs the most
 *    projects it may (`teams.projects_max`, or its plan's) sends no new offer until one is over (FS-211).
 *  - The client sees the team's name and the people who work on it - first name, role, what they take on - and nothing else of them (D10);
 *    each person is told they were put on the offer.
 */
class TeamOffers
{
    public const RESPONSIBILITY_MAX = 200;

    public const STAFF_MAX = 20;

    public function __construct(
        private readonly FreelancerTeams $teams,
        private readonly FreelancePolicies $policies,
        private readonly FreelanceFeatures $features,
        private readonly NotificationCenter $notifications,
        private readonly TeamPlans $plans,
    ) {}

    /**
     * The team an Owner or a PM sends an offer for, the Owner who signs it, and the people on it. The signer is the Owner who is paid (D2): an
     * Owner sending signs it himself unless he names another Owner; a PM must name one. When the offer is already sent (a change), its signer
     * is passed. 404 (not in the team), 403 team_offer_forbidden (a member), 403 feature_off, 409 freelancer_team_inactive, 422
     * team_offer_signer_required (a PM named nobody), 422 team_offer_signer_invalid (not an active Owner of the team), 422
     * team_offer_staff_invalid (not an active member / twice / more than one PM / too many).
     *
     * @param  list<array{freelancer: string, role: string, responsibility?: ?string}>  $staff
     * @return array{0: FreelanceTeam, 1: list<array{freelancer_id: int, role: string, responsibility: ?string}>, 2: Freelancer}
     */
    public function prepare(Freelancer $by, string $teamUuid, array $staff, ?string $signerUuid = null, ?Freelancer $signer = null): array
    {
        $team = FreelanceTeam::query()->where('uuid', $teamUuid)->first();
        if ($team === null || ! $this->teams->isMember($team, $by)) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException;
        }
        $row = $this->teams->membership($team, $by);
        if ($row === null || ! in_array($row->role, [Member::OWNER, Member::PM], true)) {
            throw new FreelanceException('team_offer_forbidden', 403);
        }
        $country = FreelanceCountry::of($by);
        if ($country === null || ! $this->features->allows($country, 'teams')) {
            throw new FreelanceException('feature_off', 403, ['feature' => 'teams'], ['feature' => 'teams']);
        }
        if (! $team->isActive()) {
            throw new FreelanceException('freelancer_team_inactive', 409);
        }
        if ($signer === null) {
            if ($signerUuid !== null && $signerUuid !== '') {
                $signer = Freelancer::query()->where('uuid', $signerUuid)->first();
                if ($signer === null || ! $this->teams->isOwner($team, $signer)) {
                    throw new FreelanceException('team_offer_signer_invalid', 422);
                }
            } elseif ($row->role === Member::OWNER) {
                $signer = $by;
            } else {
                throw new FreelanceException('team_offer_signer_required', 422);
            }
        }

        return [$team, $this->staff($team, $staff), $signer];
    }

    /** The Owner is told when a PM sent an offer in his name (he signs the agreement and is paid). */
    public function toldSigner(Offer $offer, FreelanceTeam $team, Freelancer $by, Freelancer $signer): void
    {
        $this->notifications->send(collect([$signer]), 'freelance.team_offer_submitted', 'freelance_team_offer_submitted_title', 'freelance_team_offer_submitted_body',
            ['name' => strtok((string) $by->name, ' ') ?: (string) $by->name, 'team' => $team->name, 'project' => (string) $offer->project?->title],
            ['type' => 'freelance_team_offer_submitted', 'team' => $team->uuid, 'offer' => $offer->uuid]);
    }

    /**
     * Whether these people are the ones already on the offer (to tell a real change from a repeat).
     *
     * @param  list<array{freelancer_id: int, role: string, responsibility: ?string}>  $rows
     */
    public function same(Offer $offer, array $rows): bool
    {
        $norm = fn (iterable $list) => collect($list)->map(fn ($r) => [(int) $r['freelancer_id'], (string) $r['role'], $r['responsibility'] ?? null])->sortBy(0)->values()->all();

        return $norm($offer->staff()->get()->map(fn (Staff $s) => ['freelancer_id' => $s->freelancer_id, 'role' => $s->role, 'responsibility' => $s->responsibility])) === $norm($rows);
    }

    /**
     * The people of a team's offer, checked against the team's active members.
     *
     * @param  list<array{freelancer: string, role: string, responsibility?: ?string}>  $staff
     * @return list<array{freelancer_id: int, role: string, responsibility: ?string}>
     */
    public function staff(FreelanceTeam $team, array $staff): array
    {
        if (count($staff) > self::STAFF_MAX) {
            throw new FreelanceException('team_offer_staff_invalid', 422);
        }
        $people = Freelancer::query()->whereIn('uuid', array_column($staff, 'freelancer'))
            ->whereIn('id', Member::query()->where('team_id', $team->id)->where('status', Member::ACTIVE)->select('freelancer_id'))->get()->keyBy('uuid');
        $rows = [];
        foreach ($staff as $s) {
            $person = $people->get($s['freelancer'] ?? '');
            if ($person === null || isset($rows[$person->id]) || ! in_array($s['role'] ?? '', Staff::ROLES, true)) {
                throw new FreelanceException('team_offer_staff_invalid', 422);
            }
            $text = isset($s['responsibility']) ? trim(strip_tags((string) $s['responsibility'])) : '';
            $rows[$person->id] = ['freelancer_id' => $person->id, 'role' => $s['role'], 'responsibility' => $text === '' ? null : mb_substr($text, 0, self::RESPONSIBILITY_MAX)];
        }
        if (collect($rows)->where('role', Staff::PM)->count() > 1) {
            throw new FreelanceException('team_offer_staff_invalid', 422);
        }

        return array_values($rows);
    }

    // -------------------------------------------------------------- the team's quota

    /** @return array{limit: ?int, used: int, left: ?int} this month's offers of the team (null = no cap) */
    public function quota(FreelanceTeam $team): array
    {
        // FS-211: the country's policy, raised (or lifted) by the team's plan when it has one.
        $limit = $this->plans->offersLimit($team);
        // An offer taken back before the client saw it gives its place back.
        $used = Offer::query()->where('team_id', $team->id)->where('created_at', '>=', now()->startOfMonth())
            ->where(fn ($q) => $q->where('status', '!=', OfferStatus::Withdrawn->value)->orWhereNotNull('viewed_at'))->count();

        return ['limit' => $limit, 'used' => $used, 'left' => $limit === null ? null : max(0, $limit - $used)];
    }

    /**
     * 422 team_projects_limit (the team already runs the most projects its plan allows - FS-211), 422 team_offer_quota_exhausted. Called with the
     * team locked, so two offers together can not spend the last place twice.
     */
    public function assertQuota(FreelanceTeam $team): void
    {
        $this->plans->assertProjectsRoom($team);
        $q = $this->quota($team);
        if ($q['limit'] !== null && $q['left'] <= 0) {
            throw new FreelanceException('team_offer_quota_exhausted', 422, ['limit' => $q['limit']], ['limit' => $q['limit']]);
        }
    }

    // -------------------------------------------------------------- the people

    /**
     * Writes the people of an offer (the whole list each time) and tells the ones who were not on it before.
     *
     * @param  list<array{freelancer_id: int, role: string, responsibility: ?string}>  $rows
     */
    public function save(Offer $offer, FreelanceTeam $team, array $rows, Freelancer $by): void
    {
        $before = $offer->staff()->pluck('freelancer_id')->all();
        $offer->staff()->delete();
        foreach ($rows as $row) {
            $offer->staff()->create($row);
        }
        $new = Freelancer::query()->whereIn('id', array_diff(array_column($rows, 'freelancer_id'), $before, [$by->id]))->get();
        if ($new->isNotEmpty()) {
            $this->notifications->send($new, 'freelance.team_offer_staffed', 'freelance_team_offer_staffed_title', 'freelance_team_offer_staffed_body',
                ['team' => $team->name, 'project' => (string) $offer->project?->title], ['type' => 'freelance_team_offer_staffed', 'team' => $team->uuid, 'offer' => $offer->uuid]);
        }
        $offer->unsetRelation('staff');
    }

    /** The offer's people become the agreement's, once, when the agreement starts. */
    public function copyToAgreement(Agreement $agreement): void
    {
        if ($agreement->team_id === null || $agreement->offer_id === null || FreelanceAgreementTeamMember::query()->where('agreement_id', $agreement->id)->exists()) {
            return;
        }
        Staff::query()->where('offer_id', $agreement->offer_id)->orderBy('id')->get()->each(
            fn (Staff $s) => FreelanceAgreementTeamMember::query()->create(['agreement_id' => $agreement->id, 'freelancer_id' => $s->freelancer_id, 'role' => $s->role, 'responsibility' => $s->responsibility]),
        );
    }

    // -------------------------------------------------------------- shape

    /**
     * What an offer or agreement says about its team, for one side. The freelancer's side (the Owner) sees the people in full; the client
     * sees the team's name and, of each person, the first name, the role and what they take on (D10).
     *
     * @param  Collection<int, Staff|FreelanceAgreementTeamMember>  $people
     * @return array<string, mixed>|null null when it is not a team's
     */
    public function present(?FreelanceTeam $team, Collection $people, string $for): ?array
    {
        if ($team === null) {
            return null;
        }
        $people->loadMissing('freelancer:id,uuid,name');

        return [
            'uuid' => $team->uuid,
            'name' => $team->name,
            // FS-202a: logo, badge, rating and done projects next to the name (the profile: `freelancer-teams/{uuid}` / `teams/{uuid}/profile`).
            'profile' => app(TeamProfile::class)->summary($team),
            'members' => $people->map(fn ($s) => [
                'name' => $for === 'client' ? (strtok((string) $s->freelancer?->name, ' ') ?: (string) $s->freelancer?->name) : (string) $s->freelancer?->name,
                'role' => $s->role,
                'responsibility' => $s->responsibility,
            ] + ($for === 'client' ? [] : ['freelancer' => $s->freelancer?->uuid]))->values()->all(),
        ];
    }
}
