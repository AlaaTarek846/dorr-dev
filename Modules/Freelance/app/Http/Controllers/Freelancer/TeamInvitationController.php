<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceTeamInvitation as Invitation;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\FreelancerTeam\FreelancerTeams;
use Modules\Freelance\Services\FreelancerTeam\TeamAbilities;
use Modules\Freelance\Services\FreelancerTeam\TeamInvitations;

/**
 * FS-203: invitations into a freelancers' team, under `freelance/v1` behind the `teams` switch. The team's side (`teams/{team}/invitations`)
 * is for the people who may invite and answers 404 to anybody not in the team; the invited person's side (`team-invitations`) is theirs only.
 * See TeamInvitations for the rules.
 */
class TeamInvitationController extends Controller
{
    public function __construct(
        private readonly TeamInvitations $invitations,
        private readonly FreelancerTeams $teams,
        private readonly TeamAbilities $abilities,
    ) {}

    /** GET teams/{team}/invitations - the open ones, for the people who may invite (links show their token). */
    public function open(Request $request, string $team): JsonResponse
    {
        $me = $this->me($request);
        $t = $this->team($me, $team);
        $this->abilities->assert($t, $me, TeamAbilities::INVITE_MEMBERS);

        return ApiResponse::success([
            'invitations' => $this->invitations->open($t)->map(fn (Invitation $i) => $this->invitations->present($i, true))->values(),
            'days' => TeamInvitations::DAYS,
        ], __('api.retrieved'));
    }

    /** POST teams/{team}/invitations {phone?: string, role: pm|member} - no phone = an invitation by link (the token comes back once here and in the list). */
    public function store(Request $request, string $team): JsonResponse
    {
        $data = $request->validate(['phone' => ['nullable', 'string', 'max:30'], 'role' => ['required', 'string', 'in:pm,member']]);
        $me = $this->me($request);
        $t = $this->team($me, $team);
        $i = $this->invitations->invite($t, $me, isset($data['phone']) && trim($data['phone']) !== '' ? $data['phone'] : null, $data['role']);

        return ApiResponse::created(['invitation' => $this->invitations->present($i, true)], __('freelance.team_invitation_sent'));
    }

    /** DELETE teams/{team}/invitations/{invitation} - the Owner, or whoever sent it. */
    public function cancel(Request $request, string $team, string $invitation): JsonResponse
    {
        $me = $this->me($request);
        $t = $this->team($me, $team);
        $this->invitations->cancel($t, $me, $invitation);

        return ApiResponse::success(null, __('freelance.team_invitation_cancelled'));
    }

    /** GET team-invitations - the invitations waiting for me. */
    public function mine(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'invitations' => $this->invitations->mine($this->me($request))->map(fn (Invitation $i) => $this->invitations->present($i))->values(),
        ], __('api.retrieved'));
    }

    /** POST team-invitations/{invitation}/accept */
    public function accept(Request $request, string $invitation): JsonResponse
    {
        $i = $this->invitations->accept($this->me($request), $invitation);

        return ApiResponse::success(['invitation' => $this->invitations->present($i)], __('freelance.team_invitation_accepted'));
    }

    /** POST team-invitations/{invitation}/decline */
    public function decline(Request $request, string $invitation): JsonResponse
    {
        $i = $this->invitations->decline($this->me($request), $invitation);

        return ApiResponse::success(['invitation' => $this->invitations->present($i)], __('freelance.team_invitation_declined'));
    }

    /** GET team-invitations/by-link/{token} - which team and role the link is for, before accepting. */
    public function preview(string $token): JsonResponse
    {
        return ApiResponse::success(['invitation' => $this->invitations->present($this->invitations->preview($token))], __('api.retrieved'));
    }

    /** POST team-invitations/by-link/{token}/accept - once; the first freelancer who does is in. */
    public function acceptLink(Request $request, string $token): JsonResponse
    {
        $i = $this->invitations->acceptLink($this->me($request), $token);

        return ApiResponse::success(['invitation' => $this->invitations->present($i)], __('freelance.team_invitation_accepted'));
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
