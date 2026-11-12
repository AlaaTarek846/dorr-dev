<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\FreelancerTeam\FreelancerTeams;
use Modules\Freelance\Services\FreelancerTeam\TeamProfile;

/**
 * FS-202a: a freelancers' team's profile under `freelance/v1/teams`, behind the `teams` switch: making a team, reading a profile (the team's
 * people, or anybody signed in for a public team), the Owner's changes and logo, and each member's own consent to be shown (D10).
 */
class TeamProfileController extends Controller
{
    public function __construct(
        private readonly FreelancerTeams $teams,
        private readonly TeamProfile $profile,
    ) {}

    /** POST teams {name} - a new team with me as its Owner (verified only, D4; `teams.owned_max`, D1). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:'.FreelancerTeams::NAME_MAX]]);
        $me = $this->me($request);
        $team = $this->teams->create($me, $data['name']);
        $this->profile->created($team, $me);

        return ApiResponse::created(['team' => $this->profile->present($team->refresh(), TeamProfile::MEMBER, $me)], __('freelance.team_created'));
    }

    /** GET teams/{team}/profile - the team's people always; anybody else only for an active public team (404 otherwise). */
    public function show(Request $request, string $team): JsonResponse
    {
        $me = $this->me($request);
        $t = FreelanceTeam::query()->where('uuid', $team)->firstOrFail();
        $viewer = $this->profile->viewer($t, $me) ?? throw new ModelNotFoundException;

        return ApiResponse::success(['team' => $this->profile->present($t, $viewer, $me)], __('api.retrieved'));
    }

    /** PUT teams/{team}/profile {name?, bio?, visibility?, accepting_projects?} - an Owner. */
    public function update(Request $request, string $team): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:'.FreelancerTeams::NAME_MAX],
            'bio' => ['sometimes', 'nullable', 'string', 'max:'.TeamProfile::BIO_MAX],
            'visibility' => ['sometimes', Rule::in(FreelanceTeam::VISIBILITIES)],
            'accepting_projects' => ['sometimes', 'boolean'],
        ]);
        $me = $this->me($request);
        $t = $this->profile->update($this->mine($me, $team), $me, $data);

        return ApiResponse::success(['team' => $this->profile->present($t, TeamProfile::MEMBER, $me)], __('freelance.team_profile_saved'));
    }

    /** POST teams/{team}/profile/logo (multipart: logo) - an Owner. */
    public function logo(Request $request, string $team): JsonResponse
    {
        $request->validate(['logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', new \Modules\Freelance\Rules\CleanUpload]]);
        $me = $this->me($request);
        $t = $this->profile->setLogo($this->mine($me, $team), $me, $request->file('logo'));

        return ApiResponse::success(['logo_url' => $t->logoUrl()], __('freelance.team_profile_saved'));
    }

    /** DELETE teams/{team}/profile/logo - an Owner. */
    public function removeLogo(Request $request, string $team): JsonResponse
    {
        $me = $this->me($request);
        $this->profile->setLogo($this->mine($me, $team), $me, null);

        return ApiResponse::success(['logo_url' => null], __('freelance.team_profile_saved'));
    }

    /** PUT teams/{team}/profile/me {show_in_profile: bool} - whether I am shown on the team's profile (D10); nobody else decides it. */
    public function consent(Request $request, string $team): JsonResponse
    {
        $data = $request->validate(['show_in_profile' => ['required', 'boolean']]);
        $me = $this->me($request);
        $row = $this->profile->consent($this->mine($me, $team), $me, (bool) $data['show_in_profile']);

        return ApiResponse::success(['show_in_profile' => (bool) $row->show_in_profile], __('freelance.team_profile_consent_saved'));
    }

    /** The team, for somebody in it (404 for anybody else, like the team's other routes). */
    private function mine(Freelancer $me, string $uuid): FreelanceTeam
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
