<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatFolder;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Support\ParticipantType;

/**
 * My own chat lists ("Family", "Work") — the tabs above the chat list. Private to me.
 */
class FolderService
{
    /**
     * @return Collection<int, ChatFolder>
     */
    public function list(Model $me): Collection
    {
        return ChatFolder::query()->ownedBy($me)->withCount('conversations')->orderBy('sort_order')->orderBy('id')->get();
    }

    public function create(Model $me, string $name): ChatFolder
    {
        $max = ChatSetting::current()->max_folders;

        if (ChatFolder::query()->ownedBy($me)->count() >= $max) {
            throw ChatException::tooManyFolders($max);
        }

        return ChatFolder::query()->create([
            'owner_type' => ParticipantType::aliasFor($me),
            'owner_id' => $me->getKey(),
            'name' => $name,
            'sort_order' => (int) ChatFolder::query()->ownedBy($me)->max('sort_order') + 1,
        ]);
    }

    public function update(Model $me, ChatFolder $folder, array $data): ChatFolder
    {
        $this->assertOwned($me, $folder);
        $folder->update(array_intersect_key($data, array_flip(['name', 'sort_order', 'color', 'emoji'])));

        return $folder;
    }

    public function delete(Model $me, ChatFolder $folder): void
    {
        $this->assertOwned($me, $folder);
        $folder->delete();
    }

    /**
     * Replace the folder's chats (only chats I'm in are accepted).
     *
     * @param  list<string>  $conversationUuids
     */
    public function syncConversations(Model $me, ChatFolder $folder, array $conversationUuids): ChatFolder
    {
        $this->assertOwned($me, $folder);

        $ids = ChatParticipant::query()->of($me)
            ->whereHas('conversation', fn ($q) => $q->whereIn('uuid', $conversationUuids))
            ->pluck('conversation_id');

        $folder->conversations()->sync($ids);

        return $folder->loadCount('conversations');
    }

    private function assertOwned(Model $me, ChatFolder $folder): void
    {
        if ($folder->owner_type !== ParticipantType::aliasFor($me) || (int) $folder->owner_id !== (int) $me->getKey()) {
            throw new ChatException('folder_not_found', 404);
        }
    }
}
