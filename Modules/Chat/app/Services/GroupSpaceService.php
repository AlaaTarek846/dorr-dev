<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Chat\Enums\ConversationStatus;
use Modules\Chat\Enums\ConversationType;
use Modules\Chat\Enums\GroupKind;
use Modules\Chat\Enums\ParticipantRole;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroup;
use Modules\Chat\Models\ChatGroupAuditLog;
use Modules\Chat\Models\ChatGroupSpace;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Support\GroupPermissions;
use Modules\Chat\Support\ParticipantType;

/**
 * Terms and subgroups (educational) and projects (work) inside a group — docs/groups_tasks.md
 * GR-007, decision D2. Each is its own group chat with its own members, every one of them a member
 * of the main group: nobody gets in on their own (not new members of the main group, not by a
 * link), and leaving or being removed from the main group takes them out of all of them. A closed
 * space is read only. Managing spaces takes `spaces.manage`.
 */
class GroupSpaceService
{
    /** Which spaces each kind has. */
    public const TYPES = [
        'education' => ['term', 'subgroup'],
        'work' => ['project'],
    ];

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
        private readonly ChatBroadcaster $broadcaster,
    ) {}

    /**
     * The spaces I'm in — every one for those who manage spaces.
     *
     * @return list<array<string, mixed>>
     */
    public function list(Model $me, ChatConversation $parent): array
    {
        $this->assertKindGroup($parent);
        $mine = $this->conversations->participantOf($me, $parent, true);
        $manager = in_array('spaces.manage', GroupPermissions::ofParticipant($mine, $parent), true);

        $spaces = ChatGroupSpace::query()->where('parent_conversation_id', $parent->id)
            ->whereHas('conversation')->with('conversation.group')
            ->orderBy('sort_order')->orderBy('id')->get();
        $memberOf = ChatParticipant::query()->of($me)->whereNull('left_at')->whereIn('conversation_id', $spaces->pluck('conversation_id'))->pluck('conversation_id')->all();

        return $spaces
            ->filter(fn (ChatGroupSpace $s) => $manager || in_array($s->conversation_id, $memberOf, true))
            ->map(fn (ChatGroupSpace $s) => $this->present($s, in_array($s->conversation_id, $memberOf, true)))
            ->values()->all();
    }

    /**
     * @param  array{type: string, name: string, starts_on?: ?string, ends_on?: ?string, members?: list<int>}  $data  members: participant ids in the main group
     * @return array<string, mixed>
     */
    public function create(Model $me, ChatConversation $parent, array $data): array
    {
        $kind = $this->assertKindGroup($parent);
        $mine = $this->assertManager($me, $parent);
        GroupLifecycleService::assertWritable($parent);

        if (! in_array($data['type'], self::TYPES[$kind->value], true)) {
            throw new ChatException('space_type_invalid', 422);
        }
        $members = $this->parentMembers($parent, (array) ($data['members'] ?? []));

        $space = DB::transaction(function () use ($me, $parent, $data, $mine, $members) {
            $conversation = ChatConversation::query()->create([
                'type' => ConversationType::Group,
                'status' => ConversationStatus::Accepted,
                'created_by_type' => ParticipantType::aliasFor($me),
                'created_by_id' => $me->getKey(),
                'disappearing_seconds' => $parent->disappearing_seconds,
            ]);
            ChatGroup::query()->create([
                'conversation_id' => $conversation->id,
                'name' => trim($data['name']),
                'invite_token' => Str::random(24),
                // Only managers add people; nobody joins by a link.
                'only_admins_add_members' => true,
                'space_of_conversation_id' => $parent->id,
            ]);
            $space = ChatGroupSpace::query()->create([
                'parent_conversation_id' => $parent->id,
                'conversation_id' => $conversation->id,
                'type' => $data['type'],
                'starts_on' => $data['starts_on'] ?? null,
                'ends_on' => $data['ends_on'] ?? null,
                'sort_order' => (int) ChatGroupSpace::query()->where('parent_conversation_id', $parent->id)->max('sort_order') + 1,
            ]);

            // The main group's owner owns it too; whoever made it runs it.
            $owner = $parent->activeParticipants()->where('role', ParticipantRole::Owner->value)->first();
            if ($owner !== null && $owner->participant() !== null) {
                $this->conversations->addParticipant($conversation, $owner->participant(), ParticipantRole::Owner);
            }
            if ($owner?->id !== $mine->id) {
                $this->conversations->addParticipant($conversation, $me, ParticipantRole::Admin);
            }
            $this->messages->system($conversation, $me, 'group_created', [], ['name' => trim($data['name'])]);
            foreach ($members as $member) {
                $this->join($conversation, $member);
            }
            app(GroupNotifier::class)->notify($parent, $members, 'spaces', 'space_added', ['space' => trim($data['name'])], ['space_conversation_id' => $conversation->uuid]);
            ChatGroupAuditLog::record($parent, $me, 'space_created', 'space:'.$space->id, null, ['type' => $space->type, 'name' => trim($data['name'])]);

            return $space;
        });

        $this->changed($parent, $space);

        return $this->present($space->load('conversation.group'), true);
    }

    /**
     * @param  list<int>  $participantIds  in the main group
     * @return array<string, mixed>
     */
    public function addMembers(Model $me, ChatConversation $parent, int $spaceId, array $participantIds): array
    {
        $this->assertKindGroup($parent);
        $this->assertManager($me, $parent);
        $space = $this->space($parent, $spaceId);
        GroupLifecycleService::assertWritable($space->conversation);
        $members = $this->parentMembers($parent, $participantIds);

        DB::transaction(function () use ($me, $parent, $space, $members) {
            foreach ($members as $member) {
                $this->join($space->conversation, $member, $me);
            }
            app(GroupNotifier::class)->notify($parent, $members, 'spaces', 'space_added', ['space' => (string) $space->conversation->group?->name], ['space_conversation_id' => $space->conversation->uuid]);
            ChatGroupAuditLog::record($parent, $me, 'space_members_added', 'space:'.$space->id, null, ['participants' => array_map(fn ($p) => $p->id, $members)]);
        });
        $this->changed($parent, $space);

        return $this->present($space->refresh()->load('conversation.group'), true);
    }

    /** Out of the space (they stay in the main group); its owner stays. */
    public function removeMember(Model $me, ChatConversation $parent, int $spaceId, int $parentParticipantId): void
    {
        $this->assertKindGroup($parent);
        $this->assertManager($me, $parent);
        $space = $this->space($parent, $spaceId);
        $member = $parent->participants()->find($parentParticipantId) ?? throw new ChatException('not_a_member', 404);
        $row = $space->conversation->activeParticipants()->where('participant_type', $member->participant_type)->where('participant_id', $member->participant_id)->first()
            ?? throw new ChatException('not_a_member', 404);

        if ($row->role === ParticipantRole::Owner) {
            throw ChatException::ownerOnly();
        }

        DB::transaction(function () use ($me, $parent, $space, $row) {
            $row->update(['left_at' => now(), 'role' => ParticipantRole::Member, 'pinned_at' => null]);
            $this->messages->system($space->conversation, $me, 'member_removed', [$row->key()]);
            ChatGroupAuditLog::record($parent, $me, 'space_member_removed', 'space:'.$space->id, null, ['participant' => $row->id]);
        });
        $this->changed($parent, $space, [$row]);
    }

    /** Closed: read only (a term that ended, a project done) — or open again. */
    public function close(Model $me, ChatConversation $parent, int $spaceId, bool $closed): array
    {
        $this->assertKindGroup($parent);
        $this->assertManager($me, $parent);
        $space = $this->space($parent, $spaceId);

        if (($space->closed_at !== null) !== $closed) {
            DB::transaction(function () use ($me, $parent, $space, $closed) {
                $space->update(['closed_at' => $closed ? now() : null]);
                $this->messages->system($space->conversation, $me, $closed ? 'space_closed' : 'space_reopened');
                ChatGroupAuditLog::record($parent, $me, $closed ? 'space_closed' : 'space_reopened', 'space:'.$space->id);
            });
            $this->changed($parent, $space);
        }

        return $this->present($space->refresh()->load('conversation.group'), true);
    }

    /**
     * GR-012: a new term after this one — the old one closes (read only), the chosen members of the
     * main group are in the new one. Nothing else moves: not the chat, not grades.
     *
     * @param  array{name: string, starts_on?: ?string, ends_on?: ?string, members?: list<int>}  $data
     * @return array<string, mixed>
     */
    public function nextTerm(Model $me, ChatConversation $parent, int $spaceId, array $data): array
    {
        $this->assertKindGroup($parent);
        $this->assertManager($me, $parent);
        $old = $this->space($parent, $spaceId);
        if ($old->type !== 'term') {
            throw new ChatException('space_type_invalid', 422);
        }

        $new = $this->create($me, $parent, ['type' => 'term'] + $data);
        if ($old->closed_at === null) {
            $this->close($me, $parent, $old->id, true);
        }
        ChatGroupAuditLog::record($parent, $me, 'term_rolled_over', 'space:'.$old->id, null, ['new_space' => $new['id'], 'members' => count((array) ($data['members'] ?? []))]);

        return $new;
    }

    /**
     * The members of a space, as main-group participant ids (to pick who moves to the next term).
     *
     * @return list<int>
     */
    public function memberIds(Model $me, ChatConversation $parent, int $spaceId): array
    {
        $this->assertKindGroup($parent);
        $this->assertManager($me, $parent);
        $space = $this->space($parent, $spaceId);
        $inSpace = $space->conversation->activeParticipants()->get(['participant_type', 'participant_id'])
            ->map(fn ($p) => $p->participant_type.':'.$p->participant_id)->all();

        return $parent->activeParticipants()->get()->filter(fn ($p) => in_array($p->participant_type.':'.$p->participant_id, $inSpace, true))->pluck('id')->values()->all();
    }

    /** Out of the main group = out of every space in it, at once (spec §09). */
    public function leftParent(ChatConversation $parent, ChatParticipant $participant): void
    {
        $ids = ChatGroupSpace::query()->where('parent_conversation_id', $parent->id)->pluck('conversation_id');
        if ($ids->isEmpty()) {
            return;
        }

        $rows = ChatParticipant::query()->whereIn('conversation_id', $ids)->whereNull('left_at')
            ->where('participant_type', $participant->participant_type)->where('participant_id', $participant->participant_id)->get();
        foreach ($rows as $row) {
            $row->update(['left_at' => now(), 'role' => ParticipantRole::Member, 'pinned_at' => null]);
        }
        if ($rows->isNotEmpty()) {
            $this->broadcaster->toParticipants($rows, 'chat.conversation.updated', ['conversation_id' => $parent->uuid]);
        }
    }

    /** The space this chat is, if it's one (for the conversation's `space` badge). */
    public static function spaceOf(ChatConversation $conversation): ?ChatGroupSpace
    {
        if ($conversation->group?->space_of_conversation_id === null) {
            return null;
        }

        return ChatGroupSpace::query()->where('conversation_id', $conversation->id)->with('parent.group')->first();
    }

    /** No links, no joining on your own, nobody from outside the main group. */
    public static function assertNotSpace(ChatConversation $conversation): void
    {
        if ($conversation->group?->space_of_conversation_id !== null) {
            throw new ChatException('space_members_from_group', 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ChatGroupSpace $space, bool $isMember): array
    {
        $conversation = $space->conversation;

        return [
            'id' => $space->id,
            'conversation_id' => $conversation?->uuid,
            'type' => $space->type,
            'name' => $conversation?->group?->name,
            'starts_on' => $space->starts_on?->toDateString(),
            'ends_on' => $space->ends_on?->toDateString(),
            'is_closed' => $space->closed_at !== null,
            'is_member' => $isMember,
            'members_count' => $conversation?->activeParticipants()->count() ?? 0,
        ];
    }

    // ------------------------------------------------------------------ helpers

    private function join(ChatConversation $conversation, ChatParticipant $parentMember, ?Model $by = null): void
    {
        $account = $parentMember->participant();
        if ($account === null || $conversation->activeParticipants()->of($account)->exists()) {
            return;
        }

        // Joining later: no history from before (the space's earlier talk isn't theirs).
        $this->conversations->addParticipant($conversation, $account, ParticipantRole::Member, $by ? ((int) $conversation->last_message_id ?: null) : null);
        if ($by !== null) {
            $this->messages->system($conversation, $by, 'member_added', [$parentMember->key()]);
        }
    }

    /**
     * @param  list<int>  $participantIds
     * @return list<ChatParticipant>
     */
    private function parentMembers(ChatConversation $parent, array $participantIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $participantIds)));
        $rows = $parent->activeParticipants()->whereIn('id', $ids)->get();

        if ($rows->count() !== count($ids)) {
            throw new ChatException('space_members_from_group', 422);
        }

        return $rows->all();
    }

    private function assertKindGroup(ChatConversation $parent): GroupKind
    {
        if (! GroupLifecycleService::isKindGroup($parent)) {
            throw new ChatException('group_kind_required', 422);
        }

        return $parent->group->kind;
    }

    private function assertManager(Model $me, ChatConversation $parent): ChatParticipant
    {
        $mine = $this->conversations->participantOf($me, $parent, true);

        if (! in_array('spaces.manage', GroupPermissions::ofParticipant($mine, $parent), true)) {
            throw new ChatException('group_permission_denied', 403);
        }

        return $mine;
    }

    private function space(ChatConversation $parent, int $spaceId): ChatGroupSpace
    {
        return ChatGroupSpace::query()->where('parent_conversation_id', $parent->id)->whereHas('conversation')
            ->with('conversation.group')->find($spaceId) ?? throw new ChatException('space_not_found', 404);
    }

    /**
     * @param  list<ChatParticipant>  $alsoNotify
     */
    private function changed(ChatConversation $parent, ChatGroupSpace $space, array $alsoNotify = []): void
    {
        $rows = ChatParticipant::query()->whereIn('conversation_id', [$parent->id, $space->conversation_id])->whereNull('left_at')->get()->concat($alsoNotify);
        $this->broadcaster->toParticipants($rows, 'chat.conversation.updated', ['conversation_id' => $space->conversation?->uuid ?? $parent->uuid]);
        $this->broadcaster->toParticipants($rows, 'chat.conversation.updated', ['conversation_id' => $parent->uuid]);
    }
}
