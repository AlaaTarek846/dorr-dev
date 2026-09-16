<?php

namespace Modules\Provider\Services;

use App\Models\ServiceCategory;
use App\Services\BaseService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Models\Admin;
use Modules\Provider\Enums\ProviderServiceStatus;
use Modules\Provider\Http\Resources\ProviderProfileResource;
use Modules\Provider\Http\Resources\ProviderServiceResource;
use Modules\Provider\Repositories\ProviderProfileRepository;

class ProviderProfileService extends BaseService
{
    protected ?string $resource = ProviderProfileResource::class;

    public function __construct(ProviderProfileRepository $repository)
    {
        parent::__construct($repository);
    }

    public function approve(int|string $id, Admin $admin): JsonResponse
    {
        /** @var ProviderProfileRepository $repository */
        $repository = $this->repository;
        $profile = $repository->approve($repository->show($id), $admin);

        return ApiResponse::success(new ProviderProfileResource($profile), __('provider.approved'));
    }

    public function reject(int|string $id, string $reason): JsonResponse
    {
        /** @var ProviderProfileRepository $repository */
        $repository = $this->repository;
        $profile = $repository->reject($repository->show($id), $reason);

        return ApiResponse::success(new ProviderProfileResource($profile), __('provider.rejected'));
    }

    public function suspend(int|string $id): JsonResponse
    {
        /** @var ProviderProfileRepository $repository */
        $repository = $this->repository;
        $profile = $repository->suspend($repository->show($id));

        return ApiResponse::success(new ProviderProfileResource($profile), __('provider.suspended'));
    }

    public function reactivate(int|string $id): JsonResponse
    {
        /** @var ProviderProfileRepository $repository */
        $repository = $this->repository;
        $profile = $repository->reactivate($repository->show($id));

        return ApiResponse::success(new ProviderProfileResource($profile), __('provider.reactivated'));
    }

    public function addService(int|string $id, int $categoryId): JsonResponse
    {
        /** @var ProviderProfileRepository $repository */
        $repository = $this->repository;
        $profile = $repository->show($id);
        $category = ServiceCategory::query()->findOrFail($categoryId);

        // Mandatory checkpoint: a provider can only be linked to a leaf
        // service category, never a parent grouping category.
        if (! $category->isLeaf()) {
            throw ValidationException::withMessages([
                'service_category_id' => [__('provider.category_must_be_leaf')],
            ]);
        }

        $service = $repository->addService($profile, $categoryId);

        return ApiResponse::created(new ProviderServiceResource($service), __('api.created'));
    }

    public function approveService(int|string $providerId, int|string $serviceId): JsonResponse
    {
        return $this->changeServiceStatus($providerId, $serviceId, ProviderServiceStatus::Approved, __('provider.service_approved'));
    }

    public function rejectService(int|string $providerId, int|string $serviceId): JsonResponse
    {
        return $this->changeServiceStatus($providerId, $serviceId, ProviderServiceStatus::Rejected, __('provider.service_rejected'));
    }

    protected function changeServiceStatus(
        int|string $providerId,
        int|string $serviceId,
        ProviderServiceStatus $status,
        string $message,
    ): JsonResponse {
        /** @var ProviderProfileRepository $repository */
        $repository = $this->repository;
        $profile = $repository->show($providerId);
        $service = $profile->services()->findOrFail($serviceId);

        $service = $repository->changeServiceStatus($service, $status);

        return ApiResponse::success(new ProviderServiceResource($service), $message);
    }
}
