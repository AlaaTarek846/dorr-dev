<?php

namespace App\Services\Concerns;

use App\Support\Api\ApiResponse;
use App\Support\BulkDelete\BulkDeleteMessage;
use App\Support\BulkDelete\BulkDeleteResult;
use Illuminate\Http\JsonResponse;

trait RespondsToBulkDelete
{
    /**
     * @param  list<int|string>  $ids
     */
    public function deleteMultiple(array $ids, ?string $message = null): JsonResponse
    {
        $result = $this->repository->deleteMultiple($ids);

        return $this->respondToBulkDelete($result, $message);
    }

    protected function respondToBulkDelete(BulkDeleteResult $result, ?string $message = null): JsonResponse
    {
        if ($message !== null) {
            return ApiResponse::success(
                $result->toArray(),
                $message,
                $result->anyDeleted() ? 200 : 409,
            );
        }

        $payload = BulkDeleteMessage::for($result);

        return ApiResponse::success(
            $result->toArray(),
            $payload['message'],
            $payload['status'],
        );
    }
}
