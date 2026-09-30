<?php

namespace Modules\User\Services;

use App\Http\Resources\General\FaqResource;
use App\Repositories\General\FaqRepository;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class MobileFaqService
{
    public function __construct(
        protected FaqRepository $repository,
    ) {}

    /**
     * All active general FAQs (no service linkage), localized to the request locale.
     */
    public function generalFaqs(): JsonResponse
    {
        return ApiResponse::success(
            FaqResource::collection($this->repository->generalActive()),
            __('api.retrieved'),
        );
    }
}
