<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Modules\AI\Http\Requests\AiConversationFileAttachRequest;
use Modules\AI\Services\AiConversationFileService;

/**
 * Phase 10 (doc S27): customer-facing conversation-files API. Deliberately
 * thin - every method is a one-line delegation to
 * AiConversationFileService, same shape as AiFileUploadController/
 * AiChatController.
 */
class AiConversationFileController extends Controller
{
    public function __construct(protected AiConversationFileService $service) {}

    public function index(Request $request, int|string $conversation)
    {
        return $this->service->index($this->owner($request), $conversation);
    }

    public function store(AiConversationFileAttachRequest $request, int|string $conversation)
    {
        return $this->service->store($this->owner($request), $conversation, $request->validated('file_id'));
    }

    public function destroy(Request $request, int|string $conversation, int|string $file)
    {
        return $this->service->destroy($this->owner($request), $conversation, $file);
    }

    protected function owner(Request $request): Authenticatable
    {
        return $request->user('user_api') ?? $request->user('provider_api');
    }
}
