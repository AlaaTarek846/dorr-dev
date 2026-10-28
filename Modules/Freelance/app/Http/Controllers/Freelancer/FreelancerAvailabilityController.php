<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Account\FreelancerAvailabilityService;

/** The freelancer's availability (FL-106): the pause, the guide price, the weekly hours and the time off. Scoped to the signed-in freelancer. */
class FreelancerAvailabilityController extends Controller
{
    public function __construct(private readonly FreelancerAvailabilityService $availability) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->availability->present($this->me($request)), __('api.retrieved'));
    }

    /** PUT profile/availability — only what is sent changes. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'accepting_requests' => ['sometimes', 'boolean'],
            'paused_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'pause_reason' => ['nullable', 'string', 'max:200'],
            'rate_type' => ['sometimes', Rule::in(['hourly', 'fixed', 'none'])],
            'rate_min_minor' => ['required_if:rate_type,hourly,fixed', 'nullable', 'integer', 'min:1', 'max:100000000000'],
            'rate_max_minor' => ['nullable', 'integer', 'gte:rate_min_minor', 'max:100000000000'],
            'weekly_hours' => ['sometimes', 'array', 'max:7'],
            'weekly_hours.*.day' => ['required', 'integer', 'between:0,6', 'distinct'],
            'weekly_hours.*.from' => ['required', 'date_format:H:i'],
            'weekly_hours.*.to' => ['required', 'date_format:H:i', 'after:weekly_hours.*.from'],
        ]);
        $me = $this->me($request);
        $this->availability->update($me, $data);

        return ApiResponse::success($this->availability->present($me->refresh()), __('api.updated'));
    }

    public function timeOff(Request $request): JsonResponse
    {
        return ApiResponse::success($this->availability->timeOff($this->me($request))->map(fn ($t) => $this->availability->presentTimeOff($t))->values(), __('api.retrieved'));
    }

    public function addTimeOff(Request $request): JsonResponse
    {
        $data = $request->validate([
            'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        return ApiResponse::created($this->availability->presentTimeOff($this->availability->addTimeOff($this->me($request), $data)), __('api.created'));
    }

    public function removeTimeOff(Request $request, int $period): JsonResponse
    {
        $this->availability->removeTimeOff($this->me($request)->timeOff()->findOrFail($period));

        return ApiResponse::success(null, __('api.deleted'));
    }

    private function me(Request $request): Freelancer
    {
        return $request->user('freelance_api');
    }
}
