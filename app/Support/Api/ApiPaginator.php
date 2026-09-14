<?php

namespace App\Support\Api;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class ApiPaginator
{
    /**
     * @return array<string, mixed>
     */
    public static function meta(LengthAwarePaginator $paginator): array
    {
        return [
            'per_page' => $paginator->perPage(),
            'path' => $paginator->path(),
            'total' => $paginator->total(),
            'current_page' => $paginator->currentPage(),
            'next_page_url' => $paginator->nextPageUrl(),
            'prev_page_url' => $paginator->previousPageUrl(),
            'last_page' => $paginator->lastPage(),
            'has_more_pages' => $paginator->hasMorePages(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }

    /**
     * Paginate an Eloquent query and wrap items with a JsonResource.
     *
     * Pass paginate=0 or all=1 to return all records without pagination.
     *
     * @param  class-string<JsonResource>  $resourceClass
     * @return array{data: mixed, pagination: array<string, mixed>|null}
     */
    public static function resolve(
        mixed $query,
        string $resourceClass,
        ?string $groupBy = null,
    ): array {
        $perPage = (int) request()->input('paginate', 10);

        if (request()->has('paginate')) {
            request()->validate([
                'paginate' => 'nullable|integer|min:0|max:50',
            ]);
        }

        $fetchAll = request()->boolean('all') || $perPage === 0;

        if ($fetchAll) {
            $items = $query->get();
            $data = self::transformItems($items, $resourceClass, $groupBy);

            return [
                'data' => $data,
                'pagination' => null,
            ];
        }

        $paginator = $query->paginate(max($perPage, 1));
        $data = self::transformItems($paginator->getCollection(), $resourceClass, $groupBy);

        return [
            'data' => $data,
            'pagination' => self::meta($paginator),
        ];
    }

    /**
     * @param  class-string<JsonResource>  $resourceClass
     */
    protected static function transformItems(
        Collection $items,
        string $resourceClass,
        ?string $groupBy = null,
    ): mixed {
        $resource = $resourceClass::collection($items);

        if ($groupBy === null) {
            return $resource;
        }

        return collect($resource->resolve())->groupBy($groupBy);
    }
}
