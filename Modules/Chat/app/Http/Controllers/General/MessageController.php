<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Chat\Http\Requests\SendMessageRequest;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Services\MessageReminderService;
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
            // A thread's replies instead of the timeline (spec 122).
            'thread' => ['nullable', 'uuid'],
        ]);

        $me = $request->user();
        $page = $this->messages->page($me, $conversation, $data, (int) ($data['limit'] ?? 50));

        return ApiResponse::success([
            'messages' => $this->messages->present($page['messages'], $page['context']),
            'has_more_before' => $page['has_more_before'],
            'has_more_after' => $page['has_more_after'],
        ] + (! empty($data['thread']) ? ['root' => $this->messages->presentOne($me, $this->messages->findInConversation($conversation, $data['thread']))] : []), __('api.retrieved'));
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
        $data = $request->validate(['starred' => ['required', 'boolean'], 'folder_id' => ['nullable', 'integer']]);
        $this->messages->star($request->user(), $message, (bool) $data['starred'], isset($data['folder_id']) ? (int) $data['folder_id'] : null);

        return ApiResponse::success(['is_starred' => (bool) $data['starred']], __('api.updated'));
    }

    public function readLater(Request $request, ChatMessage $message)
    {
        $data = $request->validate(['on' => ['required', 'boolean']]);
        $this->messages->readLater($request->user(), $message, (bool) $data['on']);

        return ApiResponse::success(['is_read_later' => (bool) $data['on']], __('api.updated'));
    }

    public function readLaterList(Request $request)
    {
        return ApiResponse::success($this->messages->readLaterList($request->user()), __('api.retrieved'));
    }

    /**
     * "Needs a reply": on my follow-up list until I answer (or `on: false`).
     */
    public function followUp(Request $request, ChatMessage $message)
    {
        $data = $request->validate(['on' => ['required', 'boolean']]);
        $this->messages->followUp($request->user(), $message, (bool) $data['on']);

        return ApiResponse::success(['is_follow_up' => (bool) $data['on']], __('api.updated'));
    }

    public function followUpList(Request $request)
    {
        return ApiResponse::success($this->messages->followUpList($request->user()), __('api.retrieved'));
    }

    /**
     * Remind me about this message at `remind_at` (ISO-8601 with offset), with an optional note.
     */
    public function setReminder(Request $request, ChatMessage $message, MessageReminderService $reminders)
    {
        $data = $request->validate(['remind_at' => ['required', 'date'], 'note' => ['nullable', 'string', 'max:200']]);
        $reminder = $reminders->set($request->user(), $message, Carbon::parse($data['remind_at']), $data['note'] ?? null);

        return ApiResponse::success(['reminder_at' => $reminder->remind_at->toIso8601String(), 'note' => $reminder->note], __('api.updated'));
    }

    public function clearReminder(Request $request, ChatMessage $message, MessageReminderService $reminders)
    {
        $reminders->clear($request->user(), $message);

        return ApiResponse::success(['reminder_at' => null], __('api.deleted'));
    }

    public function reminders(Request $request, MessageReminderService $reminders)
    {
        return ApiResponse::success($reminders->upcoming($request->user()), __('api.retrieved'));
    }

    public function starred(Request $request, ?ChatConversation $conversation = null)
    {
        $folder = $request->query('folder');

        return ApiResponse::success($this->messages->starred($request->user(), $conversation, $folder === 'none' ? 'none' : (is_numeric($folder) ? (int) $folder : null)), __('api.retrieved'));
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
        $data = $request->validate([
            'q' => ['required_without:types', 'nullable', 'string', 'min:2', 'max:100'],
            'types' => ['nullable', 'array'],
            'types.*' => [Rule::in(['text', 'image', 'video', 'voice', 'document', 'link', 'location', 'poll', 'contact', 'sticker', 'money'])],
        ]);

        return ApiResponse::success($this->messages->search($request->user(), $data['q'] ?? null, $conversation, $data['types'] ?? []), __('api.retrieved'));
    }

    public function gallery(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['media', 'documents', 'audio', 'links', 'locations'])],
            'before' => ['nullable', 'uuid'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'file_kind' => ['nullable', Rule::in(['pdf', 'word', 'excel', 'slides', 'archive', 'other'])],
            'min_size' => ['nullable', 'integer', 'min:0'],
            'max_size' => ['nullable', 'integer', 'min:0'],
            'sort' => ['nullable', Rule::in(['date', 'size'])],
        ]);

        $page = $this->messages->gallery($request->user(), $conversation, $data['kind'], $data['before'] ?? null, 60, $data);

        return ApiResponse::success([
            'messages' => $this->messages->present($page['messages'], $page['context']),
            'has_more' => $page['has_more'],
        ], __('api.retrieved'));
    }
}
