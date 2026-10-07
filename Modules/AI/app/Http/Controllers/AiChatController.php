<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Modules\AI\Http\Requests\AiChatMessageRequest;
use Modules\AI\Services\AiChatService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiChatController extends Controller
{
    public function __construct(protected AiChatService $service) {}

    public function status(Request $request)
    {
        return $this->service->activeProviderStatus();
    }

    public function usage(Request $request)
    {
        return $this->service->usageStatus($this->owner($request));
    }

    public function index(Request $request)
    {
        return $this->service->listConversations($this->owner($request));
    }

    public function store(Request $request)
    {
        return $this->service->createConversation($this->owner($request));
    }

    public function show(Request $request, int|string $conversation)
    {
        return $this->service->showConversation($this->owner($request), $conversation);
    }

    public function destroy(Request $request, int|string $conversation)
    {
        return $this->service->deleteConversation($this->owner($request), $conversation);
    }

    public function sendMessage(AiChatMessageRequest $request, int|string $conversation)
    {
        return $this->service->sendMessage(
            $this->owner($request),
            $conversation,
            // 'message' is now nullable when an attachment is present (see
            // AiChatMessageRequest - a voice-only send has nothing typed),
            // but sendMessage()'s $content parameter is a plain non-nullable
            // string, so null must be coalesced here rather than passed
            // through and fatal with a TypeError.
            $request->validated('message') ?? '',
            $request->file('attachment'),
            $request->header('Idempotency-Key'),
            // Phase 10: explicit per-message file scope, null when the
            // client did not send it (falls back to the conversation's
            // own attached-file scope - see
            // AiChatService::resolveFileRetrievalContext()).
            $request->validated('file_ids'),
        );
    }

    /**
     * v2.0 requirements doc 18.2 (streaming). This progressively delivers
     * the ALREADY-VERIFIED answer over Server-Sent Events rather than
     * streaming raw provider tokens as they generate - see
     * AiChatService::streamMessage() for why that distinction matters.
     * Attachments still go through the plain, non-streamed sendMessage()
     * endpoint above (multipart upload + SSE response is out of scope
     * here); this endpoint only accepts a plain text message.
     */
    public function streamMessage(AiChatMessageRequest $request, int|string $conversation): StreamedResponse
    {
        return $this->service->streamMessage(
            $this->owner($request),
            $conversation,
            $request->validated('message'),
            $request->header('Idempotency-Key'),
            // Phase 11 (doc S12): same explicit per-message file scope
            // the plain sendMessage() endpoint already accepts - see
            // AiChatService::streamMessage()'s own docblock for why the
            // streaming path needs it too.
            $request->validated('file_ids'),
        );
    }

    /**
     * v2.0 requirements doc 17.3: self-service export of the requester's
     * own AI conversation history as a downloadable JSON file.
     */
    public function exportData(Request $request)
    {
        $data = $this->service->exportOwnerData($this->owner($request));

        $fileName = 'ai-data-export-'.now()->format('Y-m-d-His').'.json';

        return response()->json($data)
            ->header('Content-Disposition', 'attachment; filename="'.$fileName.'"');
    }

    /**
     * v2.0 requirements doc 17.3: self-service, irreversible erase of all
     * of the requester's own AI conversations.
     */
    public function eraseData(Request $request)
    {
        return $this->service->eraseOwnerData($this->owner($request));
    }

    /**
     * This controller is shared between the User chat routes
     * (routes/user.php, guard user_api) and the Provider chat routes
     * (routes/provider.php, guard provider_api). Only one of the two
     * guards is ever authenticated for a given request.
     */
    protected function owner(Request $request): Authenticatable
    {
        return $request->user('user_api') ?? $request->user('provider_api');
    }
}
