<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroupTask;
use Modules\Chat\Services\GroupTaskService;

/**
 * Tasks in work groups (docs/groups_tasks.md GR-033..036). `{task}` is its uuid; who may do what is
 * checked in GroupTaskService.
 */
class GroupTaskController extends Controller
{
    public function __construct(private readonly GroupTaskService $tasks) {}

    /** GET groups/{c}/tasks?status=open|review|done|cancelled|all&mine=1&space_id=&page= — 15 a page. */
    public function index(Request $request, ChatConversation $conversation)
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'review', 'done', 'cancelled', 'all'])],
            'mine' => ['nullable', 'boolean'],
            'space_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return ApiResponse::success($this->tasks->list($request->user(), $conversation, $filters), __('api.retrieved'));
    }

    public function store(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::created($this->tasks->create($request->user(), $conversation, $this->validated($request, true)), __('api.created'));
    }

    public function show(Request $request, ChatConversation $conversation, string $task)
    {
        return ApiResponse::success($this->tasks->show($request->user(), $conversation, $task), __('api.retrieved'));
    }

    public function update(Request $request, ChatConversation $conversation, string $task)
    {
        return ApiResponse::success($this->tasks->update($request->user(), $conversation, $task, $this->validated($request, false)), __('api.updated'));
    }

    /** POST groups/{c}/tasks/{task}/status `{status: in_progress|done}`. */
    public function status(Request $request, ChatConversation $conversation, string $task)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['in_progress', 'done'])]]);

        return ApiResponse::success($this->tasks->setStatus($request->user(), $conversation, $task, $data['status']), __('api.updated'));
    }

    /** POST groups/{c}/tasks/{task}/review `{approve: bool, note?}` — a note is needed to send it back. */
    public function review(Request $request, ChatConversation $conversation, string $task)
    {
        $data = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:1000']]);

        return ApiResponse::success($this->tasks->review($request->user(), $conversation, $task, (bool) $data['approve'], $data['note'] ?? null), __('api.updated'));
    }

    /** POST groups/{c}/tasks/{task}/proof — multipart `files[]` (≤5 in all) and/or `note`. */
    public function proof(Request $request, ChatConversation $conversation, string $task)
    {
        $request->validate([
            'files' => ['nullable', 'array', 'max:'.GroupTaskService::MAX_PROOF_FILES],
            'files.*' => ['file', 'max:51200'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        return ApiResponse::success($this->tasks->addProof(
            $request->user(), $conversation, $task, (array) $request->file('files', []), $request->has('note') ? (string) $request->input('note') : null,
        ), __('api.updated'));
    }

    public function addItem(Request $request, ChatConversation $conversation, string $task)
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:300']]);

        return ApiResponse::success($this->tasks->addItem($request->user(), $conversation, $task, $data['text']), __('api.updated'));
    }

    public function tickItem(Request $request, ChatConversation $conversation, string $task, int $item)
    {
        $data = $request->validate(['done' => ['required', 'boolean']]);

        return ApiResponse::success($this->tasks->tickItem($request->user(), $conversation, $task, $item, (bool) $data['done']), __('api.updated'));
    }

    public function removeItem(Request $request, ChatConversation $conversation, string $task, int $item)
    {
        return ApiResponse::success($this->tasks->removeItem($request->user(), $conversation, $task, $item), __('api.updated'));
    }

    public function destroy(Request $request, ChatConversation $conversation, string $task)
    {
        $this->tasks->cancel($request->user(), $conversation, $task);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** GET groups/{c}/tasks/report — `reports.view`: totals, each member's load, the late ones (GR-039). */
    public function report(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::success($this->tasks->report($request->user(), $conversation), __('api.retrieved'));
    }

    /** GET groups/{c}/tasks/export — `reports.view`: a CSV of the tasks I may see (opens in Excel). */
    public function export(Request $request, ChatConversation $conversation)
    {
        $file = $this->tasks->export($request->user(), $conversation);

        return response($file['csv'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$file['name'].'"',
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $sometimes = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'title' => [$sometimes, 'string', 'min:1', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'assignee_participant_id' => ['sometimes', 'nullable', 'integer'],
            'priority' => ['sometimes', Rule::in(ChatGroupTask::PRIORITIES)],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'needs_approval' => ['sometimes', 'boolean'],
            'requires_proof' => ['sometimes', 'boolean'],
            'repeat' => ['sometimes', 'nullable', Rule::in(ChatGroupTask::REPEATS)],
            'repeat_until' => ['sometimes', 'nullable', 'date'],
            'space_id' => ['sometimes', 'nullable', 'integer'],
            'items' => ['sometimes', 'array', 'max:50'],
            'items.*' => ['nullable', 'string', 'max:300'],
            // GR-038: made from this message (uuid) — a safe reference, never a copy.
            'source_message_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
    }
}
