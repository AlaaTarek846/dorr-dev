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

        $audience = strtolower(trim((string) request()->query('audience', 'user')));
        $homeOnly = filter_var(request()->query('home', false), FILTER_VALIDATE_BOOLEAN);

        $services = $repository->publicServices($audience, $homeOnly)->map(fn ($category) => [
            'id' => $category->id,
            'name' => $category->translatedName(),
            'description' => $category->translatedDescription(),
            'module_name' => $category->module_name,
            'image' => $category->getSingleMediaUrl('image') ?: null,
            'sort_order' => (int) $category->sort_order,
            'requires_provider' => (bool) $category->requires_provider,
            'audiences' => is_array($category->audiences) ? $category->audiences : [],
            'has_children' => $category->children->isNotEmpty(),
            'children' => $category->children->map(fn ($child) => [
                'id' => $child->id,
                'name' => $child->translatedName(),
                'description' => $child->translatedDescription(),
                'module_name' => $child->module_name,
                'image' => $child->getSingleMediaUrl('image') ?: null,
                'sort_order' => (int) $child->sort_order,
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

    /**
     * @param  array{parent_id?: int|null, ordered_ids: list<int>}  $data
     */
    public function reorder(array $data): JsonResponse
    {
        /** @var ServiceCategoryRepository $repository */
        $repository = $this->repository;

        $parentId = array_key_exists('parent_id', $data) && $data['parent_id'] !== null
            ? (int) $data['parent_id']
            : null;

        $repository->reorderSiblings($parentId, $data['ordered_ids']);

        return ApiResponse::success(null, __('api.updated'));
    }

    protected function beforeStore(array $data): array
    {
        /** @var ServiceCategoryRepository $repository */
        $repository = $this->repository;

        $data = $this->mapImageMedia($data);

        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
        $data['sort_order'] = $repository->nextSortOrder($parentId);

        return $data;
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
