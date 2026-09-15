<?php

namespace App\Services\Concerns;

use App\Repositories\TranslatableRepository;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

trait ManagesCatalog
{
    /**
     * @param  list<int|string>  $ids
     */
    public function deleteMultiple(array $ids, ?string $message = null): JsonResponse
    {
        /** @var TranslatableRepository $repository */
        $repository = $this->repository;

        return ApiResponse::success(
            ['deleted' => $repository->deleteMultiple($ids)],
            $message ?? __('api.deleted'),
        );
    }

    public function changeStatus(int|string $id, bool $status, ?string $message = null): JsonResponse
    {
        /** @var TranslatableRepository $repository */
        $repository = $this->repository;
        $model = $repository->changeStatus($id, $status);

        return ApiResponse::success(
            $this->transformResource($model),
            $message ?? __('api.updated'),
        );
    }

    public function dropdown(?string $message = null): JsonResponse
    {
        /** @var TranslatableRepository $repository */
        $repository = $this->repository;

        return ApiResponse::success(
            $repository->dropdown(),
            $message ?? __('api.retrieved'),
        );
    }
}
