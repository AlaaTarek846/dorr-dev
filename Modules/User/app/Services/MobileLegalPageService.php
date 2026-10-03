<?php

namespace Modules\User\Services;

use App\Http\Resources\General\LegalPageResource;
use App\Repositories\General\LegalPageRepository;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class MobileLegalPageService
{
    public function __construct(
        protected LegalPageRepository $repository,
    ) {}

    /**
     * The active legal page of the requested type (privacy/term) for a service,
     * or the general one (no service), localized to the request locale.
     */
    public function page(string $type, ?int $serviceId): JsonResponse
    {
        $page = $this->repository->activeForType($type, $serviceId);

        return ApiResponse::success(
            $page !== null ? new LegalPageResource($page) : null,
            __('api.retrieved'),
        );
    }
}