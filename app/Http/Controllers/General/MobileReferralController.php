<?php

namespace App\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Http\Requests\General\StoreReferralTrackRequest;
use App\Services\General\MobileReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\User\Models\User;

class MobileReferralController extends Controller
{
    public function __construct(protected MobileReferralService $service) {}

    public function myCode(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('user_api');

        return $this->service->myCode($user);
    }

    public function track(StoreReferralTrackRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('user_api');

        return $this->service->track($user, $request->validated('referral_code'));
    }
}
