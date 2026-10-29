<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Services\ChatExportService;
use Modules\Chat\Services\ChatStorageService;

/**
 * Manage storage (backlog 46–48) and export a chat (25).
 */
class ChatStorageController extends Controller
{
    public function __construct(private readonly ChatStorageService $storage) {}

    public function overview(Request $request)
    {
        return ApiResponse::success($this->storage->overview($request->user()), __('api.retrieved'));
    }

    /** `kind` all · large · forwarded, `conversation_id?`, `page`, `per_page` (default 15). */
    public function files(Request $request)
    {
        $data = $request->validate([
            'kind' => ['sometimes', Rule::in(['all', 'large', 'forwarded'])],
            'conversation_id' => ['sometimes', 'nullable', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        return ApiResponse::success($this->storage->list(
            $request->user(),
            $data['kind'] ?? 'all',
            $data['conversation_id'] ?? null,
            (int) ($data['page'] ?? 1),
            (int) ($data['per_page'] ?? 15),
        ), __('api.retrieved'));
    }

    /** `{messages[]}` — deleted for me only, in whichever chats they are. */
    public function delete(Request $request)
    {
        $data = $request->validate(['messages' => ['required', 'array', 'min:1', 'max:500'], 'messages.*' => ['uuid']]);

        return ApiResponse::success(['deleted' => $this->storage->delete($request->user(), $data['messages'])], __('api.deleted'));
    }

    /** `media` 0 · 1 — a .txt, or a .zip with the files too. */
    public function export(Request $request, ChatConversation $conversation, ChatExportService $export)
    {
        $file = $export->export($request->user(), $conversation, $request->boolean('media'));

        return response()->download($file['path'], $file['name'], ['Content-Type' => $file['mime']])->deleteFileAfterSend();
    }
}
