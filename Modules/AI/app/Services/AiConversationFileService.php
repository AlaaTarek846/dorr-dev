<?php

namespace Modules\AI\Services;

use App\Support\Api\ApiResponse;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Modules\AI\Exceptions\AiFileException;
use Modules\AI\Http\Resources\AiFileResource;
use Modules\AI\Repositories\AiConversationRepository;
use Modules\AI\Repositories\AiFileRepository;

/**
 * Phase 10 (doc S27): the customer-facing conversation-files API
 * (GET/POST/DELETE user/v1/ai-chat/conversations/{conversation}/files),
 * same thin-controller-delegates-to-service shape as
 * AiFileUploadService. Every method re-validates conversation AND file
 * ownership itself via the existing *::findForOwner() repositories
 * (doc S26: never bypass Phase 9's owner resolution) - a conversation or
 * file id belonging to another owner 404s via findOrFail(), exactly
 * like every other endpoint in this module, rather than leaking via a
 * different error shape.
 */
class AiConversationFileService
{
    public function __construct(
        protected AiConversationRepository $conversations,
        protected AiFileRepository $files,
        protected AiConversationFileScope $scope,
    ) {}

    public function index(Authenticatable $owner, int|string $conversationId): JsonResponse
    {
        $conversation = $this->conversations->findForOwner($owner, $conversationId);

        $files = $this->scope->listAttachedFiles($owner, $conversation);

        return ApiResponse::success(AiFileResource::collection($files), __('api.retrieved'));
    }

    public function store(Authenticatable $owner, int|string $conversationId, int|string $fileId): JsonResponse
    {
        $conversation = $this->conversations->findForOwner($owner, $conversationId);
        $file = $this->files->findForOwner($owner, $fileId);

        $this->scope->attach($conversation, $file, $owner);

        return ApiResponse::success(new AiFileResource($file), __('api.updated'));
    }

    public function destroy(Authenticatable $owner, int|string $conversationId, int|string $fileId): JsonResponse
    {
        $conversation = $this->conversations->findForOwner($owner, $conversationId);
        $file = $this->files->findForOwner($owner, $fileId);

        if (! $this->scope->detach($conversation, $file)) {
            throw AiFileException::forReason('file_not_attached');
        }

        return ApiResponse::noContent(__('api.deleted'));
    }
}
