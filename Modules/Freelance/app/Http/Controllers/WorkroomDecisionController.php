<?php

namespace Modules\Freelance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelanceWorkroomDecision as Decision;
use Modules\Freelance\Services\Workroom\WorkroomAccess;
use Modules\Freelance\Services\Workroom\WorkroomContext;
use Modules\Freelance\Services\Workroom\WorkroomDecisions;

/**
 * The Workroom's decision log (FS-150c), one controller for both prefixes (`mobile/v1/freelance/projects/{uuid}/workroom/decisions` and
 * `freelance/v1/projects/{uuid}/workroom/decisions`): a side proposes, the other side agrees or declines, the writer changes or withdraws a
 * proposal that still waits, and an agreed decision is replaced only by a new one that supersedes it. A member of the client's team reads
 * (`view`) and, as a manager, works on them (`workroom`) - see ClientTeamAbilities.
 */
class WorkroomDecisionController extends Controller
{
    public function __construct(
        private readonly WorkroomAccess $access,
        private readonly WorkroomDecisions $decisions,
    ) {}

    /** GET .../workroom/decisions[?status=&milestone=&mine=1&waiting=1&per_page=&page=] - what waits first. */
    public function index(Request $request, string $uuid): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(Decision::STATUSES)],
            'milestone' => ['nullable', 'uuid'],
            'mine' => ['nullable', 'boolean'],
            'waiting' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $c = $this->access->context($request, $uuid);
        $page = $this->decisions->list($c, $f, (int) ($f['per_page'] ?? 30));

        return ApiResponse::success([
            'decisions' => $page->getCollection()->map(fn (Decision $d) => $this->decisions->present($d, $c))->values(),
            'summary' => $this->decisions->summary($c),
            'read_only' => ! $c->writable(),
            'can' => ['decision_create' => $c->writable()],
        ], __('api.retrieved'), 200, ApiPaginator::meta($page));
    }

    /** GET .../workroom/decisions/{decision} */
    public function show(Request $request, string $uuid, string $decision): JsonResponse
    {
        $c = $this->access->context($request, $uuid);

        return ApiResponse::success(['decision' => $this->decisions->present($this->decisions->find($c, $decision), $c)], __('api.retrieved'));
    }

    /** POST .../workroom/decisions {title, body, milestone?, supersedes?} - the proposer has agreed by proposing; the other side answers. */
    public function store(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:'.WorkroomDecisions::TITLE_MAX],
            'body' => ['required', 'string', 'max:'.WorkroomDecisions::BODY_MAX],
            'milestone' => ['nullable', 'uuid'],
            'supersedes' => ['nullable', 'uuid'],
        ]);
        $c = $this->access->context($request, $uuid);

        return ApiResponse::created($this->answer($c, $this->decisions->propose($c, $data)), __('freelance.workroom_decision_created'));
    }

    /** PUT .../workroom/decisions/{decision} - any of title, body, milestone; its writer, while it waits for an answer. */
    public function update(Request $request, string $uuid, string $decision): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:'.WorkroomDecisions::TITLE_MAX],
            'body' => ['sometimes', 'required', 'string', 'max:'.WorkroomDecisions::BODY_MAX],
            'milestone' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $c = $this->access->context($request, $uuid);

        return ApiResponse::success($this->answer($c, $this->decisions->update($c, $this->decisions->find($c, $decision), $data)), __('freelance.workroom_decision_updated'));
    }

    /** POST .../workroom/decisions/{decision}/agree {note?} - the side that did not propose it. */
    public function agree(Request $request, string $uuid, string $decision): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:'.WorkroomDecisions::NOTE_MAX]]);
        $c = $this->access->context($request, $uuid);

        return ApiResponse::success($this->answer($c, $this->decisions->agree($c, $this->decisions->find($c, $decision), $data['note'] ?? null)), __('freelance.workroom_decision_agreed'));
    }

    /** POST .../workroom/decisions/{decision}/decline {note?} - the side that did not propose it. */
    public function decline(Request $request, string $uuid, string $decision): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:'.WorkroomDecisions::NOTE_MAX]]);
        $c = $this->access->context($request, $uuid);

        return ApiResponse::success($this->answer($c, $this->decisions->decline($c, $this->decisions->find($c, $decision), $data['note'] ?? null)), __('freelance.workroom_decision_declined'));
    }

    /** DELETE .../workroom/decisions/{decision} - withdraws a proposal (its writer, while it waits). */
    public function destroy(Request $request, string $uuid, string $decision): JsonResponse
    {
        $c = $this->access->context($request, $uuid);
        $this->decisions->withdraw($c, $this->decisions->find($c, $decision));

        return ApiResponse::success(['summary' => $this->decisions->summary($c)], __('freelance.workroom_decision_deleted'));
    }

    /** @return array<string, mixed> */
    private function answer(WorkroomContext $c, Decision $decision): array
    {
        return ['decision' => $this->decisions->present($decision, $c), 'summary' => $this->decisions->summary($c)];
    }
}
