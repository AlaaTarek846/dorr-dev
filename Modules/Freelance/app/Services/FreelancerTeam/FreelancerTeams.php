<?php

namespace Modules\Freelance\Services\FreelancerTeam;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceTeamMember as Member;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\Freelance\Services\Verification\FreelancerGate;

/**
 * The rules of a freelancers' team (FS-201): who may be in it, in which role, and how many. Nothing here talks to a screen - the invitations
 * that bring people in are FS-203, the roles' permissions FS-204 (TeamAbilities) - it is what those steps call so the rules live in one place.
 *
 *  - A team always has an active Owner at least (D6): the last one can neither leave, nor be removed, nor step down - ownership is passed
 *    first (promote another member to Owner, then step down or leave).
 *  - A person is the Owner of at most `teams.owned_max` teams (one at first, D1) and is a PM or member of at most `teams.joined_max` others
 *    (five at first, D1), a team holds at most `teams.members_max` active people (no cap at first): admin policies, empty = no cap. A limit
 *    stops new additions only; what is there stays.
 *  - Only a verified freelancer creates or joins a team (D4: the Owner is verified, and so is every member).
 *  - A person leaves at once whenever he wants (D5); the Owner removes a member. Nobody's row is deleted: it turns `left` / `removed`, and coming
 *    back turns the same row `active` again.
 *  - A suspended or archived team takes no new member and changes nothing - but its people can still leave it.
 *
 * Every write locks the person first and then the team, so two steps for the same person or the same team are counted one at a time.
 */
class FreelancerTeams
{
    public const NAME_MIN = 3;

    public const NAME_MAX = 80;

    public const REASON_MAX = 100;

    public function __construct(
        private readonly FreelancePolicies $policies,
        private readonly FreelancerGate $gate,
    ) {}

    // -------------------------------------------------------------- reading

    /** The person's place in the team while it lasts (`active`), or null. */
    public function membership(FreelanceTeam $team, Freelancer $who): ?Member
    {
        return Member::query()->where('team_id', $team->id)->where('freelancer_id', $who->id)->where('status', Member::ACTIVE)->first();
    }

    public function isMember(FreelanceTeam $team, Freelancer $who): bool
    {
        return $this->membership($team, $who) !== null;
    }

    public function isOwner(FreelanceTeam $team, Freelancer $who): bool
    {
        return $this->membership($team, $who)?->role === Member::OWNER;
    }

    /**
     * The teams the person is in now, with the team loaded (a team that was deleted is not listed).
     *
     * @return Collection<int, Member>
     */
    public function memberships(Freelancer $who): Collection
    {
        return Member::query()->where('freelancer_id', $who->id)->where('status', Member::ACTIVE)->whereHas('team')->with('team')->orderBy('id')->get();
    }

    /**
     * What the person holds against the admin's limits: the teams he owns and the teams he is only a PM or member of.
     *
     * @return array{owned: array{used: int, max: ?int}, joined: array{used: int, max: ?int}}
     */
    public function limits(Freelancer $who): array
    {
        return [
            'owned' => ['used' => $this->count($who, true), 'max' => $this->max($who, 'teams.owned_max')],
            'joined' => ['used' => $this->count($who, false), 'max' => $this->max($who, 'teams.joined_max')],
        ];
    }

    /** The most active people one team holds (null = no cap): the country's policy, raised by the team's plan when it has one (FS-211). */
    public function maxMembers(FreelanceTeam $team): ?int
    {
        return app(TeamPlans::class)->membersMax($team);
    }

    // -------------------------------------------------------------- writing

    /**
     * A new team with its creator as the Owner, in the creator's country. 403 freelance_verification_required (not verified),
     * 422 freelancer_team_name_required, 422 freelancer_team_limit_owned (he owns the most teams he may).
     */
    public function create(Freelancer $creator, string $name): FreelanceTeam
    {
        $this->gate->assertCanApply($creator);
        $clean = $this->name($name);

        return DB::transaction(function () use ($creator, $clean): FreelanceTeam {
            $this->lockPerson($creator);
            $this->assertPersonRoom($creator, Member::OWNER);

            $team = FreelanceTeam::query()->create([
                'country_id' => (int) $creator->country_id,
                'name' => $clean,
                'status' => FreelanceTeam::ACTIVE,
                'created_by_freelancer_id' => $creator->id,
            ]);
            Member::query()->create(['team_id' => $team->id, 'freelancer_id' => $creator->id, 'role' => Member::OWNER, 'status' => Member::ACTIVE, 'joined_at' => now()]);

            return $team;
        });
    }

    /**
     * A person comes into a team as a PM or a member (the Owner role is only given by `changeRole`). Somebody who left or was removed comes
     * back on the same row. 409 freelancer_team_already_member, 409 freelancer_team_inactive, 422 freelancer_team_limit_joined /
     * freelancer_team_limit_members, 403 freelance_verification_required.
     */
    public function join(FreelanceTeam $team, Freelancer $who, string $role = Member::MEMBER): Member
    {
        if (! in_array($role, [Member::PM, Member::MEMBER], true)) {
            throw new FreelanceException('freelancer_team_role_invalid', 422);
        }
        $this->gate->assertCanApply($who);

        return DB::transaction(function () use ($team, $who, $role): Member {
            $this->lockPerson($who);
            $team = $this->lockTeam($team);
            $row = Member::query()->where('team_id', $team->id)->where('freelancer_id', $who->id)->lockForUpdate()->first();
            if ($row?->isActive()) {
                throw new FreelanceException('freelancer_team_already_member', 409);
            }
            $this->assertPersonRoom($who, $role);
            $this->assertTeamRoom($team);

            // A fresh start: the old permissions, the reason he left and who removed him do not come back with him.
            $fresh = ['role' => $role, 'status' => Member::ACTIVE, 'permissions' => null, 'joined_at' => now(), 'left_at' => null, 'left_reason' => null, 'removed_by_freelancer_id' => null];
            if ($row === null) {
                return Member::query()->create(['team_id' => $team->id, 'freelancer_id' => $who->id] + $fresh);
            }
            $row->forceFill($fresh)->save();

            return $row;
        });
    }

    /** The person leaves at once (D5), even a suspended or archived team. 404 freelancer_team_not_member, 409 freelancer_team_last_owner. */
    public function leave(FreelanceTeam $team, Freelancer $who, ?string $reason = null): Member
    {
        return DB::transaction(function () use ($team, $who, $reason): Member {
            $this->lockPerson($who);
            $team = $this->lockTeam($team, false);
            $row = $this->lockedMembership($team, $who);
            $this->assertNotLastOwner($team, $row);

            $row->forceFill(['status' => Member::LEFT, 'left_at' => now(), 'left_reason' => $this->reason($reason)])->save();

            return $row;
        });
    }

    /**
     * An Owner removes somebody else from the team. 403 freelancer_team_forbidden (not an Owner, or himself - he leaves instead),
     * 404 freelancer_team_not_member, 409 freelancer_team_inactive.
     */
    public function remove(FreelanceTeam $team, Freelancer $by, Freelancer $target, ?string $reason = null): Member
    {
        return DB::transaction(function () use ($team, $by, $target, $reason): Member {
            $this->lockPerson($target);
            $team = $this->lockTeam($team);
            $this->assertOwner($team, $by);
            if ($by->id === $target->id) {
                throw new FreelanceException('freelancer_team_forbidden', 403);
            }
            $row = $this->lockedMembership($team, $target);
            // The Owner doing this is one, so removing another Owner never leaves the team without one.
            $row->forceFill(['status' => Member::REMOVED, 'left_at' => now(), 'left_reason' => $this->reason($reason), 'removed_by_freelancer_id' => $by->id])->save();

            return $row;
        });
    }

    /**
     * FS-208: staff take somebody out of the team (even a suspended one, even an Owner when another stays): the team never ends with no Owner
     * (D6). 404 freelancer_team_not_member, 409 freelancer_team_last_owner.
     */
    public function removeByStaff(FreelanceTeam $team, Freelancer $target, ?string $reason = null): Member
    {
        return DB::transaction(function () use ($team, $target, $reason): Member {
            $this->lockPerson($target);
            $team = $this->lockTeam($team, false);
            $row = $this->lockedMembership($team, $target);
            $this->assertNotLastOwner($team, $row);
            $row->forceFill(['status' => Member::REMOVED, 'left_at' => now(), 'left_reason' => $this->reason($reason), 'removed_by_freelancer_id' => null])->save();

            return $row;
        });
    }

    /**
     * An Owner changes the role of a member (himself included): promoting somebody to Owner is how ownership is passed. The member's own
     * permissions are cleared - they were set for the old role. 403 freelancer_team_forbidden, 404 freelancer_team_not_member,
     * 409 freelancer_team_last_owner (the last Owner can not step down), 422 freelancer_team_role_invalid, 422 freelancer_team_limit_owned /
     * freelancer_team_limit_joined (the person's own limits), 403 freelance_verification_required (a new Owner is verified).
     */
    public function changeRole(FreelanceTeam $team, Freelancer $by, Freelancer $target, string $role): Member
    {
        if (! in_array($role, Member::ROLES, true)) {
            throw new FreelanceException('freelancer_team_role_invalid', 422);
        }

        return DB::transaction(function () use ($team, $by, $target, $role): Member {
            $this->lockPerson($target);
            $team = $this->lockTeam($team);
            $this->assertOwner($team, $by);
            $row = $this->lockedMembership($team, $target);
            if ($row->role === $role) {
                return $row;
            }

            if ($role === Member::OWNER) {
                $this->gate->assertCanApply($target);
                $this->assertPersonRoom($target, Member::OWNER);
            } elseif ($row->role === Member::OWNER) {
                // Stepping down turns an owned team into a joined one.
                $this->assertNotLastOwner($team, $row);
                $this->assertPersonRoom($target, $role);
            }
            $row->forceFill(['role' => $role, 'permissions' => null])->save();

            return $row;
        });
    }

    // -------------------------------------------------------------- inside

    /** The person's row is locked first, so his own counts (teams owned / joined) are read one step at a time. */
    private function lockPerson(Freelancer $who): void
    {
        Freelancer::query()->lockForUpdate()->find($who->id);
    }

    private function lockTeam(FreelanceTeam $team, bool $mustBeActive = true): FreelanceTeam
    {
        $locked = FreelanceTeam::withTrashed()->lockForUpdate()->findOrFail($team->id);
        if ($mustBeActive && ($locked->trashed() || ! $locked->isActive())) {
            throw new FreelanceException('freelancer_team_inactive', 409);
        }

        return $locked;
    }

    /** The person's active place in the (locked) team, locked itself; 404 when he is not in it. */
    private function lockedMembership(FreelanceTeam $team, Freelancer $who): Member
    {
        $row = Member::query()->where('team_id', $team->id)->where('freelancer_id', $who->id)->where('status', Member::ACTIVE)->lockForUpdate()->first();
        if ($row === null) {
            throw new FreelanceException('freelancer_team_not_member', 404);
        }

        return $row;
    }

    private function assertOwner(FreelanceTeam $team, Freelancer $who): void
    {
        $owner = Member::query()->where('team_id', $team->id)->where('freelancer_id', $who->id)->where('status', Member::ACTIVE)->where('role', Member::OWNER)->exists();
        if (! $owner) {
            throw new FreelanceException('freelancer_team_forbidden', 403);
        }
    }

    /** Called inside the team's lock: an Owner who is the only one can not go. */
    private function assertNotLastOwner(FreelanceTeam $team, Member $row): void
    {
        if ($row->role !== Member::OWNER) {
            return;
        }
        $owners = Member::query()->where('team_id', $team->id)->where('status', Member::ACTIVE)->where('role', Member::OWNER)->count();
        if ($owners <= 1) {
            throw new FreelanceException('freelancer_team_last_owner', 409);
        }
    }

    /** The person's own limits: teams he owns (as an Owner) or only joined (as a PM or member). */
    private function assertPersonRoom(Freelancer $who, string $role): void
    {
        $owner = $role === Member::OWNER;
        $max = $this->max($who, $owner ? 'teams.owned_max' : 'teams.joined_max');
        if ($max !== null && $this->count($who, $owner) >= $max) {
            throw new FreelanceException($owner ? 'freelancer_team_limit_owned' : 'freelancer_team_limit_joined', 422, ['max' => $max], ['max' => $max]);
        }
    }

    private function assertTeamRoom(FreelanceTeam $team): void
    {
        $max = $this->maxMembers($team);
        if ($max !== null && $team->activeMembers()->count() >= $max) {
            throw new FreelanceException('freelancer_team_limit_members', 422, ['max' => $max], ['max' => $max]);
        }
    }

    private function count(Freelancer $who, bool $owner): int
    {
        return Member::query()->where('freelancer_id', $who->id)->where('status', Member::ACTIVE)->whereHas('team')
            ->where('role', $owner ? '=' : '!=', Member::OWNER)->count();
    }

    private function max(Freelancer $who, string $key): ?int
    {
        $max = $this->policies->for((int) $who->country_id)->get($key);

        return $max === null ? null : (int) $max;
    }

    /** The team's name cleaned (tags and extra spaces out); 422 freelancer_team_name_required when too short. */
    public function cleanName(string $name): string
    {
        return $this->name($name);
    }

    private function name(string $name): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($name)));
        if (mb_strlen($text) < self::NAME_MIN) {
            throw new FreelanceException('freelancer_team_name_required', 422);
        }

        return mb_substr($text, 0, self::NAME_MAX);
    }

    private function reason(?string $reason): ?string
    {
        $text = $reason === null ? '' : trim(strip_tags($reason));

        return $text === '' ? null : mb_substr($text, 0, self::REASON_MAX);
    }
}
