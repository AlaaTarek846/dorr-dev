<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Chat\Http\Requests\SendMessageRequest;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Services\MessageService;

/**
 * Messages inside a conversation (docs/chat-plan.md §3.3–3.4 + reply / forward / edit).
 */
class MessageController extends Controller
{
    public function __construct(private readonly MessageService $messages) {}

    public function index(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate([
            'before' => ['nullable', 'uuid'],
            'after' => ['nullable', 'uuid'],
            'around' => ['nullable', 'uuid'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->messages->page($request->user(), $conversation, $data, (int) ($data['limit'] ?? 50));

        return ApiResponse::success([
            'messages' => $this->messages->present($page['messages'], $page['context']),
            'has_more_before' => $page['has_more_before'],
            'has_more_after' => $page['has_more_after'],
        ], __('api.retrieved'));
    }

    public function store(SendMessageRequest $request, ChatConversation $conversation)
    {
        $me = $request->user();
        $message = $this->messages->send($me, $conversation, $request->validated() + ['thumbnail_file' => $request->file('thumbnail')], $request->file('files', []));

        return ApiResponse::created($this->messages->presentOne($me, $message), __('api.created'));
    }

    public function update(Request $request, ChatMessage $message)
    {
        $data = $request->validate(['body' => ['present', 'nullable', 'string', 'max:65000']]);
        $me = $request->user();

        return ApiResponse::success($this->messages->presentOne($me, $this->messages->edit($me, $message, (string) $data['body'])), __('api.updated'));
    }

    /**
     * Delete for everyone.
     */
    public function destroy(Request $request, ChatMessage $message)
    {
        $me = $request->user();

        return ApiResponse::success($this->messages->presentOne($me, $this->messages->deleteForEveryone($me, $message)), __('api.deleted'));
    }

    public function deleteForMe(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['messages' => ['required', 'array', 'min:1', 'max:200'], 'messages.*' => ['uuid']]);

        return ApiResponse::success(['deleted' => $this->messages->deleteForMe($request->user(), $conversation, $data['messages'])], __('api.deleted'));
    }

    public function forward(Request $request)
    {
        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:30'], 'messages.*' => ['uuid'],
            'conversations' => ['required', 'array', 'min:1'], 'conversations.*' => ['uuid'],
        ]);

        $me = $request->user();
        $sent = $this->messages->forward($me, $data['messages'], $data['conversations']);

        return ApiResponse::created(array_map(fn ($m) => $this->messages->presentOne($me, $m), $sent), __('api.created'));
    }

    public function react(Request $request, ChatMessage $message)
    {
        $data = $request->validate(['emoji' => ['present', 'nullable', 'string', 'max:32']]);
        $me = $request->user();

        return ApiResponse::success($this->messages->presentOne($me, $this->messages->react($me, $message, $data['emoji'])), __('api.updated'));
    }

    public function reactions(Request $request, ChatMessage $message)
    {
        return ApiResponse::success($this->messages->reactions($request->user(), $message), __('api.retrieved'));
    }

    public function star(Request $request, ChatMessage $message)
    {
        $data = $request->validate(['starred' => ['required', 'boolean']]);
        $this->messages->star($request->user(), $message, (bool) $data['starred']);

        return ApiResponse::success(['is_starred' => (bool) $data['starred']], __('api.updated'));
    }

    public function starred(Request $request, ?ChatConversation $conversation = null)
    {
        return ApiResponse::success($this->messages->starred($request->user(), $conversation), __('api.retrieved'));
    }

    public function pin(Request $request, ChatMessage $message)
    {
        $data = $request->validate(['duration_seconds' => ['required', Rule::in([86400, 604800, 2592000])]]);
        $this->messages->pin($request->user(), $message, (int) $data['duration_seconds']);

        return ApiResponse::success($this->messages->pinned($request->user(), $message->conversation), __('api.updated'));
    }

    public function unpin(Request $request, ChatMessage $message)
    {
        $this->messages->unpin($request->user(), $message);

        return ApiResponse::success($this->messages->pinned($request->user(), $message->conversation), __('api.updated'));
    }

    public function pinned(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::success($this->messages->pinned($request->user(), $conversation), __('api.retrieved'));
    }

    public function info(Request $request, ChatMessage $message)
    {
        return ApiResponse::success($this->messages->info($request->user(), $message), __('api.retrieved'));
    }

    public function search(Request $request, ?ChatConversation $conversation = null)
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        return ApiResponse::success($this->messages->search($request->user(), $data['q'], $conversation), __('api.retrieved'));
    }

    public function gallery(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['media', 'documents', 'audio', 'links', 'locations'])],
            'before' => ['nullable', 'uuid'],
        ]);

        $page = $this->messages->gallery($request->user(), $conversation, $data['kind'], $data['before'] ?? null);

        return ApiResponse::success([
            'messages' => $this->messages->present($page['messages'], $page['context']),
            'has_more' => $page['has_more'],
        ], __('api.retrieved'));
    }
}
