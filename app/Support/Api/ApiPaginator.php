<?php

namespace App\Support\Api;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
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
     * Pages a list that is already built (a computed sort, two sources merged) the way a query's `paginate()` does, for the apps'
     * endless scrolling: `page` and `per_page` (1–$max, $default when missing) are read from the request.
     *
     * @param  Collection<int, mixed>|array<int, mixed>  $rows
     */
    public static function slice(Collection|array $rows, int $default = 15, int $max = 50): Paginator
    {
        $rows = collect($rows)->values();
        $perPage = min(max((int) request()->input('per_page', $default), 1), $max);
        $page = max((int) request()->input('page', 1), 1);

        return new Paginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, ['path' => request()->url()]);
    }

    /** `per_page` of the request (1–$max, $default when missing) — for a query that is paged with `paginate()`. */
    public static function perPage(int $default = 15, int $max = 50): int
    {
        return min(max((int) request()->input('per_page', $default), 1), $max);
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
        $perPage = (int) (request()->input('paginate') ?? request()->input('per_page', 10));

        if (request()->has('paginate') || request()->has('per_page')) {
            request()->validate([
                'paginate' => 'nullable|integer|min:0|max:50',
                'per_page' => 'nullable|integer|min:0|max:50',
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
