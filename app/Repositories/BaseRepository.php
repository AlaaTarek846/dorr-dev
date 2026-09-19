<?php

namespace App\Repositories;

use App\Exceptions\ConflictException;
use App\Repositories\Concerns\ManagesBulkAndStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

abstract class BaseRepository
{
    use ManagesBulkAndStatus;

    /**
     * Relations that block delete when they have records.
     *
     * @var list<string>
     */
    protected array $deleteBlockRelations = [];
    protected Model $model;

    /**
     * @var list<string>
     */
    protected array $with = [];

    /**
     * @var list<string>
     */
    protected array $withCount = [];

    /**
     * Default ordering for index listings.
     *
     * @var array<string, string>
     */
    protected array $orderBy = [
        'id' => 'desc',
    ];

    public function index(): Builder
    {
        return $this->applyIndexDefaults($this->buildIndexQuery());
    }

    public function query(): Builder
    {
        return $this->model->newQuery();
    }

    protected function buildIndexQuery(): Builder
    {
        $query = $this->model->newQuery();

        if ($this->shouldListOnlyTrashed()) {
            return $query->onlyTrashed();
        }

        return $query;
    }

    protected function shouldListOnlyTrashed(): bool
    {
        return filter_var(request()->input('trashed', false), FILTER_VALIDATE_BOOLEAN)
            || filter_var(request()->input('only_trashed', false), FILTER_VALIDATE_BOOLEAN);
    }

    public function store(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            $model = $this->model->create($this->prepareData($data));

            $this->afterStore($model, $data);

            return $this->refresh($model);
        });
    }

    public function show(int|string $id): Model
    {
        return $this->applyShowDefaults($this->query())
            ->findOrFail($id);
    }

    public function update(int|string $id, array $data): Model
    {
        return DB::transaction(function () use ($id, $data) {
            $model = $this->query()->findOrFail($id);
            $model->update($this->prepareData($data));

            $this->afterUpdate($model, $data);

            return $this->refresh($model);
        });
    }

    /**
     * @param  list<string>  $relations
     */
    public function destroy(int|string $id, array $relations = []): bool
    {
        $model = $this->query()->findOrFail($id);

        $this->assertCanDelete($model, $relations);

        try {
            return DB::transaction(function () use ($model) {
                $this->beforeDestroy($model);

                return (bool) $model->delete();
            });
        } catch (QueryException $exception) {
            if ($this->isForeignKeyConstraintViolation($exception)) {
                throw new ConflictException(
                    __('api.cannot_delete_related'),
                    409,
                    null,
                    'cannot_delete_related',
                );
            }

            throw $exception;
        }
    }

    protected function applyIndexDefaults(Builder $query): Builder
    {
        $query = $this->applyRelations($query);

        if (method_exists($this->model, 'scopeSearchAndFilter')) {
            $query->searchAndFilter();
        }

        foreach ($this->orderBy as $column => $direction) {
            $query->orderBy($column, $direction);
        }

        return $query;
    }

    protected function applyShowDefaults(Builder $query): Builder
    {
        return $this->applyRelations($query);
    }

    protected function applyRelations(Builder $query): Builder
    {
        if ($this->with !== []) {
            $query->with($this->with);
        }

        if ($this->withCount !== []) {
            $query->withCount($this->withCount);
        }

        return $query;
    }

    /**
     * @param  list<string>  $relations
     */
    protected function assertCanDelete(Model $model, array $relations): void
    {
        foreach ($relations as $relation) {
            if ($model->{$relation}()->exists()) {
                throw new ConflictException(
                    __('api.cannot_delete_relation', [
                        'relation' => $this->relationLabel($relation),
                    ]),
                    409,
                    null,
                    'cannot_delete_relation',
                    ['relation' => $relation],
                );
            }
        }
    }

    public function restore(int|string $id): Model
    {
        $this->assertUsesSoftDeletes();

        $model = $this->findTrashedOrFail($id);
        $model->restore();

        return $this->refresh($model);
    }

    /**
     * @param  list<string>  $relations
     */
    public function forceDestroy(int|string $id, array $relations = []): bool
    {
        $this->assertUsesSoftDeletes();

        if ($relations === []) {
            $relations = $this->deleteBlockRelations;
        }

        $model = $this->findTrashedOrFail($id);

        $this->assertCanDelete($model, $relations);

        try {
            return DB::transaction(function () use ($model) {
                $this->beforeForceDestroy($model);

                return (bool) $model->forceDelete();
            });
        } catch (QueryException $exception) {
            if ($this->isForeignKeyConstraintViolation($exception)) {
                throw new ConflictException(
                    __('api.cannot_delete_related'),
                    409,
                    null,
                    'cannot_delete_related',
                );
            }

            throw $exception;
        }
    }

    protected function assertUsesSoftDeletes(): void
    {
        if (! in_array(SoftDeletes::class, class_uses_recursive($this->model), true)) {
            throw new ModelNotFoundException;
        }
    }

    protected function findTrashedOrFail(int|string $id): Model
    {
        $model = $this->query()->withTrashed()->find($id);

        if ($model === null) {
            throw (new ModelNotFoundException)->setModel($this->model::class, [$id]);
        }

        if (! $model->trashed()) {
            throw new ConflictException(
                __('api.not_in_trash'),
                409,
                null,
                'not_in_trash',
            );
        }

        return $model;
    }

    protected function relationLabel(string $relation): string
    {
        $label = __('api.relations.'.$relation);

        return $label === 'api.relations.'.$relation ? $relation : $label;
    }

    protected function isForeignKeyConstraintViolation(QueryException $exception): bool
    {
        $errorCode = (string) $exception->getCode();

        return $errorCode === '23000'
            || str_contains($exception->getMessage(), '1451')
            || str_contains($exception->getMessage(), 'foreign key constraint');
    }

    protected function prepareData(array $data): array
    {
        $payload = collect($data)->except($this->reservedPayloadKeys());

        $fillable = $this->model->getFillable();

        if ($fillable !== []) {
            $payload = $payload->only($fillable);
        }

        return $payload->all();
    }

    /**
     * Keys handled outside mass assignment.
     *
     * @return list<string>
     */
    protected function reservedPayloadKeys(): array
    {
        return ['media', 'medias'];
    }

    protected function afterStore(Model $model, array $data): void
    {
        $this->syncMedia($model, $data);
    }

    protected function afterUpdate(Model $model, array $data): void
    {
        $this->syncMedia($model, $data);
    }

    protected function beforeDestroy(Model $model): void
    {
        // Soft delete keeps translations and media so records can be restored intact.
    }

    protected function beforeForceDestroy(Model $model): void
    {
        if (method_exists($model, 'cleanMedia')) {
            $model->cleanMedia();
        }
    }

    protected function syncMedia(Model $model, array $data): void
    {
        if (! method_exists($model, 'setSingleMedia')) {
            return;
        }

        if (isset($data['media']) && is_array($data['media'])) {
            foreach ($data['media'] as $collection => $file) {
                $model->setSingleMedia($collection, $file);
            }
        }
    }

    protected function refresh(Model $model): Model
    {
        $model->refresh();

        if ($this->with !== []) {
            $model->load($this->with);
        }

        if ($this->withCount !== []) {
            $model->loadCount($this->withCount);
        }

        return $model;
    }
}
