<?php

namespace Modules\Freelance\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Services\FreelancerTeam\TeamProfile;

/**
 * FS-202a: a freelancers' team's profile as a client reads it (`mobile/v1/freelance/freelancer-teams/{uuid}` - not `team`, which is the
 * client's own project team, FS-104). An active public team, or a private one that sent him an offer; 404 otherwise.
 */
class ClientTeamProfileController extends Controller
{
    public function __construct(private readonly TeamProfile $profile) {}

    public function show(Request $request, string $uuid): JsonResponse
    {
        $team = FreelanceTeam::query()->where('uuid', $uuid)->firstOrFail();
        $viewer = $this->profile->viewer($team, null, $request->user('user_api')) ?? throw new ModelNotFoundException;

        // FS-213: whether I keep this team (the heart on its page).
        $saved = app(\Modules\Freelance\Services\Offers\ClientTeamFavorites::class)->has($request->user('user_api'), (int) $team->id);

        return ApiResponse::success(['team' => $this->profile->present($team, $viewer) + ['is_favorite' => $saved]], __('api.retrieved'));
    }
}
