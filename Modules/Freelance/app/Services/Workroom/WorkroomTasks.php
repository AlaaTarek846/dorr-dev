<?php

namespace Modules\Freelance\Services\Workroom;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceWorkroomTask as Task;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\FreelancerTeam\TeamAssignments;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\Freelance\Services\Policy\FreelancePolicies;

/**
 * The Workroom's tasks (FS-150a). A task belongs to an active agreement, optionally to one of its parts, and is the responsibility of one
 * side - the client's or the freelancer's - with a day and a status. Either side may add a task for either side; the side that wrote a task
 * changes and deletes it, and the side it is for (or the writer) moves its status. After the agreement ends the tasks are only read.
 * Every step is written on the project's record (`workroom.task.*`), tells the live screens of the project once, and a task given to the
 * other side reaches that side as a notice. The most tasks a project may hold is the admin's policy `workroom.tasks_max` (none at first).
 */
class WorkroomTasks
{
    public const TITLE_MAX = 160;

    public const DESCRIPTION_MAX = 2000;

    public function __construct(
        private readonly FreelanceNotifier $notifier,
        private readonly FreelancePolicies $policies,
        private readonly TeamAssignments $team,
    ) {}

    // -------------------------------------------------------------- reading

    /**
     * @param  array{status?: ?string, milestone?: ?string, assignee?: ?string, mine?: ?bool, overdue?: ?bool}  $filters
     */
    public function list(WorkroomContext $c, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = WorkroomSupport::visible(Task::query()->where('project_id', $c->project->id), $c)->with(['milestone:id,uuid,title,position', 'assigneeFreelancer:id,uuid,name']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['milestone'])) {
            $query->whereHas('milestone', fn ($q) => $q->where('uuid', $filters['milestone']));
        }
        if (! empty($filters['assignee'])) {
            $query->where('assignee_side', $filters['assignee']);
        }
        if (! empty($filters['mine'])) {
            $query->where('assignee_side', $c->side);
        }
        if (! empty($filters['overdue'])) {
            $query->where('status', '!=', Task::DONE)->whereDate('due_on', '<', now()->toDateString());
        }

        // What is being worked on first, then what waits, then blocked, then done; inside each the nearest day first.
        return $query->orderByRaw("case status when 'doing' then 0 when 'todo' then 1 when 'blocked' then 2 else 3 end")
            ->orderByRaw('due_on is null')->orderBy('due_on')->orderBy('id')
            ->paginate($perPage);
    }

    /** @return array{total: int, todo: int, doing: int, done: int, blocked: int, overdue: int} */
    public function summary(WorkroomContext $c): array
    {
        $counts = WorkroomSupport::visible(Task::query()->where('project_id', $c->project->id), $c)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $overdue = WorkroomSupport::visible(Task::query()->where('project_id', $c->project->id), $c)->where('status', '!=', Task::DONE)->whereDate('due_on', '<', now()->toDateString())->count();

        return [
            'total' => (int) $counts->sum(),
            'todo' => (int) ($counts[Task::TODO] ?? 0),
            'doing' => (int) ($counts[Task::DOING] ?? 0),
            'done' => (int) ($counts[Task::DONE] ?? 0),
            'blocked' => (int) ($counts[Task::BLOCKED] ?? 0),
            'overdue' => $overdue,
        ];
    }

    /** The most tasks one project may hold now (null = no limit). */
    public function max(WorkroomContext $c): ?int
    {
        $max = $this->policies->for((int) $c->project->country_id, $c->project->freelance_specialty_id)->get('workroom.tasks_max');

        return $max === null ? null : (int) $max;
    }

    public function find(WorkroomContext $c, string $uuid): Task
    {
        return WorkroomSupport::visible(Task::query()->where('project_id', $c->project->id), $c)->where('uuid', $uuid)->with(['milestone:id,uuid,title,position', 'assigneeFreelancer:id,uuid,name'])->firstOrFail();
    }

    // -------------------------------------------------------------- writing

    /** @param  array{title: string, description?: ?string, milestone?: ?string, due_on?: ?string, assignee_side?: ?string, assignee?: ?string}  $data */
    public function create(WorkroomContext $c, array $data): Task
    {
        $scope = WorkroomSupport::scope($c, $data['scope'] ?? null);
        $person = $this->person($c, $data);
        $task = DB::transaction(function () use ($c, $data, $person, $scope): Task {
            WorkroomSupport::lockActive($c);
            $max = $this->max($c);
            if ($max !== null && Task::query()->where('project_id', $c->project->id)->count() >= $max) {
                throw new FreelanceException('workroom_tasks_limit', 422, ['max' => $max], ['max' => $max]);
            }
            $due = $this->due($data['due_on'] ?? null, null);

            $task = Task::query()->create([
                'project_id' => $c->project->id,
                'agreement_id' => $c->agreement->id,
                'milestone_id' => WorkroomSupport::milestone($c, $data['milestone'] ?? null)?->id,
                'title' => $this->title($data['title']),
                'description' => $this->text($data['description'] ?? null),
                'status' => Task::TODO,
                'due_on' => $due,
                'scope' => $scope,
                'assignee_side' => $person === null && $scope === Task::SHARED ? ($data['assignee_side'] ?? $c->side) : Task::FREELANCER,
                'assignee_freelancer_id' => $person?->id,
                'created_by_side' => $c->side,
                'created_by_id' => (int) $c->actor->getKey(),
            ]);
            $this->record($c, 'workroom.task.created', $task, ['assignee_side' => $task->assignee_side]);

            return $task;
        });

        $this->after($c, $task, 'created', assignedTo: $task->assignee_side);
        if ($person !== null) {
            $this->team->taskGiven($c->agreement, $task, $person, $c->actor instanceof Freelancer ? $c->actor : $person);
        }

        return $task->load(['milestone:id,uuid,title,position', 'assigneeFreelancer:id,uuid,name']);
    }

    /** @param  array<string, mixed>  $data  only the keys present are changed (`milestone` / `due_on` / `description` may be null) */
    public function update(WorkroomContext $c, Task $task, array $data): Task
    {
        $reassigned = null;
        $person = array_key_exists('assignee', $data) ? $this->person($c, $data) : null;
        $given = null;
        $task = DB::transaction(function () use ($c, $task, $data, $person, &$reassigned, &$given): Task {
            WorkroomSupport::lockActive($c);
            $task = Task::query()->where('project_id', $c->project->id)->lockForUpdate()->findOrFail($task->id);
            $this->assertWriter($c, $task);

            $fill = [];
            if (array_key_exists('title', $data)) {
                $fill['title'] = $this->title((string) $data['title']);
            }
            if (array_key_exists('description', $data)) {
                $fill['description'] = $this->text($data['description']);
            }
            if (array_key_exists('milestone', $data)) {
                $fill['milestone_id'] = WorkroomSupport::milestone($c, $data['milestone'])?->id;
            }
            if (array_key_exists('due_on', $data)) {
                $fill['due_on'] = $this->due($data['due_on'], $task->due_on?->toDateString());
            }
            if (array_key_exists('assignee_side', $data) && $data['assignee_side'] !== $task->assignee_side && ! ($task->scope === Task::TEAM && $data['assignee_side'] !== Task::FREELANCER)) {
                $fill['assignee_side'] = $data['assignee_side'];
                $reassigned = $data['assignee_side'];
            }
            if (array_key_exists('assignee', $data)) {
                // A person of the team means the freelancer side; giving the task to nobody lets the person go.
                if ($person !== null) {
                    $fill['assignee_side'] = Task::FREELANCER;
                    $fill['assignee_freelancer_id'] = $person->id;
                    $given = (int) $task->assignee_freelancer_id !== (int) $person->id ? $person : null;
                    $reassigned ??= $task->assignee_side !== Task::FREELANCER ? Task::FREELANCER : null;
                } else {
                    $fill['assignee_freelancer_id'] = null;
                }
            } elseif (($fill['assignee_side'] ?? $task->assignee_side) !== Task::FREELANCER) {
                $fill['assignee_freelancer_id'] = null;
            }
            $task->fill($fill)->save();
            $this->record($c, 'workroom.task.updated', $task, ['changed' => array_keys($fill)]);

            return $task;
        });

        $this->after($c, $task, 'updated', assignedTo: $reassigned);
        if ($given !== null) {
            $this->team->taskGiven($c->agreement, $task, $given, $c->actor instanceof Freelancer ? $c->actor : $given);
        }

        return $task->load(['milestone:id,uuid,title,position', 'assigneeFreelancer:id,uuid,name']);
    }

    public function setStatus(WorkroomContext $c, Task $task, string $status): Task
    {
        $from = $task->status;
        $task = DB::transaction(function () use ($c, $task, $status, &$from): Task {
            WorkroomSupport::lockActive($c);
            $task = Task::query()->where('project_id', $c->project->id)->lockForUpdate()->findOrFail($task->id);
            if ($task->assignee_side !== $c->side && $task->created_by_side !== $c->side) {
                throw new FreelanceException('workroom_forbidden', 403);
            }
            $from = $task->status;
            if ($from !== $status) {
                $task->forceFill(['status' => $status, 'completed_at' => $status === Task::DONE ? now() : null])->save();
                $this->record($c, $status === Task::DONE ? 'workroom.task.completed' : 'workroom.task.status', $task, ['from' => $from, 'to' => $status]);
            }

            return $task;
        });

        if ($from !== $status) {
            $this->after($c, $task, 'status');
        }

        return $task->load(['milestone:id,uuid,title,position', 'assigneeFreelancer:id,uuid,name']);
    }

    public function delete(WorkroomContext $c, Task $task): void
    {
        $task = DB::transaction(function () use ($c, $task): Task {
            WorkroomSupport::lockActive($c);
            $task = Task::query()->where('project_id', $c->project->id)->lockForUpdate()->findOrFail($task->id);
            $this->assertWriter($c, $task);
            $task->delete();
            $this->record($c, 'workroom.task.deleted', $task, []);

            return $task;
        });

        $this->after($c, $task, 'deleted');
    }

    // -------------------------------------------------------------- shape

    /** @return array<string, mixed> */
    public function present(Task $t, WorkroomContext $c): array
    {
        $open = $c->writable();
        $writer = $open && $t->created_by_side === $c->side;

        return [
            'uuid' => $t->uuid,
            'title' => $t->title,
            'description' => $t->description,
            'status' => $t->status,
            'due_on' => $t->due_on?->toDateString(),
            'overdue' => $t->status !== Task::DONE && $t->due_on !== null && $t->due_on->lt(now()->startOfDay()),
            'milestone' => $t->milestone === null ? null : ['uuid' => $t->milestone->uuid, 'title' => $t->milestone->title, 'position' => (int) $t->milestone->position],
            'assignee_side' => $t->assignee_side,
            'scope' => $t->scope,
            // FS-206b: the person of the freelancers team it is given to - the client reads the first name only (D10).
            'assignee' => $t->assigneeFreelancer === null ? null : [
                'uuid' => $t->assigneeFreelancer->uuid,
                'name' => $c->side === Task::CLIENT ? (strtok((string) $t->assigneeFreelancer->name, ' ') ?: (string) $t->assigneeFreelancer->name) : $t->assigneeFreelancer->name,
            ],
            'created_by_side' => $t->created_by_side,
            'mine' => $t->assignee_side === $c->side,
            'completed_at' => $t->completed_at?->toIso8601String(),
            'created_at' => $t->created_at?->toIso8601String(),
            'updated_at' => $t->updated_at?->toIso8601String(),
            'can' => [
                'task_edit' => $writer,
                'task_delete' => $writer,
                'task_status' => $open && ($t->assignee_side === $c->side || $t->created_by_side === $c->side),
            ],
        ];
    }

    // -------------------------------------------------------------- inside

    /**
     * FS-206b: the person of the team a task is given to (`assignee` = a freelancer uuid; null = nobody), checked before anything is saved.
     * Only the freelancer side gives a task to a person of his team: 422 workroom_assignee_invalid otherwise.
     *
     * @param  array<string, mixed>  $data
     */
    private function person(WorkroomContext $c, array $data): ?Freelancer
    {
        $uuid = $data['assignee'] ?? null;
        if ($uuid === null || $uuid === '') {
            return null;
        }
        if ($c->side !== Task::FREELANCER || ! $c->actor instanceof Freelancer) {
            throw new FreelanceException('workroom_assignee_invalid', 422);
        }

        return $this->team->taskAssignee($c->agreement, (string) $uuid, $c->actor);
    }

    private function assertWriter(WorkroomContext $c, Task $task): void
    {
        if ($task->created_by_side !== $c->side) {
            throw new FreelanceException('workroom_forbidden', 403);
        }
    }

    private function title(string $title): string
    {
        $text = trim(strip_tags($title));
        if (mb_strlen($text) < 3) {
            throw new FreelanceException('workroom_title_required', 422);
        }

        return mb_substr($text, 0, self::TITLE_MAX);
    }

    private function text(?string $text): ?string
    {
        $text = $text === null ? '' : trim(strip_tags($text));

        return $text === '' ? null : mb_substr($text, 0, self::DESCRIPTION_MAX);
    }

    /** A day today or later; the day a task already has may stay as it is. */
    private function due(?string $day, ?string $current): ?string
    {
        if ($day === null || $day === '') {
            return null;
        }
        if ($day !== $current && $day < now()->toDateString()) {
            throw new FreelanceException('workroom_due_past', 422);
        }

        return $day;
    }

    /** @param  array<string, mixed>  $extra */
    private function record(WorkroomContext $c, string $type, Task $task, array $extra): void
    {
        WorkroomSupport::record($c, $type, ['task' => $task->uuid, 'title' => $task->title] + $extra, $task->scope);
    }

    /** After the step is saved: tell the other side of a task given to it, and the live screens of the project. */
    private function after(WorkroomContext $c, Task $task, string $action, ?string $assignedTo = null): void
    {
        if ($assignedTo !== null && $assignedTo !== $c->side) {
            $c->agreement->loadMissing(['client', 'freelancer']);
            $receiver = $assignedTo === Task::CLIENT ? $c->agreement->client : $c->agreement->freelancer;
            if ($receiver !== null) {
                $this->notifier->notify($receiver, 'workroom.task_assigned', ['project' => (string) $c->project->title, 'title' => $task->title], ['project' => $c->project->uuid, 'task' => $task->uuid]);
            }
        }
        WorkroomSupport::changed($c, 'task', $action, $task->uuid, $task->scope);
    }
}
