<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\FreelancerTeam\FreelancerTeams;
use Modules\Freelance\Services\FreelancerTeam\TeamAssignments;
use Modules\Freelance\Services\FreelancerTeam\TeamProjects;

/**
 * FS-206a: the projects of a freelancers' team that the person reaches - every one for an Owner or a PM, the ones he works on for a member
 * (D9) - with his place on each. Each one is then used through the ordinary project routes (`projects/{uuid}/...`), which act for the Owner
 * who signed it. A person not in the team gets 404, like the rest of the team's routes.
 */
class TeamProjectController extends Controller
{
    public function __construct(private readonly FreelancerTeams $teams, private readonly TeamProjects $projects) {}

    /**
     * GET teams/{team}/workload - what each member carries on the team's running projects (FS-207). An Owner or a PM reads everybody; a plain
     * member reads his own row only.
     */
    public function workload(Request $request, string $team): JsonResponse
    {
        /** @var Freelancer $me */
        $me = $request->user('freelance_api');
        $t = FreelanceTeam::query()->where('uuid', $team)->first();
        $row = $t === null ? null : $this->teams->membership($t, $me);
        if ($t === null || $row === null) {
            throw new ModelNotFoundException;
        }
        $all = in_array($row->role, [\Modules\Freelance\Models\FreelanceTeamMember::OWNER, \Modules\Freelance\Models\FreelanceTeamMember::PM], true);

        return ApiResponse::success(['members' => app(TeamAssignments::class)->workload($t, $all ? null : $me->id)], __('api.retrieved'));
    }

    /** GET teams/{team}/projects */
    public function index(Request $request, string $team): JsonResponse
    {
        /** @var Freelancer $me */
        $me = $request->user('freelance_api');
        $t = FreelanceTeam::query()->where('uuid', $team)->first();
        if ($t === null || ! $this->teams->isMember($t, $me)) {
            throw new ModelNotFoundException;
        }

        $rows = $this->projects->of($t, $me)->map(function (array $r) use ($t) {
            $a = $r['agreement'];
            $money = in_array(TeamProjects::MONEY, $r['abilities'], true);

            return [
                'project' => ['uuid' => $a->project?->uuid, 'title' => $a->project?->title, 'status' => $a->project?->status?->value ?? $a->project?->status],
                'agreement' => ['uuid' => $a->uuid, 'status' => $a->status, 'price_minor' => $money ? $a->price_minor : null, 'currency' => $a->currency_code],
                'signed_by' => ['uuid' => $a->freelancer?->uuid, 'name' => $a->freelancer?->name],
                'team_access' => TeamProjects::describe($t, $r['role'], $r['abilities'], $r['signer']),
            ];
        })->values();

        return ApiResponse::success($rows, __('api.retrieved'));
    }
}
