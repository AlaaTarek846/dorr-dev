<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
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
    public function listForUser(int $userId): Collection
    {
        return $this->model->newQuery()
            ->where('user_id', $userId)
            ->with('latestMessage')
            ->withCount('messages')
            ->orderByDesc('updated_at')
            ->get();
    }

    public function findForUser(int $userId, int|string $id): AiConversation
    {
        return $this->model->newQuery()
            ->where('user_id', $userId)
            ->with('messages')
            ->findOrFail($id);
    }

    public function createForUser(int $userId): AiConversation
    {
        return $this->model->newQuery()->create(['user_id' => $userId]);
    }

    public function deleteForUser(int $userId, int|string $id): bool
    {
        $conversation = $this->findForUser($userId, $id);

        return (bool) $conversation->delete();
    }
}
