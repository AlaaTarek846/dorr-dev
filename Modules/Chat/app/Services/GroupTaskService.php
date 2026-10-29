<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Chat\Enums\GroupKind;
use Modules\Chat\Enums\ParticipantRole;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroupAuditLog;
use Modules\Chat\Models\ChatGroupMemberRole;
use Modules\Chat\Models\ChatGroupRole;
use Modules\Chat\Models\ChatGroupSpace;
use Modules\Chat\Models\ChatGroupTask;
use Modules\Chat\Models\ChatGroupTaskItem;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Support\GroupMedia;
use Modules\Chat\Support\GroupPermissions;
use Modules\Chat\Support\ParticipantDirectory;

/**
 * Tasks in work groups (docs/groups_tasks.md GR-033..036), all checked on the server:
 *
 * - Making one takes `tasks.create`; giving it to someone else takes `tasks.assign`.
 * - Its maker and `tasks.assign` edit or cancel it; its assignee (`tasks.work`) and `tasks.assign`
 *   move it along. A project's tasks are for that project's members only.
 * - Repeating (GR-034): when one is due, `chat:spawn-recurring-tasks` makes the next.
 * - Checklist and proof (GR-035): with `requires_proof`, it can't be finished without a file or a note.
 * - Approval (GR-036): with `needs_approval`, "done" waits in review until `tasks.approve` accepts
 *   it or sends it back with a note.
 *
 * Everyone concerned hears `chat.group.task` (the open app reloads once) and gets a push
 * (GroupNotifier, type `tasks`). Every step is on the activity log.
 */
class GroupTaskService
{
    public const PER_PAGE = 15;

    public const MAX_PROOF_FILES = 5;

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly ChatBroadcaster $broadcaster,
        private readonly GroupNotifier $notifier,
    ) {}

    /**
     * @param  array{status?: ?string, mine?: bool, space_id?: ?int, page?: int}  $filters  status: open | review | done | cancelled
     * @return array{tasks: list<array<string, mixed>>, has_more: bool}
     */
    public function list(Model $me, ChatConversation $conversation, array $filters): array
    {
        $mine = $this->assertWorkMember($me, $conversation);
        $page = max(1, (int) ($filters['page'] ?? 1));

        $query = ChatGroupTask::query()->where('conversation_id', $conversation->id)
            ->where(fn ($q) => $q->whereNull('space_id')->orWhereIn('space_id', $this->mySpaceIds($me, $conversation)));

        match ($filters['status'] ?? 'open') {
            'review' => $query->where('status', 'in_review'),
            'done' => $query->where('status', 'done'),
            'cancelled' => $query->where('status', 'cancelled'),
            'all' => null,
            default => $query->whereIn('status', ChatGroupTask::OPEN),
        };
        if (! empty($filters['mine'])) {
            $query->where('assignee_participant_id', $mine->id);
        }
        if (! empty($filters['space_id'])) {
            $query->where('space_id', (int) $filters['space_id']);
        }

        $rows = $query->orderByRaw('due_at is null')->orderBy('due_at')->orderByDesc('id')
            ->forPage($page, self::PER_PAGE)->limit(self::PER_PAGE + 1)->with(['items', 'media', 'space.conversation.group'])->get();
        $more = $rows->count() > self::PER_PAGE;
        $rows = $rows->take(self::PER_PAGE);

        return ['tasks' => $this->presentMany($me, $conversation, $mine, $rows), 'has_more' => $more];
    }

    /** @return array<string, mixed> */
    public function show(Model $me, ChatConversation $conversation, string $uuid): array
    {
        $mine = $this->assertWorkMember($me, $conversation);

        return $this->present($me, $conversation, $mine, $this->task($me, $conversation, $uuid));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(Model $me, ChatConversation $conversation, array $data): array
    {
        $mine = $this->assertWorkMember($me, $conversation);
        GroupLifecycleService::assertWritable($conversation);
        $perms = GroupPermissions::ofParticipant($mine, $conversation);
        $this->need($perms, 'tasks.create');

        $space = $this->spaceFor($conversation, $data['space_id'] ?? null, $me);
        $assignee = $this->assignee($conversation, $space, $data['assignee_participant_id'] ?? null);
        if ($assignee !== null && $assignee->id !== $mine->id) {
            $this->need($perms, 'tasks.assign');
        }
        $this->assertRepeat($data);
        $source = $this->sourceMessage($me, $conversation, $data['source_message_id'] ?? null);

        $task = DB::transaction(function () use ($me, $conversation, $mine, $data, $space, $assignee, $source) {
            $task = ChatGroupTask::query()->create([
                'conversation_id' => $conversation->id,
                'space_id' => $space?->id,
                'source_message_id' => $source?->id,
                'title' => trim($data['title']),
                'description' => $data['description'] ?? null,
                'assignee_participant_id' => $assignee?->id,
                'created_by_participant_id' => $mine->id,
                'priority' => $data['priority'] ?? 'normal',
                'due_at' => $data['due_at'] ?? null,
                'needs_approval' => (bool) ($data['needs_approval'] ?? false),
                'requires_proof' => (bool) ($data['requires_proof'] ?? false),
                'repeat' => $data['repeat'] ?? null,
                'repeat_until' => $data['repeat_until'] ?? null,
            ]);
            if ($task->repeat !== null) {
                $task->update(['series_id' => $task->id]);
            }
            foreach (array_values((array) ($data['items'] ?? [])) as $i => $text) {
                if (filled($text)) {
                    ChatGroupTaskItem::query()->create(['task_id' => $task->id, 'text' => trim((string) $text), 'sort_order' => $i]);
                }
            }
            ChatGroupAuditLog::record($conversation, $me, 'task_created', 'task:'.$task->uuid, null, ['title' => $task->title, 'assignee' => $assignee?->id, 'from_message' => $source?->uuid]);

            return $task;
        });

        $this->changed($conversation, $task);
        if ($assignee !== null && $assignee->id !== $mine->id) {
            $this->notifier->notify($conversation, [$assignee], 'tasks', 'task_assigned', ['title' => $task->title], ['task_id' => $task->uuid]);
        }

        return $this->present($me, $conversation, $mine, $task->refresh());
    }

    /**
     * The task's details — by its maker or `tasks.assign` (giving it to someone else takes `tasks.assign`).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(Model $me, ChatConversation $conversation, string $uuid, array $data): array
    {
        $mine = $this->assertWorkMember($me, $conversation);
        GroupLifecycleService::assertWritable($conversation);
        $task = $this->task($me, $conversation, $uuid);
        $perms = GroupPermissions::ofParticipant($mine, $conversation);
        $this->assertEditor($task, $mine, $perms);
        if (! $task->isOpen()) {
            throw new ChatException('task_closed', 409);
        }

        $changes = array_intersect_key($data, array_flip(['title', 'description', 'priority', 'due_at', 'needs_approval', 'requires_proof', 'repeat', 'repeat_until']));
        $newAssignee = null;
        if (array_key_exists('assignee_participant_id', $data) && (int) $data['assignee_participant_id'] !== (int) $task->assignee_participant_id) {
            $this->need($perms, 'tasks.assign');
            $newAssignee = $this->assignee($conversation, $task->space, $data['assignee_participant_id']);
            $changes['assignee_participant_id'] = $newAssignee?->id;
        }
        $this->assertRepeat($changes + ['due_at' => $task->due_at]);

        DB::transaction(function () use ($me, $conversation, $task, $changes) {
            $before = $task->only(array_keys($changes));
            if (array_key_exists('due_at', $changes)) {
                $changes['reminded_at'] = null;
            }
            $task->update($changes);
            if (array_key_exists('repeat', $changes) && $task->repeat !== null && $task->series_id === null) {
                $task->update(['series_id' => $task->id]);
            }
            ChatGroupAuditLog::record($conversation, $me, 'task_updated', 'task:'.$task->uuid, $this->json($before), $this->json($task->only(array_keys($changes))));
        });

        $this->changed($conversation, $task);
        if ($newAssignee !== null && $newAssignee->id !== $mine->id) {
            $this->notifier->notify($conversation, [$newAssignee], 'tasks', 'task_assigned', ['title' => $task->title], ['task_id' => $task->uuid]);
        }

        return $this->present($me, $conversation, $mine, $task->refresh());
    }

    /**
     * Its assignee (or `tasks.assign`) moves it: `in_progress`, or `done` — which waits in review
     * when it needs approval, and needs proof first when it asks for it.
     *
     * @return array<string, mixed>
     */
    public function setStatus(Model $me, ChatConversation $conversation, string $uuid, string $status): array
    {
        $mine = $this->assertWorkMember($me, $conversation);
        GroupLifecycleService::assertWritable($conversation);
        $task = $this->task($me, $conversation, $uuid);
        $perms = GroupPermissions::ofParticipant($mine, $conversation);
        $this->assertWorker($task, $mine, $perms);

        if (! in_array($task->status, ['new', 'in_progress'], true)) {
            throw new ChatException('task_closed', 409);
        }

        $before = $task->status;
        if ($status === 'in_progress') {
            $task->update(['status' => 'in_progress']);
        } else {
            if ($task->requires_proof && ! $task->hasProof()) {
                throw new ChatException('task_proof_required', 422);
            }
            $task->update($task->needs_approval
                ? ['status' => 'in_review', 'submitted_at' => now(), 'review_note' => null]
                : ['status' => 'done', 'completed_at' => now()]);
        }
        ChatGroupAuditLog::record($conversation, $me, 'task_status', 'task:'.$task->uuid, ['status' => $before], ['status' => $task->status]);

        $this->changed($conversation, $task);
        if ($task->status === 'in_review') {
            $this->notifier->notify($conversation, $this->approvers($conversation)->reject(fn ($p) => $p->id === $mine->id), 'tasks', 'task_submitted', ['title' => $task->title], ['task_id' => $task->uuid]);
        }

        return $this->present($me, $conversation, $mine, $task->refresh());
    }

    /**
     * A manager (`tasks.approve`) accepts the work, or sends it back with a note.
     *
     * @return array<string, mixed>
     */
    public function review(Model $me, ChatConversation $conversation, string $uuid, bool $approve, ?string $note): array
    {
        $mine = $this->assertWorkMember($me, $conversation);
        $task = $this->task($me, $conversation, $uuid);
        $this->need(GroupPermissions::ofParticipant($mine, $conversation), 'tasks.approve');

        if ($task->status !== 'in_review') {
            throw new ChatException('task_not_in_review', 409);
        }
        if (! $approve && blank($note)) {
            throw new ChatException('task_review_note_required', 422);
        }

        $task->update($approve
            ? ['status' => 'done', 'completed_at' => now(), 'reviewed_by_participant_id' => $mine->id, 'review_note' => $note]
            : ['status' => 'in_progress', 'reviewed_by_participant_id' => $mine->id, 'review_note' => trim((string) $note)]);
        ChatGroupAuditLog::record($conversation, $me, $approve ? 'task_approved' : 'task_returned', 'task:'.$task->uuid, null, ['note' => $note]);

        $this->changed($conversation, $task);
        if ($task->assignee !== null && $task->assignee_participant_id !== $mine->id) {
            $this->notifier->notify($conversation, [$task->assignee], 'tasks', $approve ? 'task_approved' : 'task_returned', ['title' => $task->title], ['task_id' => $task->uuid]);
        }

        return $this->present($me, $conversation, $mine, $task->refresh());
    }

    /**
     * Proof of the work: files (on the private disk) and/or a note. Its assignee (or `tasks.assign`).
     *
     * @param  list<UploadedFile>  $files
     * @return array<string, mixed>
     */
    public function addProof(Model $me, ChatConversation $conversation, string $uuid, array $files, ?string $note): array
    {
        $mine = $this->assertWorkMember($me, $conversation);
        GroupLifecycleService::assertWritable($conversation);
        $task = $this->task($me, $conversation, $uuid);
        $this->assertWorker($task, $mine, GroupPermissions::ofParticipant($mine, $conversation));

        if (! in_array($task->status, ['new', 'in_progress'], true)) {
            throw new ChatException('task_closed', 409);
        }
        if ($task->getMedia(ChatGroupTask::PROOF)->count() + count($files) > self::MAX_PROOF_FILES) {
            throw new ChatException('task_proof_too_many', 422, ['max' => self::MAX_PROOF_FILES]);
        }

        foreach ($files as $file) {
            $task->addMedia($file)->usingName($file->getClientOriginalName())->toMediaCollection(ChatGroupTask::PROOF, GroupMedia::DISK);
        }
        if ($note !== null) {
            $task->update(['proof_note' => trim($note) ?: null]);
        }
        ChatGroupAuditLog::record($conversation, $me, 'task_proof_added', 'task:'.$task->uuid);
        $this->changed($conversation, $task);

        return $this->present($me, $conversation, $mine, $task->refresh());
    }

    /**
     * Checklist: its maker / `tasks.assign` add or remove lines; its assignee ticks them.
     *
     * @return array<string, mixed>
     */
    public function addItem(Model $me, ChatConversation $conversation, string $uuid, string $text): array
    {
        $mine = $this->assertWorkMember($me, $conversation);
        $task = $this->task($me, $conversation, $uuid);
        $this->assertEditor($task, $mine, GroupPermissions::ofParticipant($mine, $conversation));

        ChatGroupTaskItem::query()->create(['task_id' => $task->id, 'text' => trim($text), 'sort_order' => (int) $task->items()->max('sort_order') + 1]);
        $this->changed($conversation, $task);

        return $this->present($me, $conversation, $mine, $task->refresh());
    }

    /** @return array<string, mixed> */
    public function tickItem(Model $me, ChatConversation $conversation, string $uuid, int $itemId, bool $done): array
    {
        $mine = $this->assertWorkMember($me, $conversation);
        $task = $this->task($me, $conversation, $uuid);
        $this->assertWorker($task, $mine, GroupPermissions::ofParticipant($mine, $conversation));
        $item = $task->items()->find($itemId) ?? throw new ChatException('task_item_not_found', 404);

        $item->update($done ? ['done_at' => now(), 'done_by_participant_id' => $mine->id] : ['done_at' => null, 'done_by_participant_id' => null]);
        $this->changed($conversation, $task);

        return $this->present($me, $conversation, $mine, $task->refresh());
    }

    /** @return array<string, mixed> */
    public function removeItem(Model $me, ChatConversation $conversation, string $uuid, int $itemId): array
    {
        $mine = $this->assertWorkMember($me, $conversation);
        $task = $this->task($me, $conversation, $uuid);
        $this->assertEditor($task, $mine, GroupPermissions::ofParticipant($mine, $conversation));
        ($task->items()->find($itemId) ?? throw new ChatException('task_item_not_found', 404))->delete();
        $this->changed($conversation, $task);

        return $this->present($me, $conversation, $mine, $task->refresh());
    }

    /** Cancelled (kept for the record) — by its maker or `tasks.assign`. */
    public function cancel(Model $me, ChatConversation $conversation, string $uuid): void
    {
        $mine = $this->assertWorkMember($me, $conversation);
        $task = $this->task($me, $conversation, $uuid);
        $this->assertEditor($task, $mine, GroupPermissions::ofParticipant($mine, $conversation));

        $task->update(['status' => 'cancelled']);
        ChatGroupAuditLog::record($conversation, $me, 'task_cancelled', 'task:'.$task->uuid);
        $this->changed($conversation, $task);
    }

    /**
     * GR-034 (`chat:spawn-recurring-tasks`): every repeating task whose due time came gets its next
     * one — the same task, a fresh checklist, the next due time — until `repeat_until`.
     */
    public function spawnRecurring(): int
    {
        $made = 0;
        ChatGroupTask::query()->whereNotNull('repeat')->whereNull('next_spawned_at')->whereNotNull('due_at')
            ->where('due_at', '<=', now())->where('status', '!=', 'cancelled')->with('items')->limit(500)->get()
            ->each(function (ChatGroupTask $task) use (&$made) {
                $next = $this->nextDue($task->due_at, $task->repeat);
                DB::transaction(function () use ($task, $next, &$made) {
                    $task->update(['next_spawned_at' => now()]);
                    if ($task->repeat_until !== null && $next->toDateString() > $task->repeat_until->toDateString()) {
                        return;
                    }
                    $copy = ChatGroupTask::query()->create($task->only([
                        'conversation_id', 'space_id', 'title', 'description', 'assignee_participant_id', 'created_by_participant_id',
                        'priority', 'needs_approval', 'requires_proof', 'repeat', 'repeat_until', 'series_id',
                    ]) + ['due_at' => $next, 'status' => 'new']);
                    foreach ($task->items as $item) {
                        ChatGroupTaskItem::query()->create(['task_id' => $copy->id, 'text' => $item->text, 'sort_order' => $item->sort_order]);
                    }
                    $made++;
                    $conversation = $task->conversation;
                    if ($conversation !== null) {
                        $this->changed($conversation, $copy);
                        if ($copy->assignee !== null) {
                            $this->notifier->notify($conversation, [$copy->assignee], 'tasks', 'task_assigned', ['title' => $copy->title], ['task_id' => $copy->uuid]);
                        }
                    }
                });
            });

        return $made;
    }

    // ------------------------------------------------------------------ presenting

    /**
     * @param  iterable<ChatGroupTask>  $tasks
     * @return list<array<string, mixed>>
     */
    private function presentMany(Model $me, ChatConversation $conversation, ChatParticipant $mine, iterable $tasks): array
    {
        $tasks = collect($tasks);
        $people = ChatParticipant::query()->whereIn('id', $tasks->flatMap(fn ($t) => [$t->assignee_participant_id, $t->created_by_participant_id])->filter()->unique())->get()->keyBy('id');
        app(ParticipantDirectory::class)->prime($me, $people->map(fn ($p) => [$p->participant_type, $p->participant_id]));

        return $tasks->map(fn (ChatGroupTask $t) => $this->present($me, $conversation, $mine, $t, $people))->values()->all();
    }

    /**
     * @param  ?\Illuminate\Support\Collection<int, ChatParticipant>  $people
     * @return array<string, mixed>
     */
    private function present(Model $me, ChatConversation $conversation, ChatParticipant $mine, ChatGroupTask $task, $people = null): array
    {
        $directory = app(ParticipantDirectory::class);
        $person = function (?int $id) use ($me, $directory, $people) {
            $row = $id === null ? null : ($people?->get($id) ?? ChatParticipant::query()->find($id));

            return $row === null ? null : ['participant_id' => $row->id, 'profile' => $directory->profile($me, $row->participant_type, $row->participant_id)];
        };
        $perms = GroupPermissions::ofParticipant($mine, $conversation);
        $items = $task->items;

        return [
            'id' => $task->uuid,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status,
            'priority' => $task->priority,
            'due_at' => $task->due_at?->toIso8601String(),
            'is_late' => $task->isOpen() && $task->due_at !== null && $task->due_at->isPast(),
            'needs_approval' => $task->needs_approval,
            'requires_proof' => $task->requires_proof,
            'assignee' => $person($task->assignee_participant_id),
            'created_by' => $person($task->created_by_participant_id),
            'space' => $task->space_id === null ? null : ['id' => $task->space_id, 'name' => $task->space?->conversation?->group?->name],
            'items' => $items->map(fn (ChatGroupTaskItem $i) => ['id' => $i->id, 'text' => $i->text, 'done' => $i->done_at !== null])->values()->all(),
            'items_done' => $items->whereNotNull('done_at')->count(),
            'proof' => [
                'note' => $task->proof_note,
                'files' => $task->getMedia(ChatGroupTask::PROOF)->map(fn ($m) => ['id' => $m->uuid, 'url' => $m->getUrl(), 'name' => $m->name, 'mime_type' => $m->mime_type])->values()->all(),
            ],
            'review_note' => $task->review_note,
            'source' => $this->presentSource($me, $task),
            'repeat' => $task->repeat,
            'repeat_until' => $task->repeat_until?->toDateString(),
            'can' => [
                'edit' => $task->isOpen() && $this->isEditor($task, $mine, $perms),
                'work' => in_array($task->status, ['new', 'in_progress'], true) && $this->isWorker($task, $mine, $perms),
                'review' => $task->status === 'in_review' && in_array('tasks.approve', $perms, true),
            ],
            'submitted_at' => $task->submitted_at?->toIso8601String(),
            'completed_at' => $task->completed_at?->toIso8601String(),
            'created_at' => $task->created_at?->toIso8601String(),
        ];
    }

    // ------------------------------------------------------------------ GR-039 follow-up

    /**
     * The team's picture for `reports.view` — only over the tasks the viewer may see.
     *
     * @return array<string, mixed>
     */
    public function report(Model $me, ChatConversation $conversation): array
    {
        $mine = $this->assertWorkMember($me, $conversation);
        $this->need(GroupPermissions::ofParticipant($mine, $conversation), 'reports.view');

        $tasks = $this->visible($me, $conversation)->whereIn('status', [...ChatGroupTask::OPEN, 'done'])->get(['id', 'uuid', 'title', 'status', 'priority', 'due_at', 'completed_at', 'assignee_participant_id']);
        $weekAgo = now()->subDays(7);
        $isLate = fn (ChatGroupTask $t) => in_array($t->status, ChatGroupTask::OPEN, true) && $t->due_at !== null && $t->due_at->isPast();
        $doneWeek = fn (ChatGroupTask $t) => $t->status === 'done' && $t->completed_at !== null && $t->completed_at->gte($weekAgo);

        $people = ChatParticipant::query()->whereIn('id', $tasks->pluck('assignee_participant_id')->filter()->unique())->get()->keyBy('id');
        $directory = app(ParticipantDirectory::class);
        $directory->prime($me, $people->map(fn ($p) => [$p->participant_type, $p->participant_id]));

        return [
            'totals' => [
                'open' => $tasks->whereIn('status', ['new', 'in_progress'])->count(),
                'in_review' => $tasks->where('status', 'in_review')->count(),
                'late' => $tasks->filter($isLate)->count(),
                'done_week' => $tasks->filter($doneWeek)->count(),
            ],
            'members' => $tasks->whereNotNull('assignee_participant_id')->groupBy('assignee_participant_id')
                ->map(function ($rows, $id) use ($people, $directory, $me, $isLate, $doneWeek) {
                    $p = $people->get((int) $id);

                    return [
                        'participant_id' => (int) $id,
                        'profile' => $p ? $directory->profile($me, $p->participant_type, $p->participant_id) : null,
                        'open' => $rows->filter(fn ($t) => in_array($t->status, ChatGroupTask::OPEN, true))->count(),
                        'late' => $rows->filter($isLate)->count(),
                        'done_week' => $rows->filter($doneWeek)->count(),
                    ];
                })->sortByDesc('late')->values()->all(),
            'late' => $tasks->filter($isLate)->sortBy('due_at')->take(10)
                ->map(fn ($t) => ['id' => $t->uuid, 'title' => $t->title, 'priority' => $t->priority, 'due_at' => $t->due_at?->toIso8601String()])->values()->all(),
        ];
    }

    /**
     * A CSV (opens in Excel) of the tasks the viewer may see — `reports.view`, never wider.
     *
     * @return array{name: string, csv: string}
     */
    public function export(Model $me, ChatConversation $conversation): array
    {
        $mine = $this->assertWorkMember($me, $conversation);
        $this->need(GroupPermissions::ofParticipant($mine, $conversation), 'reports.view');

        $tasks = $this->visible($me, $conversation)->latest('id')->limit(2000)->with('space.conversation.group')->get();
        $people = ChatParticipant::query()->whereIn('id', $tasks->pluck('assignee_participant_id')->filter()->unique())->get()->keyBy('id');
        $directory = app(ParticipantDirectory::class);
        $directory->prime($me, $people->map(fn ($p) => [$p->participant_type, $p->participant_id]));

        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_map(fn ($k) => __('chat.task_export.'.$k), ['title', 'status', 'priority', 'assignee', 'project', 'due_at', 'completed_at', 'created_at']));
        foreach ($tasks as $t) {
            $p = $people->get($t->assignee_participant_id);
            fputcsv($out, [
                $t->title,
                __('chat.task_status.'.$t->status),
                __('chat.task_priority.'.$t->priority),
                $p ? ($directory->profile($me, $p->participant_type, $p->participant_id)['name'] ?? '') : '',
                $t->space?->conversation?->group?->name ?? '',
                $t->due_at?->format('Y-m-d H:i') ?? '',
                $t->completed_at?->format('Y-m-d H:i') ?? '',
                $t->created_at?->format('Y-m-d H:i') ?? '',
            ]);
        }
        rewind($out);
        $csv = "\u{FEFF}".stream_get_contents($out);
        fclose($out);
        ChatGroupAuditLog::record($conversation, $me, 'tasks_exported', null, null, ['rows' => $tasks->count()]);

        return ['name' => 'tasks-'.now()->format('Y-m-d').'.csv', 'csv' => $csv];
    }

    /** `chat:remind-group-tasks`: an open task due within the hour — its assignee hears, once. */
    public function remindDue(): int
    {
        $count = 0;
        ChatGroupTask::query()->whereIn('status', ['new', 'in_progress'])->whereNotNull('assignee_participant_id')
            ->whereNull('reminded_at')->whereBetween('due_at', [now(), now()->addHour()])->with(['assignee', 'conversation.group'])->limit(500)->get()
            ->each(function (ChatGroupTask $task) use (&$count) {
                $task->update(['reminded_at' => now()]);
                if ($task->conversation !== null && $task->assignee !== null) {
                    $this->notifier->notify($task->conversation, [$task->assignee], 'tasks', 'task_due', ['title' => $task->title], ['task_id' => $task->uuid]);
                    $count++;
                }
            });

        return $count;
    }

    // ------------------------------------------------------------------ rules

    /** The group's tasks the viewer may see: the group's own, and those of projects they're in. */
    private function visible(Model $me, ChatConversation $conversation)
    {
        return ChatGroupTask::query()->where('conversation_id', $conversation->id)
            ->where(fn ($q) => $q->whereNull('space_id')->orWhereIn('space_id', $this->mySpaceIds($me, $conversation)));
    }

    /**
     * A message of this group (or of a project I'm in) I can read, to make a task from (GR-038).
     */
    private function sourceMessage(Model $me, ChatConversation $conversation, mixed $uuid): ?ChatMessage
    {
        if (blank($uuid)) {
            return null;
        }
        $mineIn = ChatParticipant::query()->of($me)->whereNull('left_at')->pluck('conversation_id')->all();
        $allowed = array_intersect([$conversation->id, ...ChatGroupSpace::query()->where('parent_conversation_id', $conversation->id)->pluck('conversation_id')->all()], $mineIn);
        $message = ChatMessage::query()->where('uuid', (string) $uuid)->whereIn('conversation_id', $allowed)->first();

        if ($message === null || $message->isGone() || $message->sender_type === null) {
            throw new ChatException('message_not_found', 404);
        }

        return $message;
    }

    /**
     * The task's message, only for someone who may still read it; otherwise just "there was one".
     *
     * @return array<string, mixed>|null
     */
    private function presentSource(Model $me, ChatGroupTask $task): ?array
    {
        if ($task->source_message_id === null) {
            return null;
        }
        $message = $task->sourceMessage;
        $readable = $message !== null && ! $message->isGone()
            && ChatParticipant::query()->where('conversation_id', $message->conversation_id)->of($me)->whereNull('left_at')->exists();

        return $readable
            ? ['available' => true, 'message_id' => $message->uuid, 'conversation_id' => $message->conversation?->uuid, 'excerpt' => Str::limit((string) $message->body, 160)]
            : ['available' => false];
    }

    private function assertWorkMember(Model $me, ChatConversation $conversation): ChatParticipant
    {
        if (! GroupLifecycleService::isKindGroup($conversation) || $conversation->group->kind !== GroupKind::Work) {
            throw new ChatException('work_group_only', 422);
        }

        return $this->conversations->participantOf($me, $conversation, true);
    }

    /** A project's tasks are only for that project's members. */
    private function task(Model $me, ChatConversation $conversation, string $uuid): ChatGroupTask
    {
        $task = ChatGroupTask::query()->where('conversation_id', $conversation->id)->where('uuid', $uuid)->with(['items', 'media', 'space.conversation.group'])->first();

        if ($task === null || ($task->space_id !== null && ! in_array($task->space_id, $this->mySpaceIds($me, $conversation), true))) {
            throw new ChatException('task_not_found', 404);
        }

        return $task;
    }

    /** @return list<int> spaces of this group I'm in */
    private function mySpaceIds(Model $me, ChatConversation $conversation): array
    {
        $mineIn = ChatParticipant::query()->of($me)->whereNull('left_at')->select('conversation_id');

        return ChatGroupSpace::query()->where('parent_conversation_id', $conversation->id)->whereIn('conversation_id', $mineIn)->pluck('id')->all();
    }

    private function spaceFor(ChatConversation $conversation, mixed $spaceId, Model $me): ?ChatGroupSpace
    {
        if (blank($spaceId)) {
            return null;
        }
        if (! in_array((int) $spaceId, $this->mySpaceIds($me, $conversation), true)) {
            throw new ChatException('space_not_found', 404);
        }

        return ChatGroupSpace::query()->find((int) $spaceId);
    }

    /** A member of the group — and of the project, for a project's task. */
    private function assignee(ChatConversation $conversation, ?ChatGroupSpace $space, mixed $participantId): ?ChatParticipant
    {
        if (blank($participantId)) {
            return null;
        }
        $row = $conversation->activeParticipants()->find((int) $participantId) ?? throw new ChatException('not_a_member', 404);

        if ($space !== null && ! ChatParticipant::query()->where('conversation_id', $space->conversation_id)->whereNull('left_at')
            ->where('participant_type', $row->participant_type)->where('participant_id', $row->participant_id)->exists()) {
            throw new ChatException('task_assignee_not_in_project', 422);
        }

        return $row;
    }

    /** @param array<string, mixed> $data */
    private function assertRepeat(array $data): void
    {
        if (! empty($data['repeat']) && empty($data['due_at'])) {
            throw new ChatException('task_repeat_needs_due', 422);
        }
    }

    /** @param list<string> $perms */
    private function need(array $perms, string $permission): void
    {
        if (! in_array($permission, $perms, true)) {
            throw new ChatException('group_permission_denied', 403);
        }
    }

    /** @param list<string> $perms */
    private function isEditor(ChatGroupTask $task, ChatParticipant $mine, array $perms): bool
    {
        return $task->created_by_participant_id === $mine->id || in_array('tasks.assign', $perms, true);
    }

    /** @param list<string> $perms */
    private function isWorker(ChatGroupTask $task, ChatParticipant $mine, array $perms): bool
    {
        return ($task->assignee_participant_id === $mine->id && in_array('tasks.work', $perms, true))
            || in_array('tasks.assign', $perms, true) || ($task->assignee_participant_id === null && $task->created_by_participant_id === $mine->id);
    }

    /** @param list<string> $perms */
    private function assertEditor(ChatGroupTask $task, ChatParticipant $mine, array $perms): void
    {
        if (! $this->isEditor($task, $mine, $perms)) {
            throw new ChatException('group_permission_denied', 403);
        }
    }

    /** @param list<string> $perms */
    private function assertWorker(ChatGroupTask $task, ChatParticipant $mine, array $perms): void
    {
        if (! $this->isWorker($task, $mine, $perms)) {
            throw new ChatException('group_permission_denied', 403);
        }
    }

    /**
     * Who can approve here: the owner, and whoever holds a role with `tasks.approve`.
     *
     * @return \Illuminate\Support\Collection<int, ChatParticipant>
     */
    private function approvers(ChatConversation $conversation)
    {
        $roles = ChatGroupRole::query()->where('conversation_id', $conversation->id)->get()
            ->filter(fn (ChatGroupRole $r) => in_array('tasks.approve', (array) $r->permissions, true))->pluck('id');
        $holders = ChatGroupMemberRole::query()->whereIn('role_id', $roles)->pluck('participant_id');

        return $conversation->activeParticipants()
            ->where(fn ($q) => $q->where('role', ParticipantRole::Owner->value)->orWhereIn('id', $holders))->get();
    }

    private function nextDue(Carbon $due, string $repeat): Carbon
    {
        return match ($repeat) {
            'daily' => $due->copy()->addDay(),
            'weekly' => $due->copy()->addWeek(),
            default => $due->copy()->addMonthNoOverflow(),
        };
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function json(array $values): array
    {
        return array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format(DATE_ATOM) : $v, $values);
    }

    private function changed(ChatConversation $conversation, ChatGroupTask $task): void
    {
        $this->broadcaster->toParticipants($conversation->activeParticipants()->get(), 'chat.group.task', ['conversation_id' => $conversation->uuid, 'task_id' => $task->uuid]);
    }
}
