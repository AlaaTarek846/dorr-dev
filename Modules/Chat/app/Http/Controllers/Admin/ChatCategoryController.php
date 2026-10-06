<?php

namespace Modules\Chat\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Models\ChatCategory;

/**
 * The categories channels and merchant portals pick from (sport, news…), each with an icon and
 * a name per language. Deleting one leaves its channels / portals without a category.
 */
class ChatCategoryController extends Controller implements HasMiddleware
{
    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('chat-categories', [
            ['view', ['index', 'show', 'dropdown']],
            ['create', ['store']],
            ['update', ['update', 'status']],
            ['delete', ['destroy']],
        ]);
    }

    public function index(Request $request)
    {
        $rows = ChatCategory::query()->with(['translations', 'media'])->withCount(['portals', 'channels'])
            ->when($request->filled('search'), fn ($q) => $q->whereHas('translations', fn ($t) => $t->where('name', 'like', '%'.$request->string('search').'%')))
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->map(fn (ChatCategory $category) => $this->present($category));

        return ApiResponse::success($rows, __('api.retrieved'));
    }

    public function dropdown()
    {
        $rows = ChatCategory::query()->with('translations')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (ChatCategory $category) => ['id' => $category->id, 'name' => $category->translatedName()]);

        return ApiResponse::success($rows, __('api.retrieved'));
    }

    public function show(ChatCategory $chatCategory)
    {
        return ApiResponse::success($this->present($chatCategory->load(['translations', 'media'])->loadCount(['portals', 'channels'])), __('api.retrieved'));
    }

    public function store(Request $request)
    {
        $category = $this->save(new ChatCategory, $this->validated($request, true), $request);

        return ApiResponse::created($this->present($category), __('api.created'));
    }

    /** POST (multipart: the icon can change). */
    public function update(Request $request, ChatCategory $chatCategory)
    {
        return ApiResponse::success($this->present($this->save($chatCategory, $this->validated($request, false), $request)), __('api.updated'));
    }

    public function status(Request $request, ChatCategory $chatCategory)
    {
        $chatCategory->update($request->validate(['status' => ['required', 'boolean']]));

        return ApiResponse::success($this->present($chatCategory->load(['translations', 'media'])->loadCount(['portals', 'channels'])), __('api.updated'));
    }

    public function destroy(ChatCategory $chatCategory)
    {
        $chatCategory->delete();

        return ApiResponse::success(null, __('api.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'translations' => ['required', 'array', 'min:1'],
            'translations.*.locale' => ['required', 'string', 'max:10'],
            'translations.*.name' => ['required', 'string', 'max:100'],
            'status' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'icon' => [$creating ? 'required' : 'nullable', 'image', 'mimes:jpeg,jpg,png,webp,svg', 'max:2048'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(ChatCategory $category, array $data, Request $request): ChatCategory
    {
        DB::transaction(function () use ($category, $data, $request) {
            $category->fill(collect($data)->only(['status', 'sort_order'])->all())->save();

            foreach ($data['translations'] as $row) {
                $category->translations()->updateOrCreate(['locale' => $row['locale']], ['name' => $row['name']]);
            }

            if ($request->hasFile('icon')) {
                $category->setSingleMedia(ChatCategory::ICON, $request->file('icon'));
            }
        });

        return $category->load(['translations', 'media'])->loadCount(['portals', 'channels']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ChatCategory $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->translatedName(),
            'icon' => $category->iconUrl(),
            'status' => $category->status,
            'sort_order' => $category->sort_order,
            'portals_count' => $category->portals_count ?? 0,
            'channels_count' => $category->channels_count ?? 0,
            'translations' => $category->translations->map(fn ($t) => ['locale' => $t->locale, 'name' => $t->name])->values(),
        ];
    }
}
