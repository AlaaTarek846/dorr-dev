<?php

namespace Modules\Freelance\Services\FreelancerTeam;

use Illuminate\Support\Facades\DB;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceTeamMember as Member;
use Modules\Freelance\Models\Freelancer;

/**
 * FS-204: what each role of a freelancers' team may do - one catalog, one question (`can`). The roles are Owner, PM and member (Admin,
 * Finance and Reviewer come later); the table is PERMISSIONS.md §2.
 *
 *  - The Owner may do everything, and some things only the Owner does - never given to anybody else: sending the delivery the client sees
 *    (`deliver`, decision D11 - not a setting, can not be delegated), changing who is in the team and their roles, the team's profile and
 *    signing for it.
 *  - Four abilities are switches the Owner sets for each PM or member (`permissions` on the member's row; empty = the role's default):
 *    seeing the project's price, the chat with the client, the team's internal files, inviting people. A switch is cleared when the role
 *    changes (FreelancerTeams::changeRole), so it never carries over to another role.
 *  - Everybody active in the team has the internal chat and may be given tasks.
 *
 * Money between the people (earnings, sharing) is out (FS-002); nothing here moves money.
 */
class TeamAbilities
{
    // The switches (⚙️ in PERMISSIONS.md).
    public const VIEW_PRICE = 'view_price';

    public const CLIENT_CHAT = 'client_chat';

    public const INTERNAL_FILES = 'internal_files';

    public const INVITE_MEMBERS = 'invite_members';

    /** What the Owner may switch on or off for a PM or a member. */
    public const SWITCHES = [self::VIEW_PRICE, self::CLIENT_CHAT, self::INTERNAL_FILES, self::INVITE_MEMBERS];

    // Fixed by the role.
    public const DELIVER = 'deliver';

    public const MANAGE_MEMBERS = 'manage_members';

    public const EDIT_TEAM = 'edit_team';

    public const SIGN_AGREEMENT = 'sign_agreement';

    public const INTERNAL_CHAT = 'internal_chat';

    public const TAKE_TASKS = 'take_tasks';

    /** Only the Owner, whatever is set (D11 for `deliver`). */
    public const OWNER_ONLY = [self::DELIVER, self::MANAGE_MEMBERS, self::EDIT_TEAM, self::SIGN_AGREEMENT];

    /** Everybody active in the team. */
    public const EVERYONE = [self::INTERNAL_CHAT, self::TAKE_TASKS];

    public const ALL = [...self::SWITCHES, ...self::OWNER_ONLY, ...self::EVERYONE];

    /** Each role's switches before the Owner touches them (a member starts with every switch off). */
    private const DEFAULTS = [
        Member::PM => [self::VIEW_PRICE => true, self::CLIENT_CHAT => true, self::INTERNAL_FILES => true, self::INVITE_MEMBERS => false],
        Member::MEMBER => [self::VIEW_PRICE => false, self::CLIENT_CHAT => false, self::INTERNAL_FILES => false, self::INVITE_MEMBERS => false],
    ];

    public function __construct(private readonly FreelancerTeams $teams) {}

    /** May this person do this in this team now? An unknown ability, a person not active in the team, or a team not active: no. */
    public function can(FreelanceTeam $team, Freelancer $who, string $ability): bool
    {
        if (! $team->isActive() || $team->trashed()) {
            return false;
        }
        $row = $this->teams->membership($team, $who);

        return $row !== null && ($this->of($row)[$ability] ?? false);
    }

    /** 403 freelancer_team_ability (with the ability) unless the person may. */
    public function assert(FreelanceTeam $team, Freelancer $who, string $ability): void
    {
        if (! $this->can($team, $who, $ability)) {
            throw new FreelanceException('freelancer_team_ability', 403, [], ['ability' => $ability]);
        }
    }

    /** @return array<string, bool> every ability of one member row, as it is now */
    public function of(Member $row): array
    {
        if (! $row->isActive()) {
            return array_fill_keys(self::ALL, false);
        }
        if ($row->role === Member::OWNER) {
            return array_fill_keys(self::ALL, true);
        }

        return array_merge(
            array_fill_keys(self::OWNER_ONLY, false),
            array_fill_keys(self::EVERYONE, true),
            $this->switches($row),
        );
    }

    /** @return array<string, bool> the four switches of a PM or a member: the role's default with what the Owner set over it */
    public function switches(Member $row): array
    {
        $defaults = self::DEFAULTS[$row->role] ?? self::DEFAULTS[Member::MEMBER];
        $set = array_intersect_key((array) ($row->permissions ?? []), $defaults);

        return array_map('boolval', array_merge($defaults, $set));
    }

    /**
     * The Owner sets some of a PM's or a member's switches; what is not named stays as it was, and a switch set back to the role's default is
     * not kept (so a later change of the default reaches him). 403 freelancer_team_forbidden (not an Owner), 404 freelancer_team_not_member,
     * 409 freelancer_team_inactive, 422 freelancer_team_permissions_owner (an Owner has every ability), 422 freelancer_team_permission_invalid
     * (not a switch - `deliver` among them, D11).
     *
     * @param  array<string, mixed>  $values
     * @return array{0: Member, 1: array<string, bool>} the row and the switches before
     */
    public function set(FreelanceTeam $team, Freelancer $by, Freelancer $target, array $values): array
    {
        foreach ($values as $key => $value) {
            if (! in_array($key, self::SWITCHES, true) || ! is_bool($value)) {
                throw new FreelanceException('freelancer_team_permission_invalid', 422, ['ability' => (string) $key], ['ability' => (string) $key]);
            }
        }

        return DB::transaction(function () use ($team, $by, $target, $values): array {
            $locked = FreelanceTeam::withTrashed()->lockForUpdate()->findOrFail($team->id);
            if ($locked->trashed() || ! $locked->isActive()) {
                throw new FreelanceException('freelancer_team_inactive', 409);
            }
            if (! $this->teams->isOwner($locked, $by)) {
                throw new FreelanceException('freelancer_team_forbidden', 403);
            }
            $row = Member::query()->where('team_id', $locked->id)->where('freelancer_id', $target->id)->where('status', Member::ACTIVE)->lockForUpdate()->first();
            if ($row === null) {
                throw new FreelanceException('freelancer_team_not_member', 404);
            }
            if ($row->role === Member::OWNER) {
                throw new FreelanceException('freelancer_team_permissions_owner', 422);
            }

            $before = $this->switches($row);
            $defaults = self::DEFAULTS[$row->role];
            $kept = array_filter(array_merge($before, $values), fn (bool $v, string $k) => $v !== $defaults[$k], ARRAY_FILTER_USE_BOTH);
            $row->forceFill(['permissions' => $kept === [] ? null : $kept])->save();

            return [$row, $before];
        });
    }
}
