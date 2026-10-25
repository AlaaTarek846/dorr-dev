<?php

namespace Modules\Freelance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceChangeRequest;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Projects\Agreements;
use Modules\Freelance\Services\Projects\ChangeRequests;
use Modules\Freelance\Services\Projects\ClientProjects;
use Modules\Freelance\Services\Projects\FreelancerProjects;

/**
 * Changes to an active agreement (FL-404 / FL-405), for both sides like the disputes: the client at `mobile/v1/freelance/projects/{uuid}/…`,
 * the freelancer at `freelance/v1/projects/{uuid}/…`. GET agreement/changes, POST agreement/changes, POST agreement/changes/{change}/{accept|reject|withdraw}.
 */
class ChangeRequestController extends Controller
{
    public function __construct(private readonly ChangeRequests $changes) {}

    public function index(Request $request, string $uuid): JsonResponse
    {
        [, $side, $agreement] = $this->context($request, $uuid);

        return ApiResponse::success($this->changes->list($agreement, $side), __('api.retrieved'));
    }

    public function store(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(FreelanceChangeRequest::TYPES)],
            'milestone' => ['nullable', 'uuid'],
            'days' => ['required_if:type,extend_days', 'nullable', 'integer', 'min:1', 'max:365'],
            'scope' => ['sometimes', 'nullable', 'string', 'max:8000'],
            'includes' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'excludes' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'amount_minor' => ['required_if:type,milestone_price,add_milestone', 'nullable', 'integer', 'min:1', 'max:100000000000'],
            'title' => ['required_if:type,add_milestone', 'nullable', 'string', 'max:120'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        [$me, $side, $agreement] = $this->context($request, $uuid);
        $this->changes->request($agreement, $me, $side, $data['type'], $data);

        return ApiResponse::created($this->changes->list($agreement, $side), __('freelance.change_sent'));
    }

    public function answer(Request $request, string $uuid, string $change, string $action): JsonResponse
    {
        [$me, $side, $agreement] = $this->context($request, $uuid);
        $c = $this->changes->find($agreement, $change);
        match ($action) {
            'accept' => $this->changes->accept($c, $me, $side),
            'reject' => $this->changes->reject($c, $me, $side),
            default => $this->changes->withdraw($c, $me, $side),
        };

        return ApiResponse::success($this->changes->list($agreement, $side), __('api.updated'));
    }

    /** @return array{0: Model, 1: 'client'|'freelancer', 2: FreelanceAgreement} */
    private function context(Request $request, string $uuid): array
    {
        $agreements = app(Agreements::class);
        $freelancer = $request->user('freelance_api');
        if ($freelancer instanceof Freelancer) {
            $a = $agreements->forFreelancer(app(FreelancerProjects::class)->find($freelancer, $uuid), $freelancer);

            return [$freelancer, 'freelancer', $a];
        }
        $client = $request->user('user_api');
        $a = $agreements->current(app(ClientProjects::class)->find($client, $uuid)) ?? abort(404);

        return [$client, 'client', $a];
    }
}
