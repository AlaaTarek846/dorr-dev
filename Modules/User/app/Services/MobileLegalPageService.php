<?php

namespace Modules\User\Services;

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
     * or the general one (no service). Only the content is returned, in the request
     * locale (falling back to an available language so it is never blank); `data` is
     * null when no page is published.
     */
    public function page(string $type, ?int $serviceId): JsonResponse
    {
        $page = $this->repository->activeForType($type, $serviceId);

        return ApiResponse::success(
            $page !== null ? ['content' => $page->translated('content')] : null,
            __('api.retrieved'),
        );
    }
}