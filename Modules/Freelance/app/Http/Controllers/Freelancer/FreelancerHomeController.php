<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Services\Home\FreelancerHome;

/** GET freelance/v1/home — the freelancer app's home in one answer: promos, numbers, what waits for an answer, the next consultation, the work. */
class FreelancerHomeController extends Controller
{
    public function __construct(private readonly FreelancerHome $home) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->home->for($request->user('freelance_api')), __('api.retrieved'));
    }
}
