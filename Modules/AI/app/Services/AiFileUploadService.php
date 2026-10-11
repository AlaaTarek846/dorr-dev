<?php

namespace Modules\AI\Services;

use App\Support\Api\ApiResponse;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Modules\AI\Exceptions\AiFileException;
use Modules\AI\Http\Resources\AiFileResource;
use Modules\AI\Models\AiFile;
use Modules\AI\Repositories\AiConversationRepository;
use Modules\AI\Repositories\AiFileRepository;

/**
 * Acceptance criteria doc S4/S14: the customer-facing File Engine API
 * (POST/GET/DELETE /user/v1/ai-files, status) delegates everything here
 * - AiFileUploadController stays a thin pass-through (doc S4: "the
 * controller must NOT contain the complete file-processing logic"),
 * exactly like AiChatController delegates to AiChatService.
 */
class AiFileUploadService
{
    public function __construct(
        protected AiFileEngine $engine,
        protected AiFileRepository $files,
        protected AiConversationRepository $conversations,
    ) {}

    public function store(Authenticatable $owner, UploadedFile $uploadedFile, int|string|null $conversationId): JsonResponse
    {
        $conversation = $conversationId
            ? $this->conversations->findForOwner($owner, $conversationId)
            : null;

        $file = $this->engine->storeUploadedFile($owner, $uploadedFile, $conversation);

        if ($file->processing_status === AiFile::STATUS_FAILED) {
            Log::info('ai_file.upload_rejected', [
                'file_id' => $file->id,
                'reason' => $file->processing_error,
            ]);

            throw AiFileException::forReason((string) $file->processing_error);
        }

        return ApiResponse::created(new AiFileResource($file), __('api.created'));
    }

    public function show(Authenticatable $owner, int|string $id): JsonResponse
    {
        $file = $this->files->findForOwner($owner, $id);

        return ApiResponse::success(new AiFileResource($file), __('api.retrieved'));
    }

    public function status(Authenticatable $owner, int|string $id): JsonResponse
    {
        $file = $this->files->findForOwner($owner, $id);

        return ApiResponse::success([
            'id' => $file->id,
            'status' => $file->processing_status,
            'processing_status' => $file->processing_status,
            'processing_error' => $file->processing_error,
        ], __('api.retrieved'));
    }

    public function destroy(Authenticatable $owner, int|string $id): JsonResponse
    {
        $file = $this->files->findForOwner($owner, $id);

        $this->engine->delete($file);

        return ApiResponse::noContent(__('api.deleted'));
    }
}
