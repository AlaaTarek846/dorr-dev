<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Chat\Models\ChatFolder;
use Modules\Chat\Services\FolderService;

class FolderController extends Controller
{
    public function __construct(private readonly FolderService $folders) {}

    public function index(Request $request)
    {
        return ApiResponse::success($this->folders->list($request->user())->map(fn ($f) => $this->present($f))->values(), __('api.retrieved'));
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:50']]);

        return ApiResponse::created($this->present($this->folders->create($request->user(), $data['name'])->loadCount('conversations')), __('api.created'));
    }

    public function update(Request $request, ChatFolder $folder)
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:50'], 'sort_order' => ['sometimes', 'integer', 'min:0']]);

        return ApiResponse::success($this->present($this->folders->update($request->user(), $folder, $data)->loadCount('conversations')), __('api.updated'));
    }

    public function destroy(Request $request, ChatFolder $folder)
    {
        $this->folders->delete($request->user(), $folder);

        return ApiResponse::success(null, __('api.deleted'));
    }

    public function conversations(Request $request, ChatFolder $folder)
    {
        $data = $request->validate(['conversations' => ['present', 'array'], 'conversations.*' => ['uuid']]);

        return ApiResponse::success($this->present($this->folders->syncConversations($request->user(), $folder, $data['conversations'])), __('api.updated'));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ChatFolder $folder): array
    {
        return [
            'id' => $folder->id,
            'name' => $folder->name,
            'sort_order' => $folder->sort_order,
            'conversations_count' => (int) ($folder->conversations_count ?? 0),
        ];
    }
}
