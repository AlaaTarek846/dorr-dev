<?php

namespace Modules\Freelance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Projects\ClientProjects;
use Modules\Freelance\Services\Projects\FreelancerProjects;
use Modules\Freelance\Services\Projects\ProgressReports;

/**
 * Progress reports (FL-605), one controller for both prefixes: GET projects/{uuid}/reports (the two people of the agreement),
 * POST projects/{uuid}/reports {note, percent?} (the freelancer, while the agreement is active).
 */
class ProgressReportController extends Controller
{
    public function __construct(private readonly ProgressReports $reports) {}

    public function index(Request $request, string $uuid): JsonResponse
    {
        [$agreement] = $this->context($request, $uuid);

        return ApiResponse::success(['summary' => $this->reports->summary($agreement), 'reports' => $this->reports->list($agreement)], __('api.retrieved'));
    }

    public function store(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:2000'], 'percent' => ['nullable', 'integer', 'min:0', 'max:100']]);
        [$agreement, $freelancer] = $this->context($request, $uuid);
        abort_unless($freelancer instanceof Freelancer, 403);
        $this->reports->submit($agreement, $freelancer, $data['note'], isset($data['percent']) ? (int) $data['percent'] : null);

        return ApiResponse::created(['summary' => $this->reports->summary($agreement->refresh()), 'reports' => $this->reports->list($agreement)], __('freelance.report_sent'));
    }

    /** @return array{0: FreelanceAgreement, 1: ?Freelancer} */
    private function context(Request $request, string $uuid): array
    {
        $freelancer = $request->user('freelance_api');
        if ($freelancer instanceof Freelancer) {
            $project = app(FreelancerProjects::class)->find($freelancer, $uuid);
            $column = 'freelancer_id';
            $id = $freelancer->id;
        } else {
            $client = $request->user('user_api');
            $project = app(ClientProjects::class)->find($client, $uuid);
            $column = 'client_id';
            $id = $client->id;
            $freelancer = null;
        }
        $agreement = FreelanceAgreement::query()->where('project_id', $project->id)->where($column, $id)
            ->whereIn('status', [FreelanceAgreement::ACTIVE, FreelanceAgreement::COMPLETED])->latest('id')->firstOrFail();

        return [$agreement, $freelancer];
    }
}
