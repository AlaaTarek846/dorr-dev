<?php

namespace Modules\Chat\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\Chat\Models\ChatBlock;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Support\ParticipantDirectory;
use Modules\Chat\Support\ParticipantType;
use Modules\User\Models\User;

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

        return $blocks->map(fn (ChatBlock $b) => $this->present($me, $b));
    }

    /** Pages of 15; `q` matches their name or phone (account or the name I saved). */
    public function page(Model $me, ?string $q = null, int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        $perPage = min(max(1, $perPage), 50);
        $page = max(1, $page);
        $query = ChatBlock::query()
            ->where('blocker_type', ParticipantType::aliasFor($me))
            ->where('blocker_id', $me->getKey())
            ->latest('id');
        $search = trim((string) $q);
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $userIds = User::query()->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like))->pluck('id');
            $contacts = ChatContact::query()->ownedBy($me)->whereNotNull('contact_id')
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like))
                ->get(['contact_type', 'contact_id']);
            $query->where(function ($inner) use ($userIds, $contacts) {
                $inner->whereRaw('0 = 1');
                if ($userIds->isNotEmpty()) {
                    $inner->orWhere(fn ($u) => $u->where('blocked_type', 'user')->whereIn('blocked_id', $userIds));
                }
                foreach ($contacts as $c) {
                    $inner->orWhere(fn ($row) => $row->where('blocked_type', $c->contact_type)->where('blocked_id', $c->contact_id));
                }
            });
        }
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $this->directory->prime($me, $paginator->getCollection()->map(fn ($b) => [$b->blocked_type, $b->blocked_id]));
        $paginator->setCollection($paginator->getCollection()->map(fn (ChatBlock $b) => $this->present($me, $b))->values());

        return $paginator;
    }

    /**
     * @return array{profile: array<string, mixed>|null, blocked_at: string|null}
     */
    private function present(Model $me, ChatBlock $b): array
    {
        return [
            'profile' => $this->directory->profile($me, $b->blocked_type, $b->blocked_id),
            'blocked_at' => $b->created_at?->toIso8601String(),
        ];
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
