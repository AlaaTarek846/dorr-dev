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
    public function stickers(StickerService $stickers, GiphyService $giphy)
    {
        return ApiResponse::success([
            'packs' => $stickers->packs(),
            'library_enabled' => $giphy->enabled(),
        ], __('api.retrieved'));
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
