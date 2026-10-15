<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Enums\ParticipantRole;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatCommunity;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroup;
use Modules\Chat\Models\ChatGroupJoinRequest;
use Modules\Chat\Models\ChatParticipant;

/**
 * Communities (WhatsApp list 93–98): related groups under one roof.
 *
 *  - Every community has an announcements group that only its admins write in (94). Its
 *    members ARE the community's members: whoever is in any of its groups is in it.
 *  - Members see all its groups in one place and hop into any of them — straight in, or asking
 *    first when that group approves new members (95).
 *  - Its admins (the announcements group's admins, up to 20 — 97) add and remove groups, make
 *    new ones, and remove a person from the community and all its groups at once (96).
 *  - Its creator (that group's owner) stays an admin: nobody removes or demotes them, and they
 *    can't leave — only delete the community (98).
 */
class CommunityService
{
    public const MAX_ADMINS = 20;

    public const MAX_GROUPS = 50;

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly GroupService $groups,
        private readonly MessageService $messages,
        private readonly ChatBroadcaster $broadcaster,
    ) {}

    /**
     * @param  list<string>  $groupIds  conversation ids of groups I run, joining it from the start
     */
    public function create(Model $me, string $name, ?string $description, array $groupIds, ?UploadedFile $avatar = null): ChatCommunity
    {
        $picked = $this->pickGroups($me, $groupIds);
        if ($picked->count() > self::MAX_GROUPS) {
            throw new ChatException('community_groups_full', 422, ['max' => self::MAX_GROUPS]);
        }

        return DB::transaction(function () use ($me, $name, $description, $picked, $avatar) {
            $announcement = $this->groups->create($me, $name, $description, [], $avatar)['participant']->conversation;
            $community = ChatCommunity::query()->create([
                'name' => $name,
                'description' => $description,
                'creator_type' => $announcement->created_by_type,
                'creator_id' => $announcement->created_by_id,
                'announcement_conversation_id' => $announcement->id,
            ]);
            // Only admins write in it, and nobody is added by hand: you're in by being in a group.
            $announcement->group->update(['community_id' => $community->id, 'only_admins_send' => true, 'only_admins_add_members' => true]);
            $this->messages->system($announcement, $me, 'community_created', [], ['name' => $name]);

            foreach ($picked as $conversation) {
                $this->link($me, $community, $conversation);
            }

            return $community;
        });
    }

    /** @return list<array<string, mixed>> */
    public function mine(Model $me): array
    {
        $rooms = ChatParticipant::query()->of($me)->whereNull('left_at')->pluck('conversation_id');

        return ChatCommunity::query()->whereIn('announcement_conversation_id', $rooms)->with('announcement.group')->latest('id')->get()
            ->filter(fn (ChatCommunity $c) => $c->announcement !== null)
            ->map(fn (ChatCommunity $c) => $this->brief($me, $c))->values()->all();
    }

    /** @return array<string, mixed> */
    public function show(Model $me, ChatCommunity $community): array
    {
        $mine = $this->memberRow($me, $community);
        $announcement = $community->announcement;
        $groups = ChatGroup::query()->where('community_id', $community->id)->where('conversation_id', '!=', $announcement->id)
            ->with('conversation')->orderBy('name')->get()->filter(fn (ChatGroup $g) => $g->conversation !== null);
        $myRooms = ChatParticipant::query()->of($me)->whereNull('left_at')->whereIn('conversation_id', $groups->pluck('conversation_id'))->pluck('conversation_id')->all();
        $asked = ChatGroupJoinRequest::query()->whereIn('conversation_id', $groups->pluck('conversation_id'))->by($me)->pending()->pluck('conversation_id')->all();

        return $this->brief($me, $community) + [
            'admins_count' => $announcement->activeParticipants()->whereIn('role', [ParticipantRole::Owner->value, ParticipantRole::Admin->value])->count(),
            'max_admins' => self::MAX_ADMINS,
            'max_groups' => self::MAX_GROUPS,
            'is_creator' => $mine->role === ParticipantRole::Owner,
            'groups' => $groups->map(fn (ChatGroup $g) => [
                'id' => $g->conversation->uuid,
                'name' => $g->name,
                'description' => $g->description,
                'avatar' => $g->avatarUrl(),
                'members_count' => $g->conversation->activeParticipants()->count(),
                'is_member' => in_array($g->conversation_id, $myRooms, true),
                'approve_joins' => (bool) $g->approve_joins,
                'request_pending' => in_array($g->conversation_id, $asked, true),
            ])->values()->all(),
        ];
    }

    /** @param  array{name?: string, description?: string|null}  $data */
    public function update(Model $me, ChatCommunity $community, array $data, ?UploadedFile $avatar = null): ChatCommunity
    {
        $this->assertAdmin($me, $community);
        $data = array_intersect_key($data, array_flip(['name', 'description']));
        DB::transaction(function () use ($me, $community, $data, $avatar) {
            $community->update($data);
            $this->groups->updateInfo($me, $community->announcement, $data, $avatar);
        });

        return $community->refresh();
    }

    /** @param  list<string>  $groupIds */
    public function addGroups(Model $me, ChatCommunity $community, array $groupIds): void
    {
        $this->assertAdmin($me, $community);
        $picked = $this->pickGroups($me, $groupIds);
        if ($this->groupCount($community) + $picked->count() > self::MAX_GROUPS) {
            throw new ChatException('community_groups_full', 422, ['max' => self::MAX_GROUPS]);
        }
        DB::transaction(fn () => $picked->each(fn (ChatConversation $c) => $this->link($me, $community, $c)));
    }

    /** A brand-new group inside the community, with me as its owner. */
    public function newGroup(Model $me, ChatCommunity $community, string $name, ?string $description = null): ChatConversation
    {
        $this->assertAdmin($me, $community);
        if ($this->groupCount($community) + 1 > self::MAX_GROUPS) {
            throw new ChatException('community_groups_full', 422, ['max' => self::MAX_GROUPS]);
        }

        return DB::transaction(function () use ($me, $community, $name, $description) {
            $conversation = $this->groups->create($me, $name, $description, [])['participant']->conversation;
            $this->link($me, $community, $conversation);

            return $conversation;
        });
    }

    public function removeGroup(Model $me, ChatCommunity $community, ChatConversation $conversation): void
    {
        $this->assertAdmin($me, $community);
        if ($conversation->id === $community->announcement_conversation_id) {
            throw new ChatException('community_announcement_fixed', 422);
        }
        if ((int) $conversation->group?->community_id !== $community->id) {
            throw ChatException::notParticipant();
        }
        DB::transaction(function () use ($me, $community, $conversation) {
            $conversation->group->update(['community_id' => null]);
            $this->messages->system($conversation, $me, 'group_removed_from_community', [], ['name' => $community->name]);
            $this->changed($conversation);
        });
    }

    /** A member hops into one of its groups — or asks to, when that group approves new members. */
    public function joinGroup(Model $me, ChatCommunity $community, ChatConversation $conversation): ChatParticipant|ChatGroupJoinRequest
    {
        $this->memberRow($me, $community);
        if ((int) $conversation->group?->community_id !== $community->id) {
            throw ChatException::notParticipant();
        }

        return $this->groups->enterFromCommunity($me, $conversation);
    }

    /** @return list<array<string, mixed>> */
    public function members(Model $me, ChatCommunity $community): array
    {
        $this->memberRow($me, $community);

        return $this->groups->members($me, $community->announcement);
    }

    /** Out of the community and every group in it at once (their own groups excepted). */
    public function removeMember(Model $me, ChatCommunity $community, int $participantId): void
    {
        $this->assertAdmin($me, $community);
        $announcement = $community->announcement;
        $target = $announcement->activeParticipants()->whereKey($participantId)->first() ?? throw ChatException::notParticipant();
        if ($target->role === ParticipantRole::Owner) {
            throw new ChatException('community_creator_stays', 422);
        }
        $account = $target->participant() ?? throw ChatException::userNotFound();

        DB::transaction(function () use ($me, $community, $announcement, $account, $participantId) {
            foreach ($this->linked($community) as $conversation) {
                $row = $conversation->activeParticipants()->of($account)->first();
                if ($row === null || $row->role === ParticipantRole::Owner) {
                    continue;
                }
                $row->update(['left_at' => now(), 'role' => ParticipantRole::Member, 'pinned_at' => null]);
                $this->messages->system($conversation, $me, 'member_removed', [$row->key()]);
                $this->changed($conversation, [$row]);
            }
            $this->groups->removeMember($me, $announcement, $participantId);
        });
    }

    /** Make someone a community admin (up to 20) or take it back. The creator can't be changed. */
    public function setRole(Model $me, ChatCommunity $community, int $participantId, ParticipantRole $role): ChatParticipant
    {
        $this->assertAdmin($me, $community);

        return $this->groups->setRole($me, $community->announcement, $participantId, $role);
    }

    /** Leave the community and all its groups. The creator can't — they delete it instead. */
    public function leave(Model $me, ChatCommunity $community): void
    {
        $mine = $this->memberRow($me, $community);
        if ($mine->role === ParticipantRole::Owner) {
            throw new ChatException('community_creator_stays', 422);
        }
        DB::transaction(function () use ($me, $community) {
            foreach ($this->linked($community) as $conversation) {
                if ($conversation->activeParticipants()->of($me)->exists()) {
                    $this->groups->leave($me, $conversation);
                }
            }
            $this->groups->leave($me, $community->announcement);
        });
    }

    /** The creator closes it: its groups go on as ordinary groups, the announcements group is deleted. */
    public function delete(Model $me, ChatCommunity $community): void
    {
        $mine = $this->memberRow($me, $community);
        if ($mine->role !== ParticipantRole::Owner) {
            throw new ChatException('owner_action_only', 403);
        }
        DB::transaction(function () use ($me, $community) {
            foreach ($this->linked($community) as $conversation) {
                $conversation->group->update(['community_id' => null]);
                $this->messages->system($conversation, $me, 'group_removed_from_community', [], ['name' => $community->name]);
                $this->changed($conversation);
            }
            $announcement = $community->announcement;
            $community->delete();
            $announcement->group->update(['community_id' => null]);
            $this->groups->deleteForEveryone($me, $announcement);
        });
    }

    /**
     * Someone came into a group (added, by link, approved…): they're in its community too — they
     * get its announcements from now on.
     */
    public function joinedGroup(ChatConversation $conversation, Model $account): void
    {
        $communityId = $conversation->group?->community_id;
        if ($communityId === null) {
            return;
        }
        $community = ChatCommunity::query()->find($communityId);
        if ($community === null || $community->announcement_conversation_id === $conversation->id) {
            return;
        }
        $this->addToAnnouncement($community, $account);
    }

    /** Whether this conversation is a community's announcements group (still running). */
    public static function isAnnouncement(ChatConversation $conversation): bool
    {
        return ChatCommunity::query()->where('announcement_conversation_id', $conversation->id)->exists();
    }

    /** @return array{id: string, name: string, is_announcement: bool}|null */
    public static function badge(?ChatGroup $group): ?array
    {
        if ($group?->community_id === null) {
            return null;
        }
        $community = ChatCommunity::query()->find($group->community_id);

        return $community === null ? null : [
            'id' => $community->uuid,
            'name' => $community->name,
            'is_announcement' => $community->announcement_conversation_id === $group->conversation_id,
        ];
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function brief(Model $me, ChatCommunity $community): array
    {
        $announcement = $community->announcement;
        $mine = $announcement->activeParticipants()->of($me)->first();

        return [
            'id' => $community->uuid,
            'name' => $community->name,
            'description' => $community->description,
            'avatar' => $announcement->group?->avatarUrl(),
            'announcement_id' => $announcement->uuid,
            'members_count' => $announcement->activeParticipants()->count(),
            'groups_count' => $this->groupCount($community),
            'my_role' => $mine?->role->value,
            'is_admin' => (bool) $mine?->isAdmin(),
        ];
    }

    private function link(Model $me, ChatCommunity $community, ChatConversation $conversation): void
    {
        $conversation->group->update(['community_id' => $community->id]);
        $this->messages->system($conversation, $me, 'group_added_to_community', [], ['name' => $community->name]);
        foreach ($conversation->activeParticipants()->get() as $row) {
            if (($account = $row->participant()) !== null) {
                $this->addToAnnouncement($community, $account);
            }
        }
        $this->changed($conversation);
        $this->changed($community->announcement);
    }

    private function addToAnnouncement(ChatCommunity $community, Model $account): void
    {
        $announcement = $community->announcement;
        if ($announcement === null || $announcement->activeParticipants()->of($account)->exists()) {
            return;
        }
        $this->conversations->addParticipant($announcement, $account, ParticipantRole::Member, (int) $announcement->last_message_id ?: null);
    }

    /** @return Collection<int, ChatConversation> */
    private function linked(ChatCommunity $community): Collection
    {
        return ChatConversation::query()
            ->whereIn('id', ChatGroup::query()->where('community_id', $community->id)->where('conversation_id', '!=', $community->announcement_conversation_id)->select('conversation_id'))
            ->with('group')->get();
    }

    private function groupCount(ChatCommunity $community): int
    {
        return $this->linked($community)->count();
    }

    /**
     * Groups I run, each in no community yet.
     *
     * @param  list<string>  $ids
     * @return Collection<int, ChatConversation>
     */
    private function pickGroups(Model $me, array $ids): Collection
    {
        $picked = ChatConversation::query()->whereIn('uuid', array_values(array_unique($ids)))->with('group')->get();
        if ($picked->count() !== count(array_unique($ids))) {
            throw ChatException::notParticipant();
        }
        foreach ($picked as $conversation) {
            if (! $conversation->isGroup() || $conversation->isChannel()) {
                throw ChatException::groupOnly();
            }
            $row = $this->conversations->participantOf($me, $conversation, true);
            if (! $row->isAdmin()) {
                throw ChatException::adminsOnly();
            }
            if ($conversation->group->community_id !== null) {
                throw new ChatException('community_group_taken', 422);
            }
        }

        return $picked;
    }

    private function memberRow(Model $me, ChatCommunity $community): ChatParticipant
    {
        return $community->announcement?->activeParticipants()->of($me)->first() ?? throw ChatException::notParticipant();
    }

    private function assertAdmin(Model $me, ChatCommunity $community): ChatParticipant
    {
        $row = $this->memberRow($me, $community);
        if (! $row->isAdmin()) {
            throw ChatException::adminsOnly();
        }

        return $row;
    }

    /** @param  list<ChatParticipant>  $alsoNotify */
    private function changed(ChatConversation $conversation, array $alsoNotify = []): void
    {
        $this->broadcaster->toParticipants($conversation->activeParticipants()->get()->concat($alsoNotify), 'chat.conversation.updated', ['conversation_id' => $conversation->uuid]);
    }
}
