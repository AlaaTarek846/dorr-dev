<?php

namespace App\Services\General;

use App\Http\Resources\General\MobileReferralCodeResource;
use App\Http\Resources\General\ReferralResource;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\User\Models\User;

class MobileReferralService
{
    public function __construct(
        protected ReferralCodeService $codes,
        protected ReferralService $referrals,
    ) {}

    public function myCode(User $user): JsonResponse
    {
        $code = $this->codes->codeFor($user);
        $applied = $this->referrals->appliedBy($user);

        return ApiResponse::success(
            new MobileReferralCodeResource($code, $applied),
            __('api.retrieved'),
        );
    }

    public function track(User $user, string $code): JsonResponse
    {
        $already = $this->referrals->appliedBy($user);
        $referral = $this->referrals->track($user, $code);
        $created = $already === null && $referral->wasRecentlyCreated;

        return ApiResponse::success(
            new ReferralResource($referral->load('referralCode')),
            $created ? __('api.referral_tracked') : __('api.retrieved'),
            $created ? 201 : 200,
        );
    }
}
