<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Reputation\FreelancerReputation;

/**
 * FS-604: my reputation (`GET reputation` — the six figures, where each comes from, and the projects behind delivery and completion), my
 * correction requests (`GET reputation/corrections`) and asking for one (`POST reputation/corrections` {agreement, indicator, reason, note}).
 */
class FreelancerReputationController extends Controller
{
    public function __construct(private readonly FreelancerReputation $reputation) {}

    public function show(Request $request): JsonResponse
    {
        $me = $this->me($request);

        return ApiResponse::success($this->reputation->of($me, true) + [
            'corrections' => $this->reputation->mine($me),
            'reasons' => FreelancerReputation::REASONS,
            'open_max' => FreelancerReputation::OPEN_MAX,
        ], __('api.retrieved'));
    }

    public function corrections(Request $request): JsonResponse
    {
        return ApiResponse::success(['corrections' => $this->reputation->mine($this->me($request))], __('api.retrieved'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agreement' => ['required', 'uuid'],
            'indicator' => ['required', Rule::in(FreelancerReputation::CORRECTABLE)],
            'reason' => ['required', Rule::in(FreelancerReputation::REASONS)],
            'note' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        $me = $this->me($request);
        $this->reputation->ask($me, $data);

        return ApiResponse::created(['corrections' => $this->reputation->mine($me)], __('freelance.reputation_sent'));
    }

    private function me(Request $request): Freelancer
    {
        return $request->user('freelance_api');
    }
}
