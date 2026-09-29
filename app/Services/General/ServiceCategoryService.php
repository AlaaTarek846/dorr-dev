<?php

namespace App\Services\General;

use App\Http\Resources\General\ServiceCategoryResource;
use App\Http\Resources\General\ServiceCategoryTreeResource;
use App\Repositories\General\ServiceCategoryRepository;
use App\Services\CatalogService;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

class ServiceCategoryService extends CatalogService
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

    /**
     * Public list for the mobile/customer home: only the fields a service
     * tile needs, so the payload stays small and independent of admin fields.
     */
    public function publicList(): JsonResponse
    {
        /** @var ServiceCategoryRepository $repository */
        $repository = $this->repository;

        $services = $repository->publicServices()->map(fn ($category) => [
            'id' => $category->id,
            'name' => $category->translatedName(),
            'module_name' => $category->module_name,
            'image' => $category->getSingleMediaUrl('image') ?: null,
            'requires_provider' => (bool) $category->requires_provider,
            'has_children' => $category->children->isNotEmpty(),
            'children' => $category->children->map(fn ($child) => [
                'id' => $child->id,
                'name' => $child->translatedName(),
                'module_name' => $child->module_name,
                'image' => $child->getSingleMediaUrl('image') ?: null,
            ])->values(),
        ])->values();

        return ApiResponse::success($services, __('api.retrieved'));
    }

    public function leafOptions(): JsonResponse
    {
        /** @var ServiceCategoryRepository $repository */
        $repository = $this->repository;

        $options = $repository->leafOptions()->map(fn ($category) => [
            'id' => $category->id,
            'name' => $category->translatedName(),
            'requires_provider' => (bool) $category->requires_provider,
            'image' => $category->getSingleMediaUrl('image') ?: null,
            'translations' => $category->translations->map(fn ($item) => [
                'locale' => $item->locale,
                'name' => $item->name,
            ])->values(),
        ])->values();

        return ApiResponse::success($options, __('api.retrieved'));
    }

    public function treeOptions(): JsonResponse
    {
        /** @var ServiceCategoryRepository $repository */
        $repository = $this->repository;

        return ApiResponse::success(
            ServiceCategoryTreeResource::collection($repository->treeOptions()),
            __('api.retrieved'),
        );
    }

    protected function beforeStore(array $data): array
    {
        return $this->mapImageMedia($data);
    }

    protected function beforeUpdate(int|string $id, array $data): array
    {
        return $this->mapImageMedia($data);
    }

    protected function afterStore(Model $model, array $data): void
    {
        $this->handleImageRemoval($model, $data);
    }

    protected function afterUpdate(Model $model, array $data): void
    {
        $this->handleImageRemoval($model, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mapImageMedia(array $data): array
    {
        if (array_key_exists('image', $data)) {
            if ($data['image'] !== null) {
                $data['media']['image'] = $data['image'];
            }

            unset($data['image']);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleImageRemoval(Model $model, array $data): void
    {
        if (! empty($data['remove_image']) && method_exists($model, 'clearMediaCollection')) {
            $model->clearMediaCollection('image');
        }
    }
}
