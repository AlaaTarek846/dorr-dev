<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\Chat\Models\ChatBlock;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Support\ParticipantDirectory;
use Modules\Chat\Support\ParticipantType;

/**
 * Blocking closes the direct chat both ways (no messages, no calls, no group adds) — checked by
 * ChatPrivacy everywhere. The blocked person is not told; their messages simply can't be sent.
 */
class BlockService
{
    public function __construct(
        private readonly ChatBroadcaster $broadcaster,
        private readonly ParticipantDirectory $directory,
    ) {}

    public function block(Model $me, Model $other): void
    {
        ChatBlock::query()->firstOrCreate([
            'blocker_type' => ParticipantType::aliasFor($me), 'blocker_id' => $me->getKey(),
            'blocked_type' => ParticipantType::aliasFor($other), 'blocked_id' => $other->getKey(),
        ]);

        $this->notifyMe($me, $other);
    }

    public function unblock(Model $me, Model $other): void
    {
        ChatBlock::query()->where([
            'blocker_type' => ParticipantType::aliasFor($me), 'blocker_id' => $me->getKey(),
            'blocked_type' => ParticipantType::aliasFor($other), 'blocked_id' => $other->getKey(),
        ])->delete();

        $this->notifyMe($me, $other);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function list(Model $me): Collection
    {
        $blocks = ChatBlock::query()->where('blocker_type', ParticipantType::aliasFor($me))->where('blocker_id', $me->getKey())->latest()->get();

        $this->directory->prime($me, $blocks->map(fn ($b) => [$b->blocked_type, $b->blocked_id]));

        return $blocks->map(fn (ChatBlock $b) => [
            'profile' => $this->directory->profile($me, $b->blocked_type, $b->blocked_id),
            'blocked_at' => $b->created_at?->toIso8601String(),
        ]);
    }

    /**
     * My other devices refresh the chat header (the blocked side is deliberately not told).
     */
    private function notifyMe(Model $me, Model $other): void
    {
        $conversation = ChatConversation::query()->where('direct_key', ConversationService::directKey($me, $other))->first();

        if ($conversation !== null) {
            $this->broadcaster->toAccounts([[ParticipantType::aliasFor($me), (int) $me->getKey()]], 'chat.conversation.updated', ['conversation_id' => $conversation->uuid]);
        }
    }
}
