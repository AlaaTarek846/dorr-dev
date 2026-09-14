<?php

use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

if (! function_exists('responseJson')) {
    function responseJson(int $status, string $message, mixed $data = null, ?array $pagination = null): JsonResponse
    {
        return ApiResponse::json($status, $message, $data, $pagination);
    }
}

if (! function_exists('getPaginates')) {
    /**
     * @return array<string, mixed>
     */
    function getPaginates(LengthAwarePaginator $collection): array
    {
        return ApiPaginator::meta($collection);
    }
}

if (! function_exists('allOrPaginate')) {
    /**
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resource
     * @return array{data: mixed, pagination: array<string, mixed>|null}
     */
    function allOrPaginate(mixed $query, string $resource, ?string $groupBy = null): array
    {
        return ApiPaginator::resolve($query, $resource, $groupBy);
    }
}
