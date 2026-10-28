<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Services\General\MobileReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Account > Share the app with friends: the freelancer's invite code (the same referral codes as the DORR app, owner alias `freelancer`). */
class FreelancerReferralController extends Controller
{
    public function __construct(protected MobileReferralService $service) {}

    public function myCode(Request $request): JsonResponse
    {
        return $this->service->myCode($request->user('freelance_api'));
    }
}
