<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Enums\ConversationType;
use Modules\Chat\Enums\GroupKind;
use Modules\Chat\Enums\ParticipantRole;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroup;
use Modules\Chat\Models\ChatGroupAuditLog;
use Modules\Chat\Models\ChatGroupOwnershipTransfer;
use Modules\Chat\Models\ChatGroupSpace;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Models\ChatSetting;

/**
 * An educational / work group's life (docs/groups_tasks.md GR-005, GR-006):
 *
 * - Handing it over: the owner offers it to a member, who accepts or declines; until then nothing
 *   changes, and the owner can't leave a group that still has members.
 * - Archive: read only (nobody writes, joins or is added) until the owner opens it again.
 * - Delete: gone from everyone's list, but the owner can bring it back for `group_restore_days`;
 *   after that `chat:purge-deleted-groups` deletes it for good. Its terms / projects go and come
 *   back with it.
 *
 * Ordinary groups keep their old behaviour (instant hand-over, delete at once).
 * Every step is on the record (GR-010).
 */
class GroupLifecycleService
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
        private readonly ChatBroadcaster $broadcaster,
    ) {}

    public static function isKindGroup(ChatConversation $conversation): bool
    {
        return $conversation->type === ConversationType::Group && ($conversation->group?->kind ?? GroupKind::Normal) !== GroupKind::Normal;
    }

    /**
     * Nobody writes here: the group is archived, or it's a term / project that closed, or one
     * inside an archived group.
     */
    public static function readOnly(ChatConversation $conversation): bool
    {
        $group = $conversation->group;
        if ($group === null) {
            return false;
        }
        if ($group->archived_at !== null || $group->disabled_at !== null) {
            return true;
        }
        if ($group->space_of_conversation_id === null) {
            return false;
        }

        $space = ChatGroupSpace::query()->where('conversation_id', $conversation->id)->first();

        return $space !== null && ($space->closed_at !== null || ChatGroup::query()->where('conversation_id', $space->parent_conversation_id)
            ->where(fn ($q) => $q->whereNotNull('archived_at')->orWhereNotNull('disabled_at'))->exists());
    }

    public static function assertWritable(ChatConversation $conversation): void
    {
        if (self::readOnly($conversation)) {
            throw new ChatException('group_read_only', 423);
        }
    }

    // ------------------------------------------------------------------ GR-005: hand-over

    /** The owner offers the group to a member; the member hears about it (and gets a push). */
    public function offerOwnership(Model $me, ChatConversation $conversation, ChatParticipant $mine, ChatParticipant $target): ChatGroupOwnershipTransfer
    {
        if ($target->id === $mine->id) {
            throw new ChatException('owner_already', 422);
        }
        $this->assertEligible($target);

        return DB::transaction(function () use ($me, $conversation, $mine, $target) {
            $this->pending($conversation)?->update(['status' => ChatGroupOwnershipTransfer::CANCELLED, 'decided_at' => now()]);
            $offer = ChatGroupOwnershipTransfer::query()->create([
                'conversation_id' => $conversation->id,
                'from_participant_id' => $mine->id,
                'to_participant_id' => $target->id,
            ]);
            ChatGroupAuditLog::record($conversation, $me, 'ownership_offered', 'participant:'.$target->id);

            DB::afterCommit(fn () => $this->changed($conversation));
            app(GroupNotifier::class)->notify($conversation, [$target], 'ownership', 'ownership_offer', ['name' => (string) ($me->name ?? '')]);

            return $offer;
        });
    }

    /** The member says yes: they're the owner now, the old owner stays on as an admin. */
    public function acceptOwnership(Model $me, ChatConversation $conversation): ChatParticipant
    {
        $offer = $this->offerForMe($me, $conversation);
        $target = $offer->to;
        $from = $offer->from;

        if ($from === null || $from->left_at !== null || $from->role !== ParticipantRole::Owner) {
            $offer->update(['status' => ChatGroupOwnershipTransfer::CANCELLED, 'decided_at' => now()]);
            throw new ChatException('ownership_offer_not_found', 404);
        }
        $this->assertEligible($target);

        DB::transaction(function () use ($me, $conversation, $offer, $target, $from) {
            $offer->update(['status' => ChatGroupOwnershipTransfer::ACCEPTED, 'decided_at' => now()]);
            $target->update(['role' => ParticipantRole::Owner]);
            $from->update(['role' => ParticipantRole::Admin]);
            $this->messages->system($conversation, $me, 'owner_changed', [$target->key()]);
            ChatGroupAuditLog::record($conversation, $me, 'ownership_accepted', 'participant:'.$target->id, ['owner' => $from->id], ['owner' => $target->id]);
            DB::afterCommit(fn () => $this->changed($conversation));
        });

        return $target->refresh();
    }

    public function declineOwnership(Model $me, ChatConversation $conversation): void
    {
        $offer = $this->offerForMe($me, $conversation);
        $offer->update(['status' => ChatGroupOwnershipTransfer::DECLINED, 'decided_at' => now()]);
        ChatGroupAuditLog::record($conversation, $me, 'ownership_declined', 'participant:'.$offer->to_participant_id);
        $this->changed($conversation);
    }

    /** The owner takes the offer back. */
    public function cancelOwnership(Model $me, ChatConversation $conversation): void
    {
        $this->assertOwner($me, $conversation);
        $offer = $this->pending($conversation) ?? throw new ChatException('ownership_offer_not_found', 404);
        $offer->update(['status' => ChatGroupOwnershipTransfer::CANCELLED, 'decided_at' => now()]);
        ChatGroupAuditLog::record($conversation, $me, 'ownership_cancelled', 'participant:'.$offer->to_participant_id);
        $this->changed($conversation);
    }

    public function pending(ChatConversation $conversation): ?ChatGroupOwnershipTransfer
    {
        return ChatGroupOwnershipTransfer::query()->where('conversation_id', $conversation->id)
            ->where('status', ChatGroupOwnershipTransfer::PENDING)->latest('id')->first();
    }

    /**
     * The pending offer as the owner and the member it's for see it (nobody else does).
     *
     * @return array<string, mixed>|null
     */
    public function offerFor(ChatParticipant $me, ChatConversation $conversation): ?array
    {
        $offer = $this->pending($conversation);
        if ($offer === null || ! in_array($me->id, [$offer->from_participant_id, $offer->to_participant_id], true)) {
            return null;
        }

        return [
            'from_participant_id' => $offer->from_participant_id,
            'to_participant_id' => $offer->to_participant_id,
            'for_me' => $offer->to_participant_id === $me->id,
            'created_at' => $offer->created_at?->toIso8601String(),
        ];
    }

    /** A group that still has members can't lose its owner by leaving: hand it over first. */
    public function assertOwnerMayLeave(ChatConversation $conversation, ChatParticipant $participant): void
    {
        if ($participant->role === ParticipantRole::Owner && self::isKindGroup($conversation)
            && $conversation->activeParticipants()->where('id', '!=', $participant->id)->exists()) {
            throw new ChatException('owner_must_transfer', 409);
        }
    }

    // ------------------------------------------------------------------ GR-006: archive and delete

    public function archive(Model $me, ChatConversation $conversation, bool $archived): void
    {
        $this->assertKind($conversation);
        $this->assertOwner($me, $conversation);
        $group = $conversation->group;

        if (($group->archived_at !== null) === $archived) {
            return;
        }

        DB::transaction(function () use ($me, $conversation, $group, $archived) {
            $group->update(['archived_at' => $archived ? now() : null]);
            $this->messages->system($conversation, $me, $archived ? 'group_archived' : 'group_unarchived');
            ChatGroupAuditLog::record($conversation, $me, $archived ? 'group_archived' : 'group_unarchived');
            DB::afterCommit(fn () => $this->changed($conversation, withSpaces: true));
        });
    }

    /**
     * Deleted for everyone — but kept, members and all, until `purge_after`. Its terms / projects
     * go with it.
     */
    public function softDelete(Model $me, ChatConversation $conversation): void
    {
        $this->assertKind($conversation);
        $this->assertOwner($me, $conversation);
        $everyone = $this->audience($conversation);
        $days = max(1, (int) (ChatSetting::current()->group_restore_days ?? 30));

        DB::transaction(function () use ($me, $conversation, $days) {
            $conversation->group->update(['purge_after' => now()->addDays($days)]);
            ChatGroupAuditLog::record($conversation, $me, 'group_deleted', null, null, ['purge_after' => now()->addDays($days)->toIso8601String()]);
            ChatConversation::query()->whereIn('id', $this->spaceIds($conversation))->get()->each->delete();
            $conversation->delete();
        });

        $this->broadcaster->toParticipants($everyone, 'chat.conversation.updated', ['conversation_id' => $conversation->uuid]);
    }

    /** The owner brings a deleted group back (with its terms / projects) before it's purged. */
    public function restore(Model $me, ChatConversation $conversation): void
    {
        if (! $conversation->trashed() || $conversation->group?->purge_after === null || $conversation->group->purge_after->isPast()) {
            throw new ChatException('group_not_restorable', 404);
        }
        $this->assertOwner($me, $conversation);

        DB::transaction(function () use ($me, $conversation) {
            $conversation->restore();
            ChatConversation::onlyTrashed()->whereIn('id', $this->spaceIds($conversation))->get()->each->restore();
            $conversation->group->update(['purge_after' => null]);
            ChatGroupAuditLog::record($conversation, $me, 'group_restored');
        });

        $this->changed($conversation, withSpaces: true);
    }

    /**
     * The deleted groups I own that can still come back.
     *
     * @return list<array<string, mixed>>
     */
    public function deletedOwnedBy(Model $me): array
    {
        $mine = ChatParticipant::query()->of($me)->whereNull('left_at')->where('role', ParticipantRole::Owner->value)->select('conversation_id');

        return ChatConversation::onlyTrashed()->whereIn('id', $mine)
            ->whereHas('group', fn ($q) => $q->where('purge_after', '>', now()))
            ->with('group')->latest('deleted_at')->get()
            ->map(fn (ChatConversation $c) => [
                'id' => $c->uuid,
                'name' => $c->group->name,
                'kind' => $c->group->kind?->value,
                'deleted_at' => $c->deleted_at?->toIso8601String(),
                'purge_after' => $c->group->purge_after?->toIso8601String(),
            ])->all();
    }

    /** `chat:purge-deleted-groups`: gone for good once their time is up. */
    public function purgeExpired(): int
    {
        $count = 0;
        ChatConversation::onlyTrashed()->whereHas('group', fn ($q) => $q->whereNotNull('purge_after')->where('purge_after', '<=', now()))
            ->get()->each(function (ChatConversation $conversation) use (&$count) {
                ChatConversation::withTrashed()->whereIn('id', $this->spaceIds($conversation))->get()->each->forceDelete();
                $conversation->forceDelete();
                $count++;
            });

        return $count;
    }

    // ------------------------------------------------------------------ helpers

    private function assertKind(ChatConversation $conversation): void
    {
        if (! self::isKindGroup($conversation)) {
            throw new ChatException('group_kind_required', 422);
        }
    }

    private function assertOwner(Model $me, ChatConversation $conversation): ChatParticipant
    {
        $participant = ChatParticipant::query()->where('conversation_id', $conversation->id)->of($me)->whereNull('left_at')->first();

        if ($participant === null || $participant->role !== ParticipantRole::Owner) {
            throw new ChatException('owner_action_only', 403);
        }

        return $participant;
    }

    /** Still a member, and the account isn't stopped. */
    private function assertEligible(?ChatParticipant $target): void
    {
        $account = $target?->participant();
        $status = $account?->getAttribute('status');

        if ($target === null || $target->left_at !== null || $account === null
            || ($status instanceof \BackedEnum ? $status->value : ($status ?? 'active')) !== 'active') {
            throw new ChatException('ownership_candidate_unavailable', 422);
        }
    }

    private function offerForMe(Model $me, ChatConversation $conversation): ChatGroupOwnershipTransfer
    {
        $offer = $this->pending($conversation);
        $mine = $this->conversations->participantOf($me, $conversation, true);

        if ($offer === null || $offer->to_participant_id !== $mine->id) {
            throw new ChatException('ownership_offer_not_found', 404);
        }

        return $offer;
    }

    /** @return list<int> */
    private function spaceIds(ChatConversation $conversation): array
    {
        return ChatGroupSpace::query()->where('parent_conversation_id', $conversation->id)->pluck('conversation_id')->all();
    }

    /** @return \Illuminate\Support\Collection<int, ChatParticipant> */
    private function audience(ChatConversation $conversation)
    {
        return ChatParticipant::query()->whereIn('conversation_id', [$conversation->id, ...$this->spaceIds($conversation)])->whereNull('left_at')->get();
    }

    private function changed(ChatConversation $conversation, bool $withSpaces = false): void
    {
        $rows = $withSpaces ? $this->audience($conversation) : $conversation->activeParticipants()->get();
        $this->broadcaster->toParticipants($rows, 'chat.conversation.updated', ['conversation_id' => $conversation->uuid]);
    }
}
