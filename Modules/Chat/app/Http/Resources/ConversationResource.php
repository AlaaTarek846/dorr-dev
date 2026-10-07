<?php

namespace Modules\Chat\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use Modules\Chat\Enums\ConversationStatus;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Services\ChatThemeService;
use Modules\Chat\Services\GroupService;
use Modules\Chat\Support\MessageViewContext;
use Modules\Chat\Support\ParticipantDirectory;

/**
 * A conversation as it appears in *my* chat list: the other person (or the group), the last
 * message with its ticks, and everything I decided about it (pinned, muted, archived…).
 *
 * Built from my own participant row with `conversation.group`, `conversation.participants` and
 * `conversation.lastMessage` loaded, and the ParticipantDirectory primed (ConversationService does both).
 *
 * @property ChatParticipant $resource
 */
class ConversationResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $extra  e.g. presence / blocked flags on the single-chat screen
     */
    public function __construct(ChatParticipant $me, private readonly Model $viewer, private readonly array $extra = [])
    {
        parent::__construct($me);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $me = $this->resource;
        $conversation = $me->conversation;
        $directory = app(ParticipantDirectory::class);
        $isGroup = $conversation->isGroup();
        $group = $isGroup ? $conversation->group : null;
        $active = $conversation->participants->whereNull('left_at');
        $peer = $isGroup ? null : $active->first(fn (ChatParticipant $p) => $p->id !== $me->id);
        $peerProfile = $peer !== null ? $directory->profile($this->viewer, $peer->participant_type, $peer->participant_id) : null;
        // A message request I sent to someone I don't have saved (from a public story, say): their
        // number shows once they accept it (docs/remaining_chat.md ج.1).
        if ($peerProfile !== null && ! ($peerProfile['is_contact'] ?? false)
            && $conversation->status !== \Modules\Chat\Enums\ConversationStatus::Accepted
            && $conversation->created_by_type === $me->participant_type && (int) $conversation->created_by_id === (int) $me->participant_id) {
            $peerProfile['phone'] = null;
        }
        $isSelf = $conversation->isSelf();
        $selfProfile = $isSelf ? $directory->profile($this->viewer, $me->participant_type, $me->participant_id) : null;

        return [
            'id' => $conversation->uuid,
            'type' => $conversation->type->value,
            'status' => $conversation->status->value,
            // A request someone sent *me* — shown in the "message requests" box, not the chat list.
            'is_request' => $conversation->status === ConversationStatus::Pending && ! $this->startedByMe($me),
            // Note to self: named "Notes (you)", with my own picture; no peer, no calls.
            'is_self' => $isSelf,
            'title' => match (true) {
                $isGroup => $group?->name,
                $isSelf => __('chat.self_title'),
                default => $peerProfile['name'] ?? null,
            },
            'avatar' => match (true) {
                $isGroup => $group?->avatarUrl(),
                $isSelf => $selfProfile['avatar'] ?? null,
                default => $peerProfile['avatar'] ?? null,
            },
            'peer' => $peerProfile,
            'group' => $group === null ? null : [
                'name' => $group->name,
                'description' => $group->description,
                'avatar' => $group->avatarUrl(),
                // A channel counts its followers (they aren't loaded, see ConversationService::hydrate()).
                'members_count' => $conversation->isChannel() ? $conversation->activeParticipants()->count() : $active->count(),
                'only_admins_send' => $group->only_admins_send,
                'only_admins_edit_info' => $group->only_admins_edit_info,
                'only_admins_add_members' => $group->only_admins_add_members,
                'approve_joins' => $group->approve_joins,
                // Moderation: members wait this long between messages (0 = off); the banned
                // words list is only shown to the admins who manage it.
                'slow_mode_seconds' => (int) $group->slow_mode_seconds,
                'banned_words' => $me->isActive() && $me->isAdmin() ? array_values($group->banned_words ?? []) : null,
                // The admins' badge; members don't see who's waiting.
                'pending_join_requests' => $group->approve_joins && $me->isActive() && $me->isAdmin()
                    ? app(GroupService::class)->pendingJoinCount($conversation) : 0,
                // Channels: the @handle and whether anyone can find it in "Discover".
                'handle' => $group->handle,
                'is_public' => (bool) $group->is_public,
                // Channels: the ✔ (verified by Dorr, or a paid verification running) and the category.
                'is_verified' => $conversation->isChannel() && $group->isVerified(),
                'category' => $conversation->isChannel() ? $group->category?->loadMissing(['translations', 'media'])->brief() : null,
            ],
            'my_role' => $me->role->value,
            'is_member' => $me->isActive(),
            'can_send' => $this->canSend($me, $group),
            'last_message' => $this->lastMessage($me),
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'unread_count' => $me->unread_count,
            'marked_unread' => $me->marked_unread,
            'has_unread_mention' => $me->has_unread_mention,
            'is_pinned' => $me->pinned_at !== null,
            'is_archived' => $me->is_archived,
            'is_locked' => $me->is_locked,
            // A separate PIN for this chat (spec 25) — asked instead of the fingerprint.
            'has_lock_pin' => $me->lock_pin_hash !== null,
            // My privacy circle for it (only I know).
            'circle' => $me->privacy_circle_id ? (fn ($c) => $c ? ['id' => $c->uuid, 'name' => $c->name, 'shown_name' => $c->shownName(), 'emoji' => $c->emoji, 'locked' => $c->locked, 'hide_from_list' => $c->hide_from_list] : null)($me->privacyCircle) : null,
            'is_muted' => $me->isMuted(),
            'muted_until' => $me->muted_until?->toIso8601String(),
            'disappearing_seconds' => $conversation->disappearing_seconds,
            // My pick (theme_id / custom) and what to draw: the pick if still active, else the admin's default.
            'theme' => [
                'theme_id' => $me->theme_id,
                // My own wallpaper / colours / dim for this chat (only I see them), and what to
                // draw: that over the pick (or the default) — see ChatThemeService::appliedFor().
                'custom' => app(ChatThemeService::class)->presentCustom($me->custom_theme),
                'applied' => app(ChatThemeService::class)->appliedFor($me->theme_id, $me->custom_theme),
            ],
            'created_at' => $conversation->created_at?->toIso8601String(),
        ] + $this->extra;
    }

    private function startedByMe(ChatParticipant $me): bool
    {
        $c = $me->conversation;

        return $c->created_by_type === $me->participant_type && (int) $c->created_by_id === (int) $me->participant_id;
    }

    private function canSend(ChatParticipant $me, $group): bool
    {
        if (! $me->isActive() || $me->conversation->status === ConversationStatus::Rejected) {
            return false;
        }

        return $group === null || ! $group->only_admins_send || $me->isAdmin();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastMessage(ChatParticipant $me): ?array
    {
        $message = $me->conversation->lastMessage;

        if (! $message instanceof ChatMessage || ($me->cleared_before_message_id !== null && $message->id <= $me->cleared_before_message_id)) {
            return null;
        }

        $context = MessageViewContext::build($this->viewer, $me, $me->conversation, collect());
        $gone = $message->isGone();

        return [
            'id' => $message->uuid,
            'type' => $message->type->value,
            // A sensitive message never shows in the list.
            'body' => $gone || $message->is_sensitive || ($message->type === \Modules\Chat\Enums\MessageType::MomentCard && data_get($message->meta, 'card.reveal_at') && \Illuminate\Support\Carbon::parse(data_get($message->meta, 'card.reveal_at'))->isFuture())
                ? null : Str::limit((string) $message->body, 120),
            'is_sensitive' => (bool) $message->is_sensitive,
            'sender' => $context->profile($message->sender_type, $message->sender_id),
            'is_mine' => $context->isMine($message),
            'status' => $context->statusOf($message),
            'is_deleted' => $message->isDeletedForEveryone(),
            'system' => $message->sender_type === null ? ($message->meta['event'] ?? null) : null,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
