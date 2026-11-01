<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Market\FreelanceCapacity;

/**
 * FL-L10, «my capacity»: `GET capacity` (my cap, what runs, the places kept and the line on my services) and `PUT capacity {max|null}`.
 * Only running projects count against the cap.
 */
class FreelancerCapacityController extends Controller
{
    public function __construct(private readonly FreelanceCapacity $capacity) {}

    public function show(Request $request): JsonResponse
    {
        /** @var Freelancer $me */
        $me = $request->user('freelance_api');

        return ApiResponse::success($this->capacity->summary($me) + ['line' => $this->capacity->line($me)], __('api.retrieved'));
    }

    public function update(Request $request): JsonResponse
    {
        /** @var Freelancer $me */
        $me = $request->user('freelance_api');
        // The whole setting: no `max` (or null) means no cap.
        $data = $request->validate(['max' => ['nullable', 'integer', 'min:1']]);
        $summary = $this->capacity->setMax($me, isset($data['max']) ? (int) $data['max'] : null);

        return ApiResponse::success($summary + ['line' => $this->capacity->line($me->refresh())], __('api.updated'));
    }
}
