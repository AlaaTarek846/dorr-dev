<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Services\General\AuditLogger;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceTeamMember as Member;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\FreelancerTeam\FreelancerTeams;
use Modules\Freelance\Services\FreelancerTeam\TeamAbilities;
use Modules\Freelance\Services\FreelancerTeam\TeamInvitations;

/**
 * FS-204: a team's people with their roles and what each may do, for its active people, and the Owner's switches for a PM or a member,
 * under `freelance/v1/teams/{team}/members` behind the `teams` switch. A person not in the team gets 404, like the team's chat.
 */
class TeamMemberController extends Controller
{
    public const PERMISSIONS_CHANGED = 'freelance.team.permissions_changed';

    public const ROLE_CHANGED = 'freelance.team.role_changed';

    public function __construct(
        private readonly FreelancerTeams $teams,
        private readonly TeamAbilities $abilities,
        private readonly AuditLogger $audit,
        private readonly TeamInvitations $invitations,
    ) {}

    /** GET teams/{team}/members - the active people (Owners first), each with role and abilities; mine too. */
    public function index(Request $request, string $team): JsonResponse
    {
        $me = $this->me($request);
        $t = $this->team($me, $team);
        $rows = Member::query()->where('team_id', $t->id)->where('status', Member::ACTIVE)->with('freelancer:id,uuid,name')
            ->orderByRaw("case role when 'owner' then 0 when 'pm' then 1 else 2 end")->orderBy('id')->get();
        $mine = $rows->firstWhere('freelancer_id', $me->id);

        return ApiResponse::success([
            'members' => $rows->map(fn (Member $m) => $this->present($m))->values(),
            'me' => ['role' => $mine?->role, 'abilities' => $this->abilities->of($mine)],
            'switches' => TeamAbilities::SWITCHES,
        ], __('api.retrieved'));
    }

    /** PUT teams/{team}/members/{freelancer}/permissions {permissions: {view_price?, client_chat?, internal_files?, invite_members?: bool}} - Owner only. */
    public function permissions(Request $request, string $team, string $freelancer): JsonResponse
    {
        $data = $request->validate(['permissions' => ['required', 'array', 'min:1']]);
        $me = $this->me($request);
        $t = $this->team($me, $team);
        $target = Freelancer::query()->where('uuid', $freelancer)->firstOrFail();

        [$row, $before] = $this->abilities->set($t, $me, $target, $data['permissions']);
        $after = $this->abilities->switches($row);
        if ($after !== $before) {
            $this->audit->record(self::PERMISSIONS_CHANGED, $row, $before, $after + ['team' => $t->uuid, 'by_freelancer_id' => $me->id]);
        }

        return ApiResponse::success(['member' => $this->present($row->load('freelancer:id,uuid,name'))], __('freelance.team_permissions_saved'));
    }

    /**
     * PUT teams/{team}/members/{freelancer}/role {role: owner|pm|member} (FS-209) - an Owner only; the way ownership is passed on. The person's own
     * switches are cleared (they were set for the old role). See FreelancerTeams::changeRole for the errors.
     */
    public function role(Request $request, string $team, string $freelancer): JsonResponse
    {
        $data = $request->validate(['role' => ['required', 'string', Rule::in(Member::ROLES)]]);
        $me = $this->me($request);
        $t = $this->team($me, $team);
        $target = Freelancer::query()->where('uuid', $freelancer)->firstOrFail();

        $before = $this->teams->membership($t, $target)?->role;
        $row = $this->teams->changeRole($t, $me, $target, $data['role']);
        if ($before !== $row->role) {
            $this->audit->record(self::ROLE_CHANGED, $row, ['role' => $before], ['role' => $row->role, 'team' => $t->uuid, 'by_freelancer_id' => $me->id]);
        }

        return ApiResponse::success(['member' => $this->present($row->load('freelancer:id,uuid,name'))], __('freelance.team_role_saved'));
    }

    /** POST teams/{team}/leave {reason?} (FS-203) - at once (D5), even a suspended team; the last Owner can not (409). */
    public function leave(Request $request, string $team): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);
        $me = $this->me($request);
        $this->invitations->leave($this->team($me, $team), $me, $data['reason'] ?? null);

        return ApiResponse::success(null, __('freelance.team_left'));
    }

    /** DELETE teams/{team}/members/{freelancer} {reason?} (FS-203) - an Owner removes somebody else. */
    public function remove(Request $request, string $team, string $freelancer): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);
        $me = $this->me($request);
        $t = $this->team($me, $team);
        $target = Freelancer::query()->where('uuid', $freelancer)->firstOrFail();
        $this->invitations->remove($t, $me, $target, $data['reason'] ?? null);

        return ApiResponse::success(null, __('freelance.team_member_removed'));
    }

    /** @return array<string, mixed> */
    private function present(Member $m): array
    {
        return [
            'freelancer' => ['uuid' => $m->freelancer?->uuid, 'name' => $m->freelancer?->name],
            'role' => $m->role,
            'joined_at' => $m->joined_at?->toIso8601String(),
            'abilities' => $this->abilities->of($m),
            // The switches the Owner set on purpose (the rest follow the role's default); null for an Owner.
            'custom' => $m->role === Member::OWNER ? null : (object) ((array) ($m->permissions ?? [])),
        ];
    }

    private function team(Freelancer $me, string $uuid): FreelanceTeam
    {
        $team = FreelanceTeam::query()->where('uuid', $uuid)->firstOrFail();
        if (! $this->teams->isMember($team, $me)) {
            throw new ModelNotFoundException;
        }

        return $team;
    }

    private function me(Request $request): Freelancer
    {
        return $request->user('freelance_api');
    }
}
