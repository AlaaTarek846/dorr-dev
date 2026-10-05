<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Chat\Services\GiphyService;
use Modules\Chat\Services\StickerService;

/**
 * The composer's sticker / GIF panel: Dorr's sticker packs, and the Giphy library (GIFs and
 * animated stickers — trending, or searched in the person's language).
 */
class ExpressionController extends Controller
{
    public function stickers(Request $request, StickerService $stickers, GiphyService $giphy)
    {
        return ApiResponse::success([
            'packs' => $stickers->packs(),
            // The stickers I made from my own photos.
            'mine' => $stickers->mine($request->user()),
            'library_enabled' => $giphy->enabled(),
        ], __('api.retrieved'));
    }

    /**
     * A sticker made from my photo: the app cuts it out and uploads a 512×512 transparent image.
     */
    public function storeMine(Request $request, StickerService $stickers)
    {
        $data = $request->validate([
            'image' => ['required', 'image', 'mimes:png,webp', 'max:1024', 'dimensions:max_width=1024,max_height=1024'],
            'emoji' => ['nullable', 'string', 'max:16'],
        ]);

        return ApiResponse::created($stickers->addMine($request->user(), $request->file('image'), $data['emoji'] ?? null), __('api.created'));
    }

    public function destroyMine(Request $request, StickerService $stickers, int $sticker)
    {
        $stickers->removeMine($request->user(), $sticker);

        return ApiResponse::success(null, __('api.deleted'));
    }

    public function library(Request $request, GiphyService $giphy)
    {
        $data = $request->validate([
            'kind' => ['nullable', 'in:gifs,stickers'],
            'q' => ['nullable', 'string', 'max:50'],
            'offset' => ['nullable', 'integer', 'min:0', 'max:499'],
        ]);

        return ApiResponse::success($giphy->browse($data['kind'] ?? 'gifs', $data['q'] ?? null, (int) ($data['offset'] ?? 0)), __('api.retrieved'));
    }
}
