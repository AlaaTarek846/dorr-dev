<?php

namespace Modules\User\Services;

use App\Http\Resources\General\PrivacyPolicyResource;
use App\Repositories\General\PrivacyPolicyRepository;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class MobilePrivacyPolicyService
{
    public function __construct(
        protected PrivacyPolicyRepository $repository,
    ) {}

    /**
     * The active general privacy policy (no service linkage), localized to the request locale.
     */
    public function generalPolicy(): JsonResponse
    {
        $policy = $this->repository->generalActive();

        return ApiResponse::success(
            $policy !== null ? new PrivacyPolicyResource($policy) : null,
            __('api.retrieved'),
        );
    }
}
