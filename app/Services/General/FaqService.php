<?php

namespace App\Services\General;

use App\Http\Resources\General\FaqResource;
use App\Models\Faq;
use App\Repositories\General\FaqRepository;
use App\Services\CatalogService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class FaqService extends CatalogService
{
    protected ?string $resource = FaqResource::class;

    public function __construct(FaqRepository $repository)
    {
        parent::__construct($repository);
    }

    public function ordered(?int $serviceId): JsonResponse
    {
        /** @var FaqRepository $repository */
        $repository = $this->repository;

        return ApiResponse::success(
            FaqResource::collection($repository->orderedForService($serviceId)),
            __('api.retrieved'),
        );
    }

    /**
     * @param  array{service_id?: int|null, ordered_ids: list<int>}  $data
     */
    public function reorder(array $data): JsonResponse
    {
        /** @var FaqRepository $repository */
        $repository = $this->repository;

        $repository->reorderGroup(self::serviceIdFrom($data), $data['ordered_ids']);

        return ApiResponse::success(null, __('api.updated'));
    }

    protected function beforeStore(array $data): array
    {
        /** @var FaqRepository $repository */
        $repository = $this->repository;

        $data['sort_order'] = $repository->nextSortOrder(self::serviceIdFrom($data));

        return $data;
    }

    protected function beforeUpdate(int|string $id, array $data): array
    {
        if (! array_key_exists('service_id', $data)) {
            return $data;
        }

        /** @var FaqRepository $repository */
        $repository = $this->repository;

        $current = Faq::query()->find($id);
        $serviceId = self::serviceIdFrom($data);

        if ($current && $current->service_id !== $serviceId) {
            $data['sort_order'] = $repository->nextSortOrder($serviceId);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function serviceIdFrom(array $data): ?int
    {
        return isset($data['service_id']) ? (int) $data['service_id'] : null;
    }
}
