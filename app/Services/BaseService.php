<?php

namespace App\Services;

use App\Repositories\BaseRepository;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class BaseService
{
    protected BaseRepository $repository;

    /**
     * @var class-string<JsonResource>|null
     */
    protected ?string $resource = null;

    /**
     * Relations that block delete when they have records.
     *
     * @var list<string>
     */
    protected array $deleteRelations = [];

    public function __construct(BaseRepository $repository)
    {
        $this->repository = $repository;
    }

    public function index(): Builder
    {
        return $this->repository->index();
    }

    public function query(): Builder
    {
        return $this->repository->query();
    }

    /**
     * @return array{data: mixed, pagination: array<string, mixed>|null}
     */
    public function allOrPaginate(?string $resource = null, ?string $groupBy = null): array
    {
        return allOrPaginate(
            $this->repository->index(),
            $resource ?? $this->resourceClass(),
            $groupBy,
        );
    }

    public function store(array $data): Model
    {
        $payload = $this->beforeStore($data);
        $model = $this->repository->store($payload);
        $this->afterStore($model, $payload);

        return $model;
    }

    public function show(int|string $id): Model
    {
        return $this->repository->show($id);
    }

    public function update(int|string $id, array $data): Model
    {
        $payload = $this->beforeUpdate($id, $data);
        $model = $this->repository->update($id, $payload);
        $this->afterUpdate($model, $payload);

        return $model;
    }

    public function destroy(int|string $id, ?array $relations = null): bool
    {
        return $this->repository->destroy(
            $id,
            $relations ?? $this->deleteRelations,
        );
    }

    public function list(?string $message = null, ?string $groupBy = null): JsonResponse
    {
        $result = $this->allOrPaginate(groupBy: $groupBy);

        return ApiResponse::success(
            $result['data'],
            $message ?? __('api.retrieved'),
            200,
            $result['pagination'],
        );
    }

    public function create(array $data, ?string $message = null): JsonResponse
    {
        $model = $this->store($data);

        return ApiResponse::created(
            $this->transformResource($model),
            $message ?? __('api.created'),
        );
    }

    public function find(int|string $id, ?string $message = null): JsonResponse
    {
        $model = $this->show($id);

        return ApiResponse::success(
            $this->transformResource($model),
            $message ?? __('api.retrieved'),
        );
    }

    public function updateRecord(int|string $id, array $data, ?string $message = null): JsonResponse
    {
        $model = $this->update($id, $data);

        return ApiResponse::success(
            $this->transformResource($model),
            $message ?? __('api.updated'),
        );
    }

    public function delete(int|string $id, ?array $relations = null, ?string $message = null): JsonResponse
    {
        $this->destroy($id, $relations);

        return ApiResponse::noContent($message ?? __('api.deleted'));
    }

    /**
     * @return class-string<JsonResource>
     */
    protected function resourceClass(): string
    {
        if ($this->resource === null) {
            throw new \RuntimeException(sprintf(
                'Define $resource on [%s] or pass a resource class to allOrPaginate().',
                static::class,
            ));
        }

        return $this->resource;
    }

    protected function transformResource(Model $model): JsonResource
    {
        $resourceClass = $this->resourceClass();

        return new $resourceClass($model);
    }

    protected function beforeStore(array $data): array
    {
        return $data;
    }

    protected function beforeUpdate(int|string $id, array $data): array
    {
        return $data;
    }

    protected function afterStore(Model $model, array $data): void
    {
        //
    }

    protected function afterUpdate(Model $model, array $data): void
    {
        //
    }
}
