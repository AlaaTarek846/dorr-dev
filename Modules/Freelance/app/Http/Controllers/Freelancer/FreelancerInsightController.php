<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Plans\FreelanceInsights;

/**
 * FL-L01 phase 4, the freelancer's «My performance»: GET insights[?days=30|90|365] — their offers, orders, earnings and name over the
 * last days. What shows depends on the plan; a freelancer whose plan has none gets `locked: true` and the plan that opens it (200).
 */
class FreelancerInsightController extends Controller
{
    public function __construct(private readonly FreelanceInsights $insights) {}

    public function show(Request $request): JsonResponse
    {
        $data = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:3650']]);

        /** @var Freelancer $me */
        $me = $request->user('freelance_api');

        return ApiResponse::success($this->insights->for($me, isset($data['days']) ? (int) $data['days'] : null), __('api.retrieved'));
    }
}
