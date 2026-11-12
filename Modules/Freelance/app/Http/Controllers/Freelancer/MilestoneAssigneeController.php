<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceMilestone;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\FreelancerTeam\TeamAssignments;
use Modules\Freelance\Services\FreelancerTeam\TeamProjects;

/**
 * FS-206b: who of the team works on a part of a team's project. Everybody who reaches the project reads it (`view`); an Owner or a PM sets it
 * (`assign`) - the project routes middleware lets only them through. The client never sees it.
 */
class MilestoneAssigneeController extends Controller
{
    public function __construct(private readonly TeamAssignments $assignments, private readonly TeamProjects $projects) {}

    /** GET projects/{uuid}/milestones/{milestone}/assignees */
    public function index(Request $request, string $uuid, string $milestone): JsonResponse
    {
        [$agreement, $part] = $this->find($uuid, $milestone);

        return ApiResponse::success($this->answer($part), __('api.retrieved'));
    }

    /** PUT projects/{uuid}/milestones/{milestone}/assignees {freelancers: [uuid, ...]} - exactly these people; [] takes everybody off. */
    public function update(Request $request, string $uuid, string $milestone): JsonResponse
    {
        $data = $request->validate(['freelancers' => ['present', 'array', 'max:20'], 'freelancers.*' => ['uuid']]);
        [$agreement, $part] = $this->find($uuid, $milestone);
        $me = $request->user('freelance_api');
        abort_unless($me instanceof Freelancer, 401);
        $this->assignments->setMilestone($agreement, $part, array_values($data['freelancers']), $me);

        return ApiResponse::success($this->answer($part), __('freelance.team_assignees_saved'));
    }

    /** @return array{0: FreelanceAgreement, 1: FreelanceMilestone} */
    private function find(string $uuid, string $milestone): array
    {
        $project = FreelanceProject::query()->where('uuid', $uuid)->firstOrFail();
        $agreement = $this->projects->agreementOf($project);
        if ($agreement === null) {
            throw new FreelanceException('team_project_required', 422);
        }
        $part = FreelanceMilestone::query()->where('agreement_id', $agreement->id)->where('uuid', $milestone)->firstOrFail();

        return [$agreement, $part];
    }

    /** @return array<string, mixed> */
    private function answer(FreelanceMilestone $part): array
    {
        return [
            'milestone' => ['uuid' => $part->uuid, 'title' => $part->title],
            'assignees' => $this->assignments->onMilestone($part)->map(fn (Freelancer $f) => ['uuid' => $f->uuid, 'name' => $f->name])->values(),
        ];
    }
}
