<?php

namespace App\Services\General;

use App\Enums\Status;
use App\Http\Resources\General\DashboardThemeResource;
use App\Models\DashboardTheme;
use App\Repositories\General\DashboardThemeRepository;
use App\Services\BaseService;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

class DashboardThemeService extends BaseService
{
    protected ?string $resource = DashboardThemeResource::class;

    public function __construct(DashboardThemeRepository $repository)
    {
        parent::__construct($repository);
    }

    /**
     * @param  list<int|string>  $ids
     */
    public function deleteMultiple(array $ids, ?string $message = null): JsonResponse
    {
        /** @var DashboardThemeRepository $repository */
        $repository = $this->repository;

        return ApiResponse::success(
            ['deleted' => $repository->deleteMultiple($ids)],
            $message ?? __('api.deleted'),
        );
    }

    public function changeStatus(int|string $id, bool $status, ?string $message = null): JsonResponse
    {
        /** @var DashboardThemeRepository $repository */
        $repository = $this->repository;
        $theme = $repository->changeStatus($id, $status);

        return ApiResponse::success(
            $this->transformResource($theme),
            $message ?? __('api.updated'),
        );
    }

    protected function beforeStore(array $data): array
    {
        if (! array_key_exists('status', $data)) {
            $data['status'] = Status::Active->value;
        }

        return $this->preparePayload($data);
    }

    protected function beforeUpdate(int|string $id, array $data): array
    {
        return $this->preparePayload($data);
    }

    protected function afterStore(Model $model, array $data): void
    {
        /** @var DashboardTheme $model */
        $this->syncDefaultTheme($model, $data);
        $this->syncPreviewRemoval($model, $data);
    }

    protected function afterUpdate(Model $model, array $data): void
    {
        /** @var DashboardTheme $model */
        $this->syncDefaultTheme($model, $data);
        $this->syncPreviewRemoval($model, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function preparePayload(array $data): array
    {
        if (array_key_exists('status', $data)) {
            $data['status'] = filter_var($data['status'], FILTER_VALIDATE_BOOLEAN)
                ? Status::Active->value
                : Status::Inactive->value;
        }

        if (array_key_exists('is_default', $data)) {
            $data['is_default'] = filter_var($data['is_default'], FILTER_VALIDATE_BOOLEAN);
        }

        if ($requestFile = $data['preview_image'] ?? null) {
            $data['media'][DashboardTheme::PREVIEW_IMAGE_COLLECTION] = $requestFile;
        }

        unset($data['preview_image']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function syncDefaultTheme(DashboardTheme $theme, array $data): void
    {
        if (array_key_exists('is_default', $data) && $data['is_default']) {
            DashboardTheme::query()
                ->whereKeyNot($theme->id)
                ->update(['is_default' => false]);

            if (! $theme->is_default) {
                $theme->update(['is_default' => true]);
            }

            return;
        }

        if (! DashboardTheme::query()->where('is_default', true)->exists()) {
            $theme->update(['is_default' => true]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function syncPreviewRemoval(DashboardTheme $theme, array $data): void
    {
        if (filter_var($data['remove_preview_image'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $theme->clearMediaCollection(DashboardTheme::PREVIEW_IMAGE_COLLECTION);
        }
    }
}
