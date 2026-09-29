<?php

namespace Modules\Chat\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Modules\Chat\Models\ChatTheme;
use Modules\Chat\Services\ChatThemeService;

/**
 * Chat themes (docs/chat-plan.md §5): wallpaper + bubble colours, named per language, one default.
 * Create/update are POST (multipart, for the wallpaper).
 */
class ChatThemeController extends Controller implements HasMiddleware
{
    public function __construct(private readonly ChatThemeService $themes) {}

    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('chat-themes', [
            ['view', ['index', 'show']],
            ['create', ['store']],
            ['update', ['update', 'status']],
            ['delete', ['destroy']],
        ]);
    }

    public function index(Request $request)
    {
        $rows = ChatTheme::query()->with(['translations', 'media'])
            ->when($request->filled('search'), fn ($q) => $q->whereHas('translations', fn ($t) => $t->where('name', 'like', '%'.$request->string('search').'%')))
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->map(fn (ChatTheme $theme) => $this->present($theme));

        return ApiResponse::success($rows, __('api.retrieved'));
    }

    public function show(ChatTheme $chatTheme)
    {
        return ApiResponse::success($this->present($chatTheme->load(['translations', 'media'])), __('api.retrieved'));
    }

    public function store(Request $request)
    {
        $theme = $this->themes->save(null, $this->validated($request), $request->file('wallpaper'));

        return ApiResponse::created($this->present($theme), __('api.created'));
    }

    public function update(Request $request, ChatTheme $chatTheme)
    {
        $theme = $this->themes->save($chatTheme, $this->validated($request), $request->file('wallpaper'), $request->boolean('remove_wallpaper'));

        return ApiResponse::success($this->present($theme), __('api.updated'));
    }

    public function status(Request $request, ChatTheme $chatTheme)
    {
        $data = $request->validate(['status' => ['required', 'boolean']]);

        return ApiResponse::success($this->present($this->themes->setStatus($chatTheme, $data['status'])->load(['translations', 'media'])), __('api.updated'));
    }

    public function destroy(ChatTheme $chatTheme)
    {
        $this->themes->delete($chatTheme);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $color = ['string', 'regex:/^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/'];

        return $request->validate([
            'translations' => ['required', 'array', 'min:1'],
            'translations.*.locale' => ['required', 'string', 'max:10'],
            'translations.*.name' => ['required', 'string', 'max:100'],
            'background_color' => ['nullable', ...$color],
            'sender_color' => ['required', ...$color],
            'receiver_color' => ['required', ...$color],
            'is_dark' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'wallpaper' => ['nullable', 'image', 'max:5120'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ChatTheme $theme): array
    {
        return $theme->present() + [
            'status' => $theme->status,
            'sort_order' => $theme->sort_order,
            'translations' => $theme->translations->map(fn ($t) => ['locale' => $t->locale, 'name' => $t->name])->values(),
        ];
    }
}
