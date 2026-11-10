<?php

namespace Modules\Freelance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelanceWorkroomTask as Task;
use Modules\Freelance\Services\Workroom\WorkroomAccess;
use Modules\Freelance\Services\Workroom\WorkroomContext;
use Modules\Freelance\Services\Workroom\WorkroomTasks;

/**
 * The Workroom's tasks (FS-150a), one controller for both prefixes (the client's `mobile/v1/freelance/projects/{uuid}/workroom/tasks` and the
 * freelancer's `freelance/v1/projects/{uuid}/workroom/tasks`): the two sides of the agreement read them, add them, change the ones they wrote,
 * move the status of the ones that are theirs, and delete what they wrote. A member of the client's team reads them (`view`) and, as a manager,
 * works on them (`workroom`) - see ClientTeamAbilities.
 */
class WorkroomTaskController extends Controller
{
    public function __construct(
        private readonly WorkroomAccess $access,
        private readonly WorkroomTasks $tasks,
    ) {}

    /** GET .../workroom/tasks[?status=&milestone=&assignee=client|freelancer&mine=1&overdue=1&per_page=&page=] */
    public function index(Request $request, string $uuid): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(Task::STATUSES)],
            'milestone' => ['nullable', 'uuid'],
            'assignee' => ['nullable', Rule::in(Task::SIDES)],
            'mine' => ['nullable', 'boolean'],
            'overdue' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $c = $this->access->context($request, $uuid);
        $page = $this->tasks->list($c, $f, (int) ($f['per_page'] ?? 50));

        return ApiResponse::success([
            'tasks' => $page->getCollection()->map(fn (Task $t) => $this->tasks->present($t, $c))->values(),
            'summary' => $this->tasks->summary($c),
            'limit' => ['max' => $this->tasks->max($c)],
            'read_only' => ! $c->writable(),
            'can' => ['task_create' => $c->writable()],
        ], __('api.retrieved'), 200, ApiPaginator::meta($page));
    }

    /** POST .../workroom/tasks {title, description?, milestone?, due_on?, assignee_side?} */
    public function store(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:'.WorkroomTasks::TITLE_MAX],
            'description' => ['nullable', 'string', 'max:'.WorkroomTasks::DESCRIPTION_MAX],
            'milestone' => ['nullable', 'uuid'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'assignee_side' => ['nullable', Rule::in(Task::SIDES)],
            'assignee' => ['nullable', 'uuid'],
            'scope' => ['nullable', Rule::in([Task::SHARED, Task::TEAM])],
        ]);
        $c = $this->access->context($request, $uuid);
        $task = $this->tasks->create($c, $data);

        return ApiResponse::created($this->answer($c, $task), __('freelance.workroom_task_created'));
    }

    /** PUT .../workroom/tasks/{task} - any of title, description, milestone, due_on, assignee_side (the side that wrote the task only). */
    public function update(Request $request, string $uuid, string $task): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:'.WorkroomTasks::TITLE_MAX],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.WorkroomTasks::DESCRIPTION_MAX],
            'milestone' => ['sometimes', 'nullable', 'uuid'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'assignee_side' => ['sometimes', Rule::in(Task::SIDES)],
            'assignee' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $c = $this->access->context($request, $uuid);
        $updated = $this->tasks->update($c, $this->tasks->find($c, $task), $data);

        return ApiResponse::success($this->answer($c, $updated), __('freelance.workroom_task_updated'));
    }

    /** POST .../workroom/tasks/{task}/status {status} - the side it is for, or the side that wrote it. */
    public function status(Request $request, string $uuid, string $task): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(Task::STATUSES)]]);
        $c = $this->access->context($request, $uuid);
        $updated = $this->tasks->setStatus($c, $this->tasks->find($c, $task), $data['status']);

        return ApiResponse::success($this->answer($c, $updated), __('freelance.workroom_task_updated'));
    }

    /** DELETE .../workroom/tasks/{task} - the side that wrote it. */
    public function destroy(Request $request, string $uuid, string $task): JsonResponse
    {
        $c = $this->access->context($request, $uuid);
        $this->tasks->delete($c, $this->tasks->find($c, $task));

        return ApiResponse::success(['summary' => $this->tasks->summary($c)], __('freelance.workroom_task_deleted'));
    }

    /** @return array<string, mixed> */
    private function answer(WorkroomContext $c, Task $task): array
    {
        return ['task' => $this->tasks->present($task, $c), 'summary' => $this->tasks->summary($c)];
    }
}
