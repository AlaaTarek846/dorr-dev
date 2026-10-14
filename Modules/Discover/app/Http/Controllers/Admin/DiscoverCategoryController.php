<?php

namespace Modules\Discover\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Discover\Models\DiscoverCategory;

/** The kinds of events (concerts, exhibitions…), each with an emoji, a colour and a name per language. */
class DiscoverCategoryController extends Controller implements HasMiddleware
{
    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('discover-categories', [
            ['view', ['index', 'show']],
            ['create', ['store']],
            ['update', ['update', 'status', 'reorder']],
            ['delete', ['destroy']],
            ['multiple-delete', ['deleteMultiple']],
        ]);
    }

    public function index()
    {
        $rows = DiscoverCategory::query()->with('translations')->withCount('events')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (DiscoverCategory $c) => $this->present($c));

        return ApiResponse::success($rows, __('api.retrieved'));
    }

    public function show(DiscoverCategory $discoverCategory)
    {
        return ApiResponse::success($this->present($discoverCategory->load('translations')->loadCount('events')), __('api.retrieved'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);
        $data['sort_order'] ??= (int) DiscoverCategory::query()->max('sort_order') + 1;

        return ApiResponse::created($this->present($this->save(new DiscoverCategory, $data)), __('api.created'));
    }

    public function update(Request $request, DiscoverCategory $discoverCategory)
    {
        return ApiResponse::success($this->present($this->save($discoverCategory, $this->validated($request, $discoverCategory))), __('api.updated'));
    }

    /** PUT discover-categories/reorder — the ids in the new order become sort_order 1..n (the admin's drag-and-drop). */
    public function reorder(Request $request)
    {
        $data = $request->validate(['ordered_ids' => ['required', 'array', 'min:1'], 'ordered_ids.*' => ['integer', 'exists:discover_categories,id']]);
        DB::transaction(function () use ($data) {
            foreach (array_values($data['ordered_ids']) as $index => $id) {
                DiscoverCategory::query()->whereKey($id)->update(['sort_order' => $index + 1]);
            }
        });

        return ApiResponse::success(null, __('api.updated'));
    }

    public function status(Request $request, DiscoverCategory $discoverCategory)
    {
        $discoverCategory->update($request->validate(['status' => ['required', 'boolean']]));

        return ApiResponse::success($this->present($discoverCategory->load('translations')->loadCount('events')), __('api.updated'));
    }

    /** POST delete-multiple {ids[]} — deletes the ones that can go and skips the ones in use; says how many of each. */
    public function deleteMultiple(Request $request)
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer']]);
        $deleted = 0;
        $skipped = 0;
        foreach (DiscoverCategory::query()->whereIn('id', $data['ids'])->get() as $row) {
            if ($row->events()->exists()) {
                $skipped++;

                continue;
            }
            $row->delete();
            $deleted++;
        }

        return ApiResponse::success(['deleted' => $deleted, 'skipped' => $skipped], __('api.deleted'));
    }

    public function destroy(DiscoverCategory $discoverCategory)
    {
        if ($discoverCategory->events()->exists()) {
            throw new \Modules\Discover\Exceptions\DiscoverException('in_use', 422);
        }
        $discoverCategory->delete();

        return ApiResponse::success(null, __('api.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?DiscoverCategory $current): array
    {
        return $request->validate([
            'key' => [$current ? 'sometimes' : 'required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', Rule::unique('discover_categories', 'key')->ignore($current?->id)],
            'emoji' => ['nullable', 'string', 'max:16'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/'],
            'status' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'translations' => [$current ? 'sometimes' : 'required', 'array', 'min:1'],
            'translations.*.locale' => ['required', 'string', 'max:10'],
            'translations.*.name' => ['required', 'string', 'max:80'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(DiscoverCategory $category, array $data): DiscoverCategory
    {
        DB::transaction(function () use ($category, $data) {
            $category->fill(collect($data)->only(['key', 'emoji', 'color', 'status', 'sort_order'])->all())->save();
            foreach ($data['translations'] ?? [] as $row) {
                $category->translations()->updateOrCreate(['locale' => $row['locale']], ['name' => $row['name']]);
            }
        });

        return $category->load('translations')->loadCount('events');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DiscoverCategory $c): array
    {
        return [
            'id' => $c->id, 'key' => $c->key, 'name' => $c->translatedName(), 'emoji' => $c->emoji, 'color' => $c->color,
            'status' => (bool) $c->status, 'sort_order' => (int) $c->sort_order, 'events_count' => (int) ($c->events_count ?? 0),
            'created_at' => $c->created_at?->toIso8601String(), 'updated_at' => $c->updated_at?->toIso8601String(),
            'translations' => $c->translations->map(fn ($t) => ['locale' => $t->locale, 'name' => $t->name])->values()->all(),
        ];
    }
}
