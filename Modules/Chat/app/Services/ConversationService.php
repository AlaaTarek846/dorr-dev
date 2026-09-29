<?php

namespace Modules\Chat\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Enums\ConversationStatus;
use Modules\Chat\Enums\ConversationType;
use Modules\Chat\Enums\MessageType;
use Modules\Chat\Enums\ParticipantRole;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Http\Resources\ConversationResource;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatFolder;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatMessageReceipt;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Support\ParticipantDirectory;
use Modules\Chat\Support\ParticipantType;

/**
 * Conversations from one person's point of view: the chat list, opening a direct chat,
 * message requests, reading / delivery, and everything a person decides about a chat for
 * themselves (pin, archive, mute, lock, clear, delete, theme, disappearing messages).
 */
class ConversationService
{
    public function __construct(
        private readonly ChatPrivacy $privacy,
        private readonly ChatBroadcaster $broadcaster,
        private readonly ParticipantDirectory $directory,
    ) {}

    // ---------------------------------------------------------------- access

    /**
     * My row in this conversation — the one gate every conversation endpoint goes through.
     * Former members keep read access to what they saw; `$active` demands current membership.
     *
     * @throws ChatException
     */
    public function participantOf(Model $me, ChatConversation $conversation, bool $active = false): ChatParticipant
    {
        $participant = ChatParticipant::query()->of($me)->where('conversation_id', $conversation->id)->first();

        if ($participant === null || ($active && ! $participant->isActive())) {
            throw ChatException::notParticipant();
        }

        return $participant;
    }

    // ---------------------------------------------------------------- list

    /**
     * My chat list. `filter`: all | unread | groups | direct | archived | locked | requests;
     * `folder` narrows to one of my folders; `search` matches names and message text.
     */
    public function list(Model $me, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $filter = $filters['filter'] ?? 'all';
        $myType = ParticipantType::aliasFor($me);

        $query = ChatParticipant::query()->of($me)
            ->where('is_deleted', false)
            ->whereHas('conversation', function (Builder $q) use ($filter) {
                $q->where(fn (Builder $q) => $q->whereNotNull('last_message_id')->orWhere('type', ConversationType::Group->value));

                if ($filter === 'groups') {
                    $q->where('type', ConversationType::Group->value);
                } elseif ($filter === 'direct') {
                    $q->where('type', ConversationType::Direct->value);
                }
            });

        // Requests someone sent me live in their own box; a rejected one is simply gone for me.
        $query->whereHas('conversation', function (Builder $q) use ($filter, $me, $myType) {
            $startedByMe = fn (Builder $q) => $q->where('created_by_type', $myType)->where('created_by_id', $me->getKey());

            if ($filter === 'requests') {
                $q->where('status', ConversationStatus::Pending->value)->whereNot($startedByMe);
            } else {
                $q->where(fn (Builder $q) => $q->where('status', ConversationStatus::Accepted->value)->orWhere($startedByMe));
            }
        });

        if ($filter !== 'requests') {
            $query->where('is_locked', $filter === 'locked');
            if ($filter !== 'locked') {
                $query->where('is_archived', $filter === 'archived');
            }
        }

        if ($filter === 'unread') {
            $query->where(fn (Builder $q) => $q->where('unread_count', '>', 0)->orWhere('marked_unread', true));
        }

        if (! empty($filters['folder'])) {
            $folder = ChatFolder::query()->ownedBy($me)->findOrFail((int) $filters['folder']);
            $query->whereIn('conversation_id', $folder->conversations()->pluck('chat_conversations.id'));
        }

        if (($search = trim((string) ($filters['search'] ?? ''))) !== '') {
            $query->whereIn('conversation_id', $this->searchConversationIds($me, $search));
        }

        $paginator = $query
            ->join('chat_conversations', 'chat_conversations.id', '=', 'chat_participants.conversation_id')
            ->select('chat_participants.*')
            ->orderByRaw('chat_participants.pinned_at IS NULL')
            ->orderByDesc('chat_participants.pinned_at')
            ->orderByRaw('COALESCE(chat_conversations.last_message_at, chat_conversations.created_at) DESC')
            ->paginate($perPage);

        $this->hydrate($me, $paginator->getCollection());

        return $paginator;
    }

    /**
     * How many message requests are waiting for me (the badge on the requests box).
     */
    public function requestsCount(Model $me): int
    {
        $myType = ParticipantType::aliasFor($me);

        return ChatParticipant::query()->of($me)->where('is_deleted', false)
            ->whereHas('conversation', fn (Builder $q) => $q->where('status', ConversationStatus::Pending->value)
                ->whereNotNull('last_message_id')
                ->whereNot(fn (Builder $q) => $q->where('created_by_type', $myType)->where('created_by_id', $me->getKey())))
            ->count();
    }

    public function resource(Model $me, ChatParticipant $participant, array $extra = []): ConversationResource
    {
        $this->hydrate($me, new EloquentCollection([$participant]));

        return new ConversationResource($participant, $me, $extra);
    }

    /**
     * The single-chat screen header: the conversation plus presence and block state.
     */
    public function show(Model $me, ChatConversation $conversation): ConversationResource
    {
        $participant = $this->participantOf($me, $conversation);
        $extra = [];

        if (! $conversation->isGroup()) {
            $peerRow = $conversation->participants()->where('id', '!=', $participant->id)->first();
            $peer = $peerRow?->participant();

            if ($peer !== null) {
                $extra = [
                    'i_blocked' => $this->privacy->hasBlocked($me, $peer),
                    'blocked_me' => $this->privacy->hasBlocked($peer, $me),
                    'presence' => app(PresenceService::class)->presenceFor($me, $peer),
                    'block_screenshots' => (bool) $this->privacy->peek($peer)->block_screenshots,
                ];
            }
        }

        return $this->resource($me, $participant, $extra);
    }

    /**
     * Load everything ConversationResource reads, for a whole page at once.
     *
     * @param  Collection<int, ChatParticipant>  $rows
     */
    public function hydrate(Model $me, $rows): void
    {
        $rows = collect($rows);

        if ($rows->isEmpty()) {
            return;
        }

        (new EloquentCollection($rows->all()))->load(['conversation.group.media', 'conversation.participants', 'conversation.lastMessage']);

        $keys = [];
        foreach ($rows as $row) {
            foreach ($row->conversation->participants as $p) {
                if ($row->conversation->isGroup() && $p->id !== $row->id) {
                    continue; // a group row only needs me + the last message's sender
                }
                $keys[] = [$p->participant_type, $p->participant_id];
            }
            if ($last = $row->conversation->lastMessage) {
                if ($last->sender_type !== null) {
                    $keys[] = [$last->sender_type, $last->sender_id];
                }
            }
        }

        $this->directory->prime($me, $keys);
    }

    // ---------------------------------------------------------------- direct chats

    /**
     * Open (or create) the direct chat between me and `$other`. Nothing appears in either chat
     * list until the first message is sent.
     *
     * A person who never saved me in their contacts gets my first message as a *request*
     * (docs/chat-plan.md §12 item 3), unless their privacy forbids strangers entirely.
     *
     * @throws ChatException
     */
    public function openDirect(Model $me, Model $other): ChatParticipant
    {
        $key = self::directKey($me, $other);

        $existing = ChatConversation::query()->withTrashed()->where('direct_key', $key)->first();

        if ($existing !== null) {
            $this->privacy->assertReachable($me, $other);

            if ($existing->trashed()) {
                $existing->restore();
            }

            return $this->participantOf($me, $existing);
        }

        $needsRequest = $this->privacy->assertCanMessage($me, $other);

        return DB::transaction(function () use ($me, $other, $key, $needsRequest) {
            $conversation = ChatConversation::query()->create([
                'type' => ConversationType::Direct,
                'status' => $needsRequest ? ConversationStatus::Pending : ConversationStatus::Accepted,
                'direct_key' => $key,
                'created_by_type' => ParticipantType::aliasFor($me),
                'created_by_id' => $me->getKey(),
            ]);

            $mine = $this->addParticipant($conversation, $me, ParticipantRole::Member);
            $this->addParticipant($conversation, $other, ParticipantRole::Member);

            return $mine;
        });
    }

    public static function directKey(Model $a, Model $b): string
    {
        $keys = [ParticipantType::key($a), ParticipantType::key($b)];
        sort($keys);

        return implode('|', $keys);
    }

    public function addParticipant(ChatConversation $conversation, Model $account, ParticipantRole $role, ?int $clearedBefore = null): ChatParticipant
    {
        $row = ChatParticipant::query()->firstOrNew([
            'conversation_id' => $conversation->id,
            'participant_type' => ParticipantType::aliasFor($account),
            'participant_id' => $account->getKey(),
        ]);

        $row->fill([
            'role' => $role,
            'joined_at' => now(),
            'left_at' => null,
            'is_deleted' => false,
            'unread_count' => 0,
            'has_unread_mention' => false,
            // Someone joining (or re-joining) a group doesn't get the history from before they joined.
            'cleared_before_message_id' => $clearedBefore ?? $row->cleared_before_message_id,
            'last_read_message_id' => $clearedBefore ?? $row->last_read_message_id,
            'last_delivered_message_id' => $clearedBefore ?? $row->last_delivered_message_id,
        ])->save();

        return $row;
    }

    // ---------------------------------------------------------------- message requests

    public function accept(Model $me, ChatConversation $conversation): ChatParticipant
    {
        $participant = $this->assertRequestRecipient($me, $conversation);
        $conversation->update(['status' => ConversationStatus::Accepted]);

        $this->broadcaster->toParticipants($conversation->participants, 'chat.conversation.updated', [
            'conversation_id' => $conversation->uuid, 'status' => ConversationStatus::Accepted->value,
        ]);

        return $participant;
    }

    /**
     * Reject a request: the sender can't write again (and optionally gets blocked).
     */
    public function reject(Model $me, ChatConversation $conversation, bool $block = false): void
    {
        $participant = $this->assertRequestRecipient($me, $conversation);

        DB::transaction(function () use ($me, $conversation, $participant, $block) {
            $conversation->update(['status' => ConversationStatus::Rejected]);
            $participant->update(['is_deleted' => true, 'unread_count' => 0]);

            if ($block && ($sender = $conversation->participants()->where('id', '!=', $participant->id)->first()?->participant())) {
                app(BlockService::class)->block($me, $sender);
            }
        });
    }

    private function assertRequestRecipient(Model $me, ChatConversation $conversation): ChatParticipant
    {
        $participant = $this->participantOf($me, $conversation, true);

        $startedByMe = $conversation->created_by_type === $participant->participant_type
            && (int) $conversation->created_by_id === (int) $participant->participant_id;

        if ($conversation->status !== ConversationStatus::Pending || $startedByMe) {
            throw new ChatException('not_a_request', 422);
        }

        return $participant;
    }

    // ---------------------------------------------------------------- reading / delivery

    /**
     * I've read the conversation up to `$upTo` (default: everything). Clears my unread badge and
     * tells the others (blue ticks) — unless read receipts are off for me, or it's still a request.
     */
    public function markRead(Model $me, ChatConversation $conversation, ?ChatMessage $upTo = null): ChatParticipant
    {
        $participant = $this->participantOf($me, $conversation);
        $upToId = $upTo?->id ?? (int) $conversation->last_message_id;

        if ($upToId === 0) {
            return $participant;
        }

        $previousRead = (int) $participant->last_read_message_id;
        $previousDelivered = (int) $participant->last_delivered_message_id;

        $newRead = max($previousRead, $upToId);
        // System lines ("Sara joined") never count as unread.
        $remaining = $conversation->messages()->where('id', '>', $newRead)->whereNotNull('sender_type')
            ->where(fn ($q) => $q->where('sender_type', '!=', $participant->participant_type)->orWhere('sender_id', '!=', $participant->participant_id))
            ->count();

        $participant->update([
            'last_read_message_id' => $newRead,
            'last_read_at' => now(),
            'last_delivered_message_id' => max($previousDelivered, $newRead),
            'unread_count' => $remaining,
            'marked_unread' => false,
            'has_unread_mention' => $remaining > 0 && $participant->has_unread_mention,
        ]);

        if ($newRead > $previousRead) {
            if ($conversation->isGroup()) {
                $this->writeGroupReceipts($participant, $conversation, $previousRead, $newRead, 'read_at');
            }

            $hidden = $conversation->status === ConversationStatus::Pending
                || (! $conversation->isGroup() && ! $this->privacy->peek($me)->read_receipts);

            if (! $hidden) {
                $this->broadcaster->toParticipants($conversation->activeParticipants()->get(), 'chat.receipt', [
                    'conversation_id' => $conversation->uuid,
                    'participant' => $participant->key(),
                    'read_up_to' => ChatMessage::query()->whereKey($newRead)->value('uuid'),
                    'delivered_up_to' => ChatMessage::query()->whereKey(max($previousDelivered, $newRead))->value('uuid'),
                ], $participant);
            }
        }

        return $participant;
    }

    /**
     * The app received everything up to now (called when it comes online / gets a push):
     * grey double ticks for the senders.
     */
    public function markAllDelivered(Model $me): int
    {
        $rows = ChatParticipant::query()->of($me)->whereNull('left_at')
            ->join('chat_conversations', 'chat_conversations.id', '=', 'chat_participants.conversation_id')
            ->whereNotNull('chat_conversations.last_message_id')
            ->whereRaw('COALESCE(chat_participants.last_delivered_message_id, 0) < chat_conversations.last_message_id')
            ->select('chat_participants.*', 'chat_conversations.last_message_id as conversation_last_message_id')
            ->get();

        foreach ($rows as $row) {
            $conversation = ChatConversation::query()->find($row->conversation_id);
            $previous = (int) $row->last_delivered_message_id;
            $upTo = (int) $row->conversation_last_message_id;

            ChatParticipant::query()->whereKey($row->id)->update(['last_delivered_message_id' => $upTo]);

            if ($conversation->isGroup()) {
                $this->writeGroupReceipts($row, $conversation, $previous, $upTo, 'delivered_at');
            }

            if ($conversation->status !== ConversationStatus::Pending) {
                $this->broadcaster->toParticipants($conversation->activeParticipants()->get(), 'chat.receipt', [
                    'conversation_id' => $conversation->uuid,
                    'participant' => $row->key(),
                    'delivered_up_to' => ChatMessage::query()->whereKey($upTo)->value('uuid'),
                ], $row);
            }
        }

        return $rows->count();
    }

    /**
     * Per-message rows behind "Message info" in groups (bounded: at most the messages between
     * the two marks that others sent).
     */
    private function writeGroupReceipts(ChatParticipant $participant, ChatConversation $conversation, int $after, int $upTo, string $column): void
    {
        $now = now();

        $conversation->messages()
            ->where('id', '>', $after)->where('id', '<=', $upTo)
            ->whereNotNull('sender_type')
            ->where(fn ($q) => $q->where('sender_type', '!=', $participant->participant_type)->orWhere('sender_id', '!=', $participant->participant_id))
            ->orderBy('id')->limit(1000)->pluck('id')
            ->chunk(200)->each(function ($ids) use ($participant, $column, $now) {
                foreach ($ids as $messageId) {
                    $receipt = ChatMessageReceipt::query()->firstOrNew(['message_id' => $messageId, 'participant_id' => $participant->id]);
                    $receipt->delivered_at ??= $now;
                    if ($column === 'read_at') {
                        $receipt->read_at ??= $now;
                    }
                    $receipt->save();
                }
            });
    }

    // ---------------------------------------------------------------- my settings on a chat

    /**
     * pinned / archived / locked / muted_for / marked_unread / theme — whichever are given.
     */
    public function updateSettings(Model $me, ChatConversation $conversation, array $data): ChatParticipant
    {
        $participant = $this->participantOf($me, $conversation);
        $changes = [];

        if (array_key_exists('pinned', $data)) {
            $changes['pinned_at'] = $data['pinned'] ? ($participant->pinned_at ?? now()) : null;
        }
        if (array_key_exists('archived', $data)) {
            $changes['is_archived'] = (bool) $data['archived'];
        }
        if (array_key_exists('locked', $data)) {
            $changes['is_locked'] = (bool) $data['locked'];
        }
        if (array_key_exists('marked_unread', $data)) {
            $changes['marked_unread'] = (bool) $data['marked_unread'];
        }
        if (array_key_exists('mute', $data)) {
            // 8h | 1w | always | off
            $changes['muted_until'] = match ($data['mute']) {
                '8h' => now()->addHours(8),
                '1w' => now()->addWeek(),
                'always' => now()->addYears(100),
                default => null,
            };
        }
        if (array_key_exists('theme_id', $data)) {
            $changes['theme_id'] = $data['theme_id'];
        }
        if (array_key_exists('custom_theme', $data)) {
            $changes['custom_theme'] = $data['custom_theme'];
        }

        $participant->update($changes);

        return $participant;
    }

    /**
     * Clear the chat's history for me only (the chat stays in my list).
     */
    public function clear(Model $me, ChatConversation $conversation): ChatParticipant
    {
        $participant = $this->participantOf($me, $conversation);

        $participant->update([
            'cleared_before_message_id' => $conversation->last_message_id,
            'unread_count' => 0,
            'marked_unread' => false,
            'has_unread_mention' => false,
        ]);

        return $participant;
    }

    /**
     * Delete the chat for me: history cleared and the row hidden until a new message arrives.
     * A group has to be left first (like WhatsApp).
     */
    public function deleteForMe(Model $me, ChatConversation $conversation): void
    {
        $participant = $this->participantOf($me, $conversation);

        if ($conversation->isGroup() && $participant->isActive()) {
            throw new ChatException('leave_group_first', 422);
        }

        $participant->update([
            'cleared_before_message_id' => $conversation->last_message_id,
            'is_deleted' => true,
            'unread_count' => 0,
            'marked_unread' => false,
            'has_unread_mention' => false,
            'pinned_at' => null,
        ]);
    }

    /**
     * Disappearing messages for the whole conversation: null (off), 24h, 7 days or 90 days.
     */
    public function setDisappearing(Model $me, ChatConversation $conversation, ?int $seconds): ChatConversation
    {
        $participant = $this->participantOf($me, $conversation, true);

        if ($conversation->isGroup() && $conversation->group->only_admins_edit_info && ! $participant->isAdmin()) {
            throw ChatException::adminsOnly();
        }

        $conversation->update(['disappearing_seconds' => $seconds]);

        app(MessageService::class)->system($conversation, $me, $seconds ? 'disappearing_on' : 'disappearing_off', [], ['seconds' => $seconds]);

        return $conversation;
    }

    /**
     * "Ahmed is typing…" — relayed to the others, never stored.
     */
    public function typing(Model $me, ChatConversation $conversation, string $state): void
    {
        $participant = $this->participantOf($me, $conversation, true);

        $this->broadcaster->toParticipants($conversation->activeParticipants()->get(), 'chat.typing', [
            'conversation_id' => $conversation->uuid,
            'participant' => $participant->key(),
            'state' => $state,
        ], $participant);
    }

    /**
     * @return list<int>
     */
    private function searchConversationIds(Model $me, string $search): array
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
        $mine = ChatParticipant::query()->of($me)->pluck('conversation_id');

        $byGroupName = DB::table('chat_groups')->whereIn('conversation_id', $mine)->where('name', 'like', $like)->pluck('conversation_id');

        $byMessage = ChatMessage::query()->whereIn('conversation_id', $mine)
            ->where('type', MessageType::Text->value)->whereNull('deleted_for_everyone_at')
            ->where('body', 'like', $like)->distinct()->pluck('conversation_id');

        // Direct chats whose other person matches by name / phone, or by the name I saved them under.
        $peerIds = collect();
        foreach (config('chat.participants') as $alias => $class) {
            $accounts = $class::query()->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('phone', 'like', $like))->pluck('id');
            $saved = DB::table('chat_contacts')->where('owner_type', ParticipantType::aliasFor($me))->where('owner_id', $me->getKey())
                ->where('contact_type', $alias)->where('name', 'like', $like)->pluck('contact_id');
            $ids = $accounts->merge($saved)->unique();

            if ($ids->isNotEmpty()) {
                $peerIds = $peerIds->merge(ChatParticipant::query()->whereIn('conversation_id', $mine)
                    ->where('participant_type', $alias)->whereIn('participant_id', $ids)
                    ->whereNot(fn ($q) => $q->where('participant_type', ParticipantType::aliasFor($me))->where('participant_id', $me->getKey()))
                    ->pluck('conversation_id'));
            }
        }

        return $byGroupName->merge($byMessage)->merge($peerIds)->unique()->values()->all();
    }
}
