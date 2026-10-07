<?php

namespace Modules\Chat\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Modules\Chat\Models\ChatSticker;
use Modules\Chat\Models\ChatStickerPack;
use Modules\Chat\Services\StickerService;

/**
 * Dorr's sticker packs: a translated name, a cover, stickers uploaded in bulk (PNG / WebP,
 * animated WebP too), each with the emoji it means.
 */
class ChatStickerController extends Controller implements HasMiddleware
{
    public function __construct(private readonly StickerService $stickers) {}

    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('chat-stickers', [
            ['view', ['index', 'show']],
            ['create', ['store', 'addStickers']],
            ['update', ['update', 'status', 'updateSticker']],
            ['delete', ['destroy', 'destroySticker']],
        ]);
    }

    public function index()
    {
        $packs = ChatStickerPack::query()->with(['translations', 'media', 'stickers.media'])->orderBy('sort_order')->orderBy('id')->get();

        return ApiResponse::success($packs->map(fn (ChatStickerPack $p) => $this->present($p))->values(), __('api.retrieved'));
    }

    public function show(ChatStickerPack $chatStickerPack)
    {
        return ApiResponse::success($this->present($chatStickerPack->load(['translations', 'media', 'stickers.media'])), __('api.retrieved'));
    }

    public function store(Request $request)
    {
        $pack = $this->stickers->savePack(null, $this->validated($request), $request->file('cover'));

        return ApiResponse::created($this->present($pack), __('api.created'));
    }

    public function update(Request $request, ChatStickerPack $chatStickerPack)
    {
        return ApiResponse::success($this->present($this->stickers->savePack($chatStickerPack, $this->validated($request), $request->file('cover'))), __('api.updated'));
    }

    public function status(Request $request, ChatStickerPack $chatStickerPack)
    {
        $data = $request->validate(['status' => ['required', 'boolean']]);
        $chatStickerPack->update($data);
        $this->stickers->flush();

        return ApiResponse::success($this->present($chatStickerPack->load(['translations', 'media', 'stickers.media'])), __('api.updated'));
    }

    public function destroy(ChatStickerPack $chatStickerPack)
    {
        $this->stickers->deletePack($chatStickerPack->load('stickers'));

        return ApiResponse::success(null, __('api.deleted'));
    }

    public function addStickers(Request $request, ChatStickerPack $chatStickerPack)
    {
        $data = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:50'],
            'files.*' => ['file', 'mimes:png,webp,gif', 'max:1024'],
            'emoji' => ['nullable', 'string', 'max:32'],
        ]);

        return ApiResponse::success($this->present($this->stickers->addStickers($chatStickerPack, $request->file('files'), $data['emoji'] ?? null)), __('api.created'));
    }

    public function updateSticker(Request $request, ChatSticker $chatSticker)
    {
        $data = $request->validate(['emoji' => ['nullable', 'string', 'max:32'], 'sort_order' => ['sometimes', 'integer', 'min:0']]);
        $this->stickers->updateSticker($chatSticker, $data);

        return ApiResponse::success($chatSticker->refresh()->present(), __('api.updated'));
    }

    public function destroySticker(ChatSticker $chatSticker)
    {
        $this->stickers->deleteSticker($chatSticker);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'translations' => ['required', 'array', 'min:1'],
            'translations.*.locale' => ['required', 'string', 'max:10'],
            'translations.*.name' => ['required', 'string', 'max:100'],
            'status' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'cover' => ['nullable', 'image', 'max:1024'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ChatStickerPack $pack): array
    {
        return [
            'id' => $pack->id,
            'name' => $pack->translatedName(),
            'status' => $pack->status,
            'sort_order' => $pack->sort_order,
            'cover' => $pack->coverUrl(),
            'translations' => $pack->translations->map(fn ($t) => ['locale' => $t->locale, 'name' => $t->name])->values(),
            'stickers' => $pack->stickers->map(fn (ChatSticker $s) => $s->present())->values(),
        ];
    }
}
