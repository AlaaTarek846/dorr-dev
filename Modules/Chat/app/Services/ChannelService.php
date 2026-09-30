<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Chat\Enums\ConversationStatus;
use Modules\Chat\Enums\ConversationType;
use Modules\Chat\Enums\ParticipantRole;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroup;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Support\ParticipantType;

/**
 * Channels: one-to-many broadcasting (offers, news, a shop's updates). Built on groups — a
 * chat_groups row for name / description / photo / invite link — with channel rules:
 *
 *  - only the owner and admins post; followers read, react and vote in polls;
 *  - followers never see each other (no member list, no "X joined" lines, no typing);
 *  - a public channel is found in Discover by name or @handle and followed with one tap,
 *    a private one only through its invite link / QR;
 *  - posts show how many followers saw them (views) instead of ticks; no calls.
 */
class ChannelService
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
    ) {}

    public function create(Model $me, string $name, ?string $description, ?string $handle, bool $isPublic, ?UploadedFile $avatar = null): ChatParticipant
    {
        $handle = $this->cleanHandle($handle);

        return DB::transaction(function () use ($me, $name, $description, $handle, $isPublic, $avatar) {
            $conversation = ChatConversation::query()->create([
                'type' => ConversationType::Channel,
                'status' => ConversationStatus::Accepted,
                'created_by_type' => ParticipantType::aliasFor($me),
                'created_by_id' => $me->getKey(),
            ]);

            $group = ChatGroup::query()->create([
                'conversation_id' => $conversation->id,
                'name' => $name,
                'description' => $description,
                'handle' => $handle,
                'is_public' => $isPublic,
                'invite_token' => Str::random(24),
                'only_admins_send' => true,
                'only_admins_edit_info' => true,
                'only_admins_add_members' => true,
            ]);

            if ($avatar !== null) {
                $group->setSingleMedia('avatar', $avatar);
            }

            $mine = $this->conversations->addParticipant($conversation, $me, ParticipantRole::Owner);
            $this->messages->system($conversation, $me, 'channel_created', [], ['name' => $name]);

            return $mine->refresh();
        });
    }

    /**
     * The @handle (admins): letters, digits and underscores, 3–32, unique. `null` removes it.
     */
    public function setHandle(Model $me, ChatConversation $conversation, ?string $handle): void
    {
        $this->assertChannelAdmin($me, $conversation);
        $conversation->group->update(['handle' => $this->cleanHandle($handle, $conversation->group->id)]);
    }

    /**
     * Public channels to follow: by name or @handle, the biggest first, each with its follower
     * count and whether I already follow it.
     */
    public function discover(Model $me, ?string $search, int $perPage = 20): LengthAwarePaginator
    {
        $search = trim((string) $search);
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], ltrim($search, '@')).'%';

        return ChatConversation::query()
            ->where('type', ConversationType::Channel->value)
            ->whereHas('group', fn ($q) => $q->where('is_public', true)
                ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('handle', 'like', $like))))
            ->with('group.media')
            ->withCount(['participants as followers_count' => fn ($q) => $q->whereNull('left_at')])
            ->orderByDesc('followers_count')->orderByDesc('last_message_at')
            ->paginate($perPage);
    }

    /**
     * A channel's card (Discover, a link, an @handle), followed or not.
     *
     * @return array<string, mixed>
     */
    public function present(Model $me, ChatConversation $channel): array
    {
        $group = $channel->group;

        return [
            'id' => $channel->uuid,
            'name' => $group?->name,
            'description' => $group?->description,
            'avatar' => $group?->avatarUrl(),
            'handle' => $group?->handle,
            'is_public' => (bool) $group?->is_public,
            'followers_count' => (int) ($channel->followers_count ?? $channel->activeParticipants()->count()),
            'is_following' => $channel->activeParticipants()->of($me)->exists(),
            'last_post_at' => $channel->last_message_at?->toIso8601String(),
        ];
    }

    /**
     * A public channel by uuid or @handle (a private one only through its invite link).
     */
    public function find(Model $me, string $idOrHandle): ChatConversation
    {
        $channel = ChatConversation::query()->where('type', ConversationType::Channel->value)
            ->where(fn ($q) => $q->where('uuid', $idOrHandle)->orWhereHas('group', fn ($g) => $g->where('handle', ltrim($idOrHandle, '@'))))
            ->with('group.media')->first();

        $following = $channel?->activeParticipants()->of($me)->exists();

        if ($channel === null || (! $channel->group?->is_public && ! $following)) {
            throw new ChatException('channel_not_found', 404);
        }

        return $channel;
    }

    /**
     * Follow — quietly (no system line), from the current post on (history stays for new
     * followers: a channel is meant to be read back).
     */
    public function follow(Model $me, ChatConversation $channel): ChatParticipant
    {
        $existing = $channel->activeParticipants()->of($me)->first();
        if ($existing !== null) {
            return $existing;
        }

        $row = $this->conversations->addParticipant($channel, $me, ParticipantRole::Member);
        // Nothing unread on arrival: the badge starts with the next post.
        $row->update(['last_read_message_id' => $channel->last_message_id, 'last_delivered_message_id' => $channel->last_message_id, 'unread_count' => 0]);

        return $row->refresh();
    }

    /**
     * Unfollow (the owner leaves through "leave", which hands the channel over).
     */
    public function unfollow(Model $me, ChatConversation $channel): void
    {
        $this->assertChannel($channel);
        app(GroupService::class)->leave($me, $channel);
    }

    private function cleanHandle(?string $handle, ?int $ignoreGroupId = null): ?string
    {
        $handle = $handle === null ? null : strtolower(ltrim(trim($handle), '@'));

        if ($handle === null || $handle === '') {
            return null;
        }

        if (! preg_match('/^[a-z0-9_]{3,32}$/', $handle)) {
            throw new ChatException('channel_handle_invalid', 422);
        }

        $taken = ChatGroup::query()->where('handle', $handle)->when($ignoreGroupId, fn ($q, $id) => $q->whereKeyNot($id))->exists();
        if ($taken) {
            throw new ChatException('channel_handle_taken', 422);
        }

        return $handle;
    }

    private function assertChannel(ChatConversation $conversation): void
    {
        if (! $conversation->isChannel()) {
            throw new ChatException('channel_not_found', 404);
        }
    }

    private function assertChannelAdmin(Model $me, ChatConversation $conversation): void
    {
        $this->assertChannel($conversation);
        $row = $this->conversations->participantOf($me, $conversation, true);
        if (! $row->isAdmin()) {
            throw ChatException::adminsOnly();
        }
    }
}
