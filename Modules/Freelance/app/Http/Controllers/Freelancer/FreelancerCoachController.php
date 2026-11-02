<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Account\FreelancerCoach;

/** FL-L11, «improve my profile»: GET profile-coach — the percentage and what to do next, ranked. The same for every plan. */
class FreelancerCoachController extends Controller
{
    public function __construct(private readonly FreelancerCoach $coach) {}

    public function show(Request $request): JsonResponse
    {
        /** @var Freelancer $me */
        $me = $request->user('freelance_api');

        return ApiResponse::success($this->coach->for($me), __('api.retrieved'));
    }
}
