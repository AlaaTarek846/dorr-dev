<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatMessageUserState;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Models\ChatStarFolder;
use Modules\Chat\Support\ParticipantType;

/**
 * Favourites folders (spec 24): my starred messages sorted into my own folders ("Work",
 * "Recipes"…). A starred message in no folder stays in "Favourites".
 */
class StarFolderController extends Controller
{
    public const MAX = 30;

    public function index(Request $request)
    {
        $me = $request->user();
        $mine = ChatParticipant::query()->of($me)->pluck('id');
        $counts = ChatMessageUserState::query()->whereIn('participant_id', $mine)->whereNotNull('starred_at')->whereNull('deleted_at')
            ->selectRaw('star_folder_id, count(*) as c')->groupBy('star_folder_id')->pluck('c', 'star_folder_id');

        return ApiResponse::success([
            'unsorted_count' => (int) ($counts[''] ?? $counts[null] ?? 0),
            'folders' => ChatStarFolder::query()->ownedBy($me)->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn ($f) => $this->present($f, (int) ($counts[$f->id] ?? 0)))->values(),
        ], __('api.retrieved'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, false);
        $me = $request->user();
        if (ChatStarFolder::query()->ownedBy($me)->count() >= self::MAX) {
            throw new ChatException('star_folder_limit', 422, ['max' => self::MAX]);
        }
        $folder = ChatStarFolder::query()->create($data + [
            'owner_type' => ParticipantType::aliasFor($me),
            'owner_id' => $me->getKey(),
            'sort_order' => (int) ChatStarFolder::query()->ownedBy($me)->max('sort_order') + 1,
        ]);

        return ApiResponse::created($this->present($folder, 0), __('api.created'));
    }

    public function update(Request $request, ChatStarFolder $starFolder)
    {
        $this->assertMine($request, $starFolder);
        $starFolder->update($this->validated($request, true));

        return ApiResponse::success($this->present($starFolder, 0), __('api.updated'));
    }

    /** Its messages stay starred, back in "Favourites". */
    public function destroy(Request $request, ChatStarFolder $starFolder)
    {
        $this->assertMine($request, $starFolder);
        $starFolder->delete();

        return ApiResponse::success(null, __('api.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ChatStarFolder $f, int $count): array
    {
        return ['id' => $f->id, 'name' => $f->name, 'emoji' => $f->emoji, 'color' => $f->color, 'count' => $count];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $partial): array
    {
        return $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:50'],
            'emoji' => ['sometimes', 'nullable', 'string', 'max:16'],
            'color' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);
    }

    private function assertMine(Request $request, ChatStarFolder $folder): void
    {
        if (! $folder->isOwnedBy($request->user())) {
            throw new ChatException('star_folder_not_found', 404);
        }
    }
}
