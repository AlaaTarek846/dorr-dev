<?php

namespace App\Services;

use App\Http\Resources\ServiceCategoryResource;
use App\Repositories\ServiceCategoryRepository;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class ServiceCategoryService extends BaseService
{
    protected ?string $resource = ServiceCategoryResource::class;

    /**
     * @var list<string>
     */
    protected array $deleteRelations = ['children'];

    public function __construct(ServiceCategoryRepository $repository)
    {
        parent::__construct($repository);
    }

    public function tree(): JsonResponse
    {
        /** @var ServiceCategoryRepository $repository */
        $repository = $this->repository;

        return ApiResponse::success(
            ServiceCategoryResource::collection($repository->tree()),
            __('api.retrieved'),
        );
    }

    public function dropdown(): JsonResponse
    {
        /** @var ServiceCategoryRepository $repository */
        $repository = $this->repository;

        return ApiResponse::success($repository->dropdown(), __('api.retrieved'));
    }

    public function leafOptions(): JsonResponse
    {
        /** @var ServiceCategoryRepository $repository */
        $repository = $this->repository;

        $options = $repository->leafOptions()->map(fn ($category) => [
            'id' => $category->id,
            'name' => $category->name_ar,
            'name_en' => $category->name_en,
            'department' => $category->department,
            'provider_type_label' => $category->provider_type_label,
        ])->values();

        return ApiResponse::success($options, __('api.retrieved'));
    }

    /**
     * @param  list<int|string>  $ids
     */
    public function deleteMultiple(array $ids, ?string $message = null): JsonResponse
    {
        /** @var ServiceCategoryRepository $repository */
        $repository = $this->repository;

        return ApiResponse::success(
            ['deleted' => $repository->deleteMultiple($ids)],
            $message ?? __('api.deleted'),
        );
    }

    public function changeStatus(int|string $id, bool $status, ?string $message = null): JsonResponse
    {
        /** @var ServiceCategoryRepository $repository */
        $repository = $this->repository;
        $category = $repository->changeStatus($id, $status);

        return ApiResponse::success(
            $this->transformResource($category),
            $message ?? __('api.updated'),
        );
    }

    protected function beforeStore(array $data): array
    {
        return $this->normalize($data);
    }

    protected function beforeUpdate(int|string $id, array $data): array
    {
        return $this->normalize($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalize(array $data): array
    {
        // A category can't be its own parent's descendant AND have children
        // at the same time from the provider's point of view; requires_provider
        // = false makes provider_type_label meaningless, so drop it rather
        // than store stale data the form no longer shows.
        if (array_key_exists('requires_provider', $data) && ! $data['requires_provider']) {
            $data['provider_type_label'] = null;
        }

        return $data;
    }
}
