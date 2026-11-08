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

    public const TITLE_CHANGED = 'freelance.team.job_title_changed';

    public const TITLE_MAX = 60;

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
        // How many agreements of the team each Owner signed that have not ended, and how many offers in its name he has open - such an Owner
        // can not leave or step down (FS-212, D12).
        $signed = $this->teams->liveAgreementsBySigner($t);
        $open = $this->teams->openOffersBySigner($t);

        return ApiResponse::success([
            'members' => $rows->map(fn (Member $m) => $this->present($m, $signed, $open))->values(),
            'me' => ['role' => $mine?->role, 'abilities' => $this->abilities->of($mine), 'signed_agreements' => $signed[$me->id] ?? 0, 'open_offers' => $open[$me->id] ?? 0],
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

    /**
     * PUT teams/{team}/members/{freelancer}/title {job_title: ?string} (FS-213) - what the person does in the team ("UI designer"), shown next
     * to his name on the team's profile when he agreed to be shown (D10). An Owner writes anybody's (`manage_members`), a person his own; an
     * empty or missing value clears it. A suspended or archived team changes nothing (409).
     */
    public function title(Request $request, string $team, string $freelancer): JsonResponse
    {
        // A missing title clears it too: an app's JSON may leave a null out.
        $data = $request->validate(['job_title' => ['nullable', 'string', 'max:'.self::TITLE_MAX]]);
        $me = $this->me($request);
        $t = $this->team($me, $team);
        $target = Freelancer::query()->where('uuid', $freelancer)->firstOrFail();
        $row = $this->teams->membership($t, $target);
        if ($row === null || ! $row->isActive()) {
            throw new ModelNotFoundException;
        }
        if ($t->trashed() || ! $t->isActive()) {
            throw new \Modules\Freelance\Exceptions\FreelanceException('freelancer_team_inactive', 409);
        }
        if ((int) $target->id !== (int) $me->id) {
            $this->abilities->assert($t, $me, TeamAbilities::MANAGE_MEMBERS);
        }
        $title = trim(strip_tags((string) ($data['job_title'] ?? '')));
        $title = $title === '' ? null : mb_substr($title, 0, self::TITLE_MAX);
        $before = $row->job_title;
        if ($before !== $title) {
            $row->forceFill(['job_title' => $title])->save();
            $this->audit->record(self::TITLE_CHANGED, $row, ['job_title' => $before], ['job_title' => $title, 'team' => $t->uuid, 'by_freelancer_id' => $me->id]);
        }

        return ApiResponse::success(['member' => $this->present($row->load('freelancer:id,uuid,name'))], __('freelance.team_title_saved'));
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
    /**
     * @param  array<int, int>  $signed  `[freelancer id => agreements he signed that have not ended]`
     * @param  array<int, int>  $open  `[freelancer id => offers in the team's name he signed that are still open]`
     */
    private function present(Member $m, array $signed = [], array $open = []): array
    {
        return [
            'freelancer' => ['uuid' => $m->freelancer?->uuid, 'name' => $m->freelancer?->name],
            'role' => $m->role,
            'job_title' => $m->job_title,
            'signed_agreements' => $m->role === Member::OWNER ? ($signed[(int) $m->freelancer_id] ?? 0) : 0,
            'open_offers' => $m->role === Member::OWNER ? ($open[(int) $m->freelancer_id] ?? 0) : 0,
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
