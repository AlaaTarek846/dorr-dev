<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\User\Models\SupportHelpFeedback;
use Modules\User\Models\SupportHelpNode;

/**
 * Dashboard → Support → Help menu: the tree of topics the app shows before a customer opens a ticket.
 * The list is one level at a time (`parent_id`, none = the main menu) with the path to it, and how often
 * customers said "solved" or "I need an agent" at the end of each topic (a menu adds up its sub-topics).
 */
class SupportHelpNodeController extends Controller implements HasMiddleware
{
    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('support-help-nodes', [
            ['view', ['index', 'show', 'options']],
            ['create', ['store']],
            ['update', ['update']],
            ['change-status', ['status']],
            ['delete', ['destroy']],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $parentId = $request->filled('parent_id') ? (int) $request->query('parent_id') : null;
        $status = (string) $request->query('status', 'all');
        $search = trim((string) $request->query('search', ''));
        $outcomes = $this->outcomes();

        $rows = SupportHelpNode::query()->with('translations')->withCount('children')
            ->where('parent_id', $parentId)
            ->when(in_array($status, ['active', 'inactive'], true), fn ($query) => $query->where('status', $status === 'active'))
            ->when($search !== '', fn ($query) => $query->whereHas('translations', fn ($t) => $t->where('title', 'like', "%{$search}%")->orWhere('answer', 'like', "%{$search}%")))
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->map(fn (SupportHelpNode $node) => $this->present($node, $outcomes[$node->id] ?? null));

        $siblings = SupportHelpNode::query()->where('parent_id', $parentId);
        $total = (clone $siblings)->count();
        $active = (clone $siblings)->where('status', true)->count();
        $solved = SupportHelpFeedback::query()->where('solved', true)->count();
        $agent = SupportHelpFeedback::query()->where('solved', false)->count();

        return ApiResponse::success($rows, __('api.retrieved'), 200, null, [
            'path' => $this->path($parentId),
            'status_counts' => ['total' => $total, 'active' => $active, 'inactive' => $total - $active],
            'summary' => [
                'solved' => $solved,
                'agent' => $agent,
                'solved_rate' => $solved + $agent > 0 ? (int) round($solved * 100 / ($solved + $agent)) : null,
            ],
        ]);
    }

    /**
     * Where a topic can sit, as a tree for the dashboard's tree select: the topic being moved and its own
     * sub-topics are left out (`?exclude={id}`), and a place that would push its branch below the depth limit is
     * shown but not selectable.
     */
    public function options(Request $request): JsonResponse
    {
        $nodes = SupportHelpNode::query()->with('translations')->orderBy('sort_order')->orderBy('id')->get();
        $byParent = $nodes->groupBy('parent_id');
        $moving = $request->filled('exclude') ? $nodes->firstWhere('id', (int) $request->query('exclude')) : null;
        $height = $moving ? $this->height($byParent, $moving->id) : 1;

        $branch = function (?int $parentId, int $depth) use (&$branch, $byParent, $moving, $height): array {
            $items = [];

            foreach ($byParent->get($parentId) ?? [] as $node) {
                if ($moving && $node->id === $moving->id) {
                    continue; // its own sub-topics go with it
                }

                $items[] = [
                    'key' => (string) $node->id,
                    'label' => $node->translated('title') ?: "#{$node->id}",
                    'selectable' => $depth + $height <= SupportHelpNode::MAX_DEPTH,
                    'children' => $branch($node->id, $depth + 1),
                ];
            }

            return $items;
        };

        return ApiResponse::success($branch(null, 1), __('api.retrieved'));
    }

    public function show(SupportHelpNode $supportHelpNode): JsonResponse
    {
        return ApiResponse::success($this->present($supportHelpNode->load('translations')->loadCount('children'), $this->outcomes()[$supportHelpNode->id] ?? null), __('api.retrieved'));
    }

    public function store(Request $request): JsonResponse
    {
        $node = $this->save(new SupportHelpNode, $this->validated($request));

        return ApiResponse::created($this->present($node), __('api.created'));
    }

    public function update(Request $request, SupportHelpNode $supportHelpNode): JsonResponse
    {
        return ApiResponse::success($this->present($this->save($supportHelpNode, $this->validated($request, $supportHelpNode))), __('api.updated'));
    }

    public function status(Request $request, SupportHelpNode $supportHelpNode): JsonResponse
    {
        $supportHelpNode->update($request->validate(['status' => ['required', 'boolean']]));

        return ApiResponse::success($this->present($supportHelpNode->load('translations')->loadCount('children')), __('api.updated'));
    }

    /** Its sub-topics go with it (cascade); the answers customers gave stay, without a topic. */
    public function destroy(SupportHelpNode $supportHelpNode): JsonResponse
    {
        $supportHelpNode->delete();

        return ApiResponse::noContent(__('api.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?SupportHelpNode $current = null): array
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:support_help_nodes,id'],
            'translations' => ['required', 'array', 'min:1'],
            'translations.*.locale' => ['required', 'string', 'max:10'],
            'translations.*.title' => ['required', 'string', 'max:150'],
            'translations.*.answer' => ['nullable', 'string', 'max:4000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'status' => ['sometimes', 'boolean'],
        ]);

        // A topic goes under a new parent only if that parent is not the topic itself or one of its own
        // sub-topics, and the topic's whole branch still fits within the depth limit.
        if (array_key_exists('parent_id', $data) && $data['parent_id'] !== null) {
            $parent = SupportHelpNode::query()->findOrFail($data['parent_id']);

            if ($current) {
                for ($node = $parent; $node; $node = $node->parent_id ? SupportHelpNode::query()->find($node->parent_id) : null) {
                    if ($node->id === $current->id) {
                        throw ValidationException::withMessages(['parent_id' => __('validation.in', ['attribute' => 'parent_id'])]);
                    }
                }
            }

            $byParent = SupportHelpNode::query()->get(['id', 'parent_id'])->groupBy('parent_id');
            $branch = $current ? $this->height($byParent, $current->id) : 1;

            if ($parent->depth() + $branch > SupportHelpNode::MAX_DEPTH) {
                throw ValidationException::withMessages(['parent_id' => __('validation.max.numeric', ['attribute' => 'depth', 'max' => SupportHelpNode::MAX_DEPTH])]);
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(SupportHelpNode $node, array $data): SupportHelpNode
    {
        DB::transaction(function () use ($node, $data) {
            $node->fill(collect($data)->except('translations')->all())->save();

            foreach ($data['translations'] as $row) {
                $node->translations()->updateOrCreate(
                    ['locale' => $row['locale']],
                    ['title' => trim($row['title']), 'answer' => filled($row['answer'] ?? null) ? trim($row['answer']) : null],
                );
            }
        });

        return $node->load('translations')->loadCount('children');
    }

    /**
     * How many levels the branch starting at this topic has (itself = 1).
     *
     * @param  Collection<int|string, Collection<int, SupportHelpNode>>  $byParent
     */
    private function height(Collection $byParent, int $id): int
    {
        $deepest = 0;

        foreach ($byParent->get($id) ?? [] as $child) {
            $deepest = max($deepest, $this->height($byParent, $child->id));
        }

        return 1 + $deepest;
    }

    /**
     * "Solved" and "needed an agent" per topic, each topic adding up its sub-topics.
     *
     * @return array<int, array{solved: int, agent: int}>
     */
    private function outcomes(): array
    {
        $parents = SupportHelpNode::query()->pluck('parent_id', 'id');
        $totals = [];

        SupportHelpFeedback::query()
            ->selectRaw('support_help_node_id, solved, count(*) as total')
            ->whereNotNull('support_help_node_id')
            ->groupBy('support_help_node_id', 'solved')
            ->get()
            ->each(function ($row) use (&$totals, $parents) {
                for ($id = $row->support_help_node_id; $id; $id = $parents[$id] ?? null) {
                    $totals[$id] ??= ['solved' => 0, 'agent' => 0];
                    $totals[$id][$row->solved ? 'solved' : 'agent'] += (int) $row->total;
                }
            });

        return $totals;
    }

    /**
     * The topics from the main menu down to this one, for the breadcrumb of the list.
     *
     * @return list<array{id: int, title: string|null}>
     */
    private function path(?int $parentId): array
    {
        $path = [];
        $node = $parentId ? SupportHelpNode::query()->with('translations')->find($parentId) : null;

        while ($node) {
            array_unshift($path, ['id' => $node->id, 'title' => $node->translated('title')]);
            $node = $node->parent_id ? SupportHelpNode::query()->with('translations')->find($node->parent_id) : null;
        }

        return $path;
    }

    /**
     * @param  array{solved: int, agent: int}|null  $outcome
     * @return array<string, mixed>
     */
    private function present(SupportHelpNode $node, ?array $outcome = null): array
    {
        $solved = $outcome['solved'] ?? 0;
        $agent = $outcome['agent'] ?? 0;

        return [
            'id' => $node->id,
            'parent_id' => $node->parent_id,
            'title' => $node->translated('title'),
            'answer' => $node->translated('answer'),
            'sort_order' => $node->sort_order,
            'status' => $node->status,
            'children_count' => (int) ($node->children_count ?? 0),
            'solved_count' => $solved,
            'agent_count' => $agent,
            'created_at' => $node->created_at,
            'updated_at' => $node->updated_at,
            'translations' => $node->translations->map(fn ($t) => ['locale' => $t->locale, 'title' => $t->title, 'answer' => $t->answer])->values(),
        ];
    }
}
