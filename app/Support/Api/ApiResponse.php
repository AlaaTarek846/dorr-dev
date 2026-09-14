<?php

namespace App\Support\Api;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiResponse
{
    /**
     * Standard JSON response (backward-compatible helper shape).
     */
    public static function json(
        int $status,
        string $message,
        mixed $data = null,
        ?array $pagination = null,
        ?array $meta = null,
    ): JsonResponse {
        $success = $status >= 200 && $status < 300;

        $payload = [
            'success' => $success,
            'status' => $success ? 'success' : 'error',
            'code' => $status,
            'message' => $message,
            'data' => $data ?? [],
            'pagination' => $pagination,
        ];

        if ($meta !== null) {
            $payload = array_merge($payload, $meta);
        }

        return response()->json($payload, $status);
    }

    public static function success(
        mixed $data = null,
        string $message = 'OK',
        int $status = 200,
        ?array $pagination = null,
        ?array $meta = null,
    ): JsonResponse {
        return self::json($status, $message, $data, $pagination, $meta);
    }

    public static function created(mixed $data = null, string $message = 'Created successfully.'): JsonResponse
    {
        return self::success($data, $message, 201);
    }

    public static function noContent(string $message = 'Deleted successfully.'): JsonResponse
    {
        return self::json(204, $message, []);
    }

    public static function error(
        string $message,
        int $status = 400,
        mixed $errors = null,
        mixed $data = null,
    ): JsonResponse {
        $payload = [
            'success' => false,
            'status' => 'error',
            'code' => $status,
            'message' => $message,
            'data' => $data ?? [],
            'pagination' => null,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }

    /**
     * @param  class-string<JsonResource>  $resourceClass
     */
    public static function paginated(
        mixed $query,
        string $resourceClass,
        string $message = 'OK',
        ?string $groupBy = null,
    ): JsonResponse {
        $result = ApiPaginator::resolve($query, $resourceClass, $groupBy);

        return self::success($result['data'], $message, 200, $result['pagination']);
    }

    public static function fromPaginator(
        LengthAwarePaginator $paginator,
        mixed $data,
        string $message = 'OK',
    ): JsonResponse {
        return self::success($data, $message, 200, ApiPaginator::meta($paginator));
    }
}
