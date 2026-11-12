<?php

namespace Modules\Freelance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Projects\ApprovalTrail;
use Modules\Freelance\Services\Projects\ClientProjects;
use Modules\Freelance\Services\Projects\FreelancerProjects;

/**
 * The project's record of approvals (FL-406) for its two sides: the client at `mobile/v1/freelance/projects/{uuid}/agreement/trail`
 * (every agreement on their project), the freelancer at `freelance/v1/projects/{uuid}/agreement/trail` (only their own agreement).
 */
class ApprovalTrailController extends Controller
{
    public function __construct(private readonly ApprovalTrail $trail) {}

    public function index(Request $request, string $uuid): JsonResponse
    {
        $freelancer = $request->user('freelance_api');
        if ($freelancer instanceof Freelancer) {
            $project = app(FreelancerProjects::class)->find($freelancer, $uuid);
            $ids = FreelanceAgreement::query()->where('project_id', $project->id)->where('freelancer_id', $freelancer->id)->pluck('id')->all();
            abort_if($ids === [], 404);

            return ApiResponse::success($this->trail->list((int) $project->id, $ids, 'freelancer'), __('api.retrieved'));
        }
        $project = app(ClientProjects::class)->find($request->user('user_api'), $uuid);

        return ApiResponse::success($this->trail->list((int) $project->id, null, 'client'), __('api.retrieved'));
    }
}
