<?php

namespace App\Services;

use App\Http\Requests\PlatformSettingUpdateRequest;
use App\Http\Resources\PlatformBrandingResource;
use App\Http\Resources\PlatformSettingResource;
use App\Repositories\PlatformSettingRepository;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class PlatformSettingService extends BaseService
{
    protected ?string $resource = PlatformSettingResource::class;

    public function __construct(PlatformSettingRepository $repository)
    {
        parent::__construct($repository);
    }

    public function getBranding(): JsonResponse
    {
        /** @var PlatformSettingRepository $repository */
        $repository = $this->repository;

        return ApiResponse::success(
            new PlatformBrandingResource($repository->instance()),
            __('api.retrieved'),
        );
    }

    public function getSettings(): JsonResponse
    {
        /** @var PlatformSettingRepository $repository */
        $repository = $this->repository;

        return ApiResponse::success(
            $this->transformResource($repository->instance()),
            __('api.retrieved'),
        );
    }

    public function updateSettings(PlatformSettingUpdateRequest $request): JsonResponse
    {
        /** @var PlatformSettingRepository $repository */
        $repository = $this->repository;
        $setting = $repository->instance();

        foreach (PlatformSettingUpdateRequest::MEDIA_COLLECTIONS as $collection) {
            if ($request->boolean("remove_{$collection}")) {
                $setting->clearMediaCollection($collection);
            }

            if ($request->hasFile($collection)) {
                $setting->setSingleMedia($collection, $request->file($collection));
            }
        }

        $setting->update([
            'app_name' => $request->validated('app_name'),
        ]);

        return ApiResponse::success(
            $this->transformResource($setting->fresh()),
            __('api.updated'),
        );
    }
}
