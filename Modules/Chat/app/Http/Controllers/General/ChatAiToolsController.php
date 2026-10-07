<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatTask;
use Modules\Chat\Services\ChatAiToolsService;
use Modules\Chat\Services\MessageService;
use Modules\Chat\Services\TaskService;

/**
 * DORR AI tools in the chat (spec 31, 36–42, 46, 48, 49) and my tasks (38). Every AI call is one
 * explicit tap; results are suggestions until I confirm.
 */
class ChatAiToolsController extends Controller
{
    public function __construct(
        private readonly ChatAiToolsService $tools,
        private readonly TaskService $tasks,
    ) {}

    /** POST conversations/{c}/assistant — `{question, messages?[], history?[{q, a}]}` (31). */
    public function assistant(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:1000'],
            'messages' => ['nullable', 'array', 'max:50'],
            'messages.*' => ['uuid'],
            'history' => ['nullable', 'array', 'max:10'],
            'history.*.q' => ['required', 'string', 'max:1000'],
            'history.*.a' => ['required', 'string', 'max:4000'],
        ]);

        return ApiResponse::success($this->tools->assistant($request->user(), $conversation, $data['question'], $data['messages'] ?? null, $data['history'] ?? []), __('api.retrieved'));
    }

    /** POST ai/proofread — `{text}` (36): the text I'm writing, corrected. */
    public function proofread(Request $request)
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:4000']]);

        return ApiResponse::success($this->tools->proofread($data['text']), __('api.retrieved'));
    }

    /** POST messages/{m}/understand (37, 42). */
    public function understand(Request $request, ChatMessage $message)
    {
        return ApiResponse::success($this->tools->understand($request->user(), $message), __('api.retrieved'));
    }

    /** POST messages/{m}/simplify (46). */
    public function simplify(Request $request, ChatMessage $message)
    {
        return ApiResponse::success($this->tools->simplify($request->user(), $message), __('api.retrieved'));
    }

    /** POST messages/{m}/tasks — `{timezone?}` (38): suggested tasks, to save with POST tasks. */
    public function tasksFrom(Request $request, ChatMessage $message)
    {
        $data = $request->validate(['timezone' => ['nullable', 'timezone:all']]);

        return ApiResponse::success($this->tools->tasksFrom($request->user(), $message, $data['timezone'] ?? $this->zone($request)), __('api.retrieved'));
    }

    /** POST conversations/{c}/note — `{messages[]}` (39): a note saved into "Notes (you)". */
    public function note(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['messages' => ['required', 'array', 'min:1', 'max:50'], 'messages.*' => ['uuid']]);
        $me = $request->user();
        $note = $this->tools->note($me, $conversation, $data['messages']);

        return ApiResponse::created([
            'title' => $note['title'],
            'points' => $note['points'],
            'message' => app(MessageService::class)->presentOne($me, $note['message']),
        ], __('api.created'));
    }

    /** POST conversations/{c}/dates — `{messages?[], timezone?}` (40): suggestions only. */
    public function dates(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['messages' => ['nullable', 'array', 'max:200'], 'messages.*' => ['uuid'], 'timezone' => ['nullable', 'timezone:all']]);

        return ApiResponse::success($this->tools->dates($request->user(), $conversation, $data['messages'] ?? null, $data['timezone'] ?? $this->zone($request)), __('api.retrieved'));
    }

    /** POST ai/important — `{conversation_id?}` (41): in one chat, or across my unread chats. */
    public function important(Request $request)
    {
        $data = $request->validate(['conversation_id' => ['nullable', 'uuid']]);
        $conversation = isset($data['conversation_id']) ? ChatConversation::query()->where('uuid', $data['conversation_id'])->firstOrFail() : null;

        return ApiResponse::success($this->tools->important($request->user(), $conversation), __('api.retrieved'));
    }

    /** POST conversations/{c}/related-files (48). */
    public function relatedFiles(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::success($this->tools->relatedFiles($request->user(), $conversation), __('api.retrieved'));
    }

    /** POST ai/today — `{timezone?}` (49): today across my chats. */
    public function today(Request $request)
    {
        $data = $request->validate(['timezone' => ['nullable', 'timezone:all']]);

        return ApiResponse::success($this->tools->today($request->user(), $data['timezone'] ?? $this->zone($request)), __('api.retrieved'));
    }

    // ------------------------------------------------------------------ my tasks (38)

    public function tasks(Request $request)
    {
        $done = $request->query('status') === 'done';
        $rows = ChatTask::query()->ownedBy($request->user())->with(['message', 'conversation'])
            ->when($done, fn ($q) => $q->whereNotNull('done_at')->latest('done_at')->limit(200), fn ($q) => $q->whereNull('done_at')->orderByRaw('due_at is null')->orderBy('due_at')->orderBy('sort_order'))
            ->get();

        return ApiResponse::success($rows->map(fn ($t) => $this->tasks->present($t))->values(), __('api.retrieved'));
    }

    /** POST tasks — `{tasks: [{text, due_at?}], message_id?}`. */
    public function storeTasks(Request $request)
    {
        $data = $request->validate([
            'tasks' => ['required', 'array', 'min:1', 'max:20'],
            'tasks.*.text' => ['required', 'string', 'max:300'],
            'tasks.*.due_at' => ['nullable', 'date'],
            'message_id' => ['nullable', 'uuid'],
        ]);
        $created = $this->tasks->create($request->user(), $data['tasks'], $data['message_id'] ?? null);

        return ApiResponse::created(collect($created)->map(fn ($t) => $this->tasks->present($t->load(['message', 'conversation'])))->values(), __('api.created'));
    }

    public function updateTask(Request $request, ChatTask $task)
    {
        $data = $request->validate(['text' => ['sometimes', 'string', 'max:300'], 'due_at' => ['sometimes', 'nullable', 'date'], 'done' => ['sometimes', 'boolean']]);

        return ApiResponse::success($this->tasks->present($this->tasks->update($request->user(), $task, $data)), __('api.updated'));
    }

    public function destroyTask(Request $request, ChatTask $task)
    {
        $this->tasks->assertMine($request->user(), $task);
        $task->delete();

        return ApiResponse::success(null, __('api.deleted'));
    }

    private function zone(Request $request): string
    {
        $zone = $request->user()?->timezone ?? null;

        return $zone && in_array($zone, timezone_identifiers_list(), true) ? $zone : 'UTC';
    }
}
