<?php

namespace App\Support\BulkDelete;

class BulkDeleteMessage
{
    /**
     * @return array{message: string, status: int}
     */
    public static function for(BulkDeleteResult $result): array
    {
        if ($result->anyDeleted() && $result->skippedCount() === 0) {
            return [
                'message' => __('api.bulk_deleted_all', ['count' => $result->deleted]),
                'status' => 200,
            ];
        }

        if ($result->anyDeleted()) {
            return [
                'message' => __('api.bulk_deleted_partial', [
                    'deleted' => $result->deleted,
                    'skipped' => $result->skippedCount(),
                ]),
                'status' => 200,
            ];
        }

        return [
            'message' => __('api.bulk_deleted_none'),
            'status' => 409,
        ];
    }
}
