<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Modules\AI\Models\AiConversation;

class AiConversationRepository extends BaseRepository
{
    public function __construct(AiConversation $model)
    {
        $this->model = $model;
    }

    /**
     * @return Collection<int, AiConversation>
     */
    public function listForOwner(Authenticatable $owner): Collection
    {
        return $this->model->newQuery()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getAuthIdentifier())
            ->with('latestMessage')
            ->withCount('messages')
            ->orderByDesc('updated_at')
            ->get();
    }

    public function findForOwner(Authenticatable $owner, int|string $id): AiConversation
    {
        return $this->model->newQuery()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getAuthIdentifier())
            ->with('messages.attachments')
            ->findOrFail($id);
    }

    public function createForOwner(Authenticatable $owner): AiConversation
    {
        return $this->model->newQuery()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getAuthIdentifier(),
        ]);
    }

    public function deleteForOwner(Authenticatable $owner, int|string $id): bool
    {
        $conversation = $this->findForOwner($owner, $id);

        return (bool) $conversation->delete();
    }

    /**
     * @return Collection<int, AiConversation>
     */
    public function listForOwnerWithMessages(Authenticatable $owner): Collection
    {
        return $this->model->newQuery()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getAuthIdentifier())
            ->with(['messages.attachments'])
            ->orderBy('created_at')
            ->get();
    }

    /**
     * v2.0 requirements doc 17.3: an authorized self-service erase of all
     * of this owner's AI conversations. ai_conversations -> ai_messages ->
     * ai_conversation_attachments all cascade on delete, so removing the
     * conversations is enough to remove the product-data side.
     */
    public function deleteAllForOwner(Authenticatable $owner): int
    {
        return $this->model->newQuery()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getAuthIdentifier())
            ->get()
            ->each(fn (AiConversation $conversation) => $conversation->delete())
            ->count();
    }
}
