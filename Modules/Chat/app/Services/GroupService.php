<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Chat\Enums\ConversationStatus;
use Modules\Chat\Enums\ConversationType;
use Modules\Chat\Enums\ParticipantRole;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroup;
use Modules\Chat\Models\ChatGroupJoinRequest;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Support\ParticipantDirectory;
use Modules\Chat\Support\ParticipantType;

/**
 * Groups: create, edit info and settings, members and roles, leaving (the owner hands over),
 * and invite links. Every change leaves a system line in the chat and a real-time
 * `chat.conversation.updated` so the open screens refresh.
 *
 * Roles (unlike LeeTaxi, actually enforced): the owner can do everything and can't be removed;
 * admins manage members and (by default) the group info; members can add people only when
 * `only_admins_add_members` is off.
 */
class GroupService
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
        private readonly ChatPrivacy $privacy,
        private readonly ChatBroadcaster $broadcaster,
    ) {}

    /**
     * @param  list<Model>  $members
     * @return array{participant: ChatParticipant, not_added: list<string>}
     */
    public function create(Model $me, string $name, ?string $description, array $members, ?UploadedFile $avatar = null, ?int $disappearingSeconds = null): array
    {
        $max = ChatSetting::current()->max_group_members;

        if (count($members) + 1 > $max) {
            throw ChatException::groupFull($max);
        }

        return DB::transaction(function () use ($me, $name, $description, $members, $avatar, $disappearingSeconds) {
            $conversation = ChatConversation::query()->create([
                'type' => ConversationType::Group,
                'status' => ConversationStatus::Accepted,
                'created_by_type' => ParticipantType::aliasFor($me),
                'created_by_id' => $me->getKey(),
                'disappearing_seconds' => $disappearingSeconds,
            ]);

            $group = ChatGroup::query()->create([
                'conversation_id' => $conversation->id,
                'name' => $name,
                'description' => $description,
                'invite_token' => Str::random(24),
            ]);

            if ($avatar !== null) {
                $group->setSingleMedia('avatar', $avatar);
            }

            $mine = $this->conversations->addParticipant($conversation, $me, ParticipantRole::Owner);
            $this->messages->system($conversation, $me, 'group_created', [], ['name' => $name]);

            // Founding members see the group from its first line.
            $result = $this->addAccounts($me, $conversation, $members, seeHistory: true);

            return ['participant' => $mine->refresh(), 'not_added' => $result['not_added']];
        });
    }

    public function updateInfo(Model $me, ChatConversation $conversation, array $data, ?UploadedFile $avatar, bool $removeAvatar = false): ChatGroup
    {
        $participant = $this->assertGroupMember($me, $conversation);
        $group = $conversation->group;

        if ($group->only_admins_edit_info && ! $participant->isAdmin()) {
            throw ChatException::adminsOnly();
        }

        DB::transaction(function () use ($me, $conversation, $group, $data, $avatar, $removeAvatar) {
            $old = $group->only(['name', 'description']);
            $group->update(array_intersect_key($data, array_flip(['name', 'description'])));

            if ($avatar !== null) {
                $group->setSingleMedia('avatar', $avatar);
                $this->messages->system($conversation, $me, 'group_photo_changed');
            } elseif ($removeAvatar) {
                $group->clearMediaCollection('avatar');
                $this->messages->system($conversation, $me, 'group_photo_removed');
            }

            if (isset($data['name']) && $data['name'] !== $old['name']) {
                $this->messages->system($conversation, $me, 'group_renamed', [], ['name' => $data['name']]);
            }
            if (array_key_exists('description', $data) && $data['description'] !== $old['description']) {
                $this->messages->system($conversation, $me, 'group_description_changed');
            }

            $this->changed($conversation);
        });

        return $group->refresh();
    }

    /**
     * only_admins_send / only_admins_edit_info / only_admins_add_members — admins only.
     */
    public function updateSettings(Model $me, ChatConversation $conversation, array $data): ChatGroup
    {
        $this->assertAdmin($me, $conversation);
        $group = $conversation->group;

        DB::transaction(function () use ($me, $conversation, $group, $data) {
            $data = array_intersect_key($data, array_flip(['only_admins_send', 'only_admins_edit_info', 'only_admins_add_members', 'approve_joins', 'is_public']));
            if ($conversation->isChannel()) {
                unset($data['only_admins_send'], $data['only_admins_add_members']); // a channel is always "admins post"
            } else {
                unset($data['is_public']);
            }
            $before = $group->only_admins_send;
            $approveBefore = $group->approve_joins;
            $group->update($data);

            if (array_key_exists('only_admins_send', $data) && (bool) $data['only_admins_send'] !== $before) {
                $this->messages->system($conversation, $me, $data['only_admins_send'] ? 'group_announcement_on' : 'group_announcement_off');
            }
            if (array_key_exists('approve_joins', $data) && (bool) $data['approve_joins'] !== $approveBefore) {
                $this->messages->system($conversation, $me, $data['approve_joins'] ? 'group_approval_on' : 'group_approval_off');
            }

            $this->changed($conversation);
        });

        return $group->refresh();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function members(Model $me, ChatConversation $conversation): array
    {
        // Followers of a channel don't see each other; its admins do.
        if ($conversation->isChannel()) {
            $this->assertAdmin($me, $conversation);
        }
        $this->conversations->participantOf($me, $conversation);
        $directory = app(ParticipantDirectory::class);

        $rows = $conversation->activeParticipants()->get()
            ->sortBy(fn (ChatParticipant $p) => [match ($p->role) {
                ParticipantRole::Owner => 0, ParticipantRole::Admin => 1, default => 2
            }, $p->joined_at])
            ->values();

        $directory->prime($me, $rows->map(fn ($p) => [$p->participant_type, $p->participant_id]));

        return $rows->map(fn (ChatParticipant $p) => [
            'participant_id' => $p->id,
            'role' => $p->role->value,
            'joined_at' => $p->joined_at?->toIso8601String(),
            'profile' => $directory->profile($me, $p->participant_type, $p->participant_id),
        ])->all();
    }

    /**
     * @param  list<Model>  $accounts
     * @return array{added: list<string>, not_added: list<string>}
     */
    public function addMembers(Model $me, ChatConversation $conversation, array $accounts): array
    {
        $participant = $this->assertGroupMember($me, $conversation);

        // Nobody is added to a channel: people follow it (search, link or QR).
        if ($conversation->isChannel()) {
            throw new ChatException('channel_follow_only', 422);
        }

        if ($conversation->group->only_admins_add_members && ! $participant->isAdmin()) {
            throw ChatException::adminsOnly();
        }

        return DB::transaction(fn () => $this->addAccounts($me, $conversation, $accounts));
    }

    public function removeMember(Model $me, ChatConversation $conversation, int $participantId): void
    {
        $this->assertAdmin($me, $conversation);
        $target = $this->activeMember($conversation, $participantId);

        if ($target->role === ParticipantRole::Owner) {
            throw ChatException::ownerOnly();
        }

        DB::transaction(function () use ($me, $conversation, $target) {
            $target->update(['left_at' => now(), 'role' => ParticipantRole::Member, 'pinned_at' => null]);
            $this->messages->system($conversation, $me, 'member_removed', [$target->key()]);
            $this->changed($conversation, [$target]);
        });
    }

    public function setRole(Model $me, ChatConversation $conversation, int $participantId, ParticipantRole $role): ChatParticipant
    {
        $this->assertAdmin($me, $conversation);
        $target = $this->activeMember($conversation, $participantId);

        if ($role === ParticipantRole::Owner || $target->role === ParticipantRole::Owner) {
            throw ChatException::ownerOnly();
        }

        if ($target->role !== $role) {
            DB::transaction(function () use ($me, $conversation, $target, $role) {
                $target->update(['role' => $role]);
                $this->messages->system($conversation, $me, $role === ParticipantRole::Admin ? 'admin_added' : 'admin_removed', [$target->key()]);
                $this->changed($conversation);
            });
        }

        return $target->refresh();
    }

    /**
     * Leaving as the owner hands the group to the longest-serving admin (or member). The last
     * person out closes the group.
     */
    public function leave(Model $me, ChatConversation $conversation): void
    {
        $participant = $this->assertGroupMember($me, $conversation);

        DB::transaction(function () use ($me, $conversation, $participant) {
            $wasOwner = $participant->role === ParticipantRole::Owner;
            $wasStaff = $participant->isAdmin();
            $participant->update(['left_at' => now(), 'role' => ParticipantRole::Member, 'pinned_at' => null]);
            // A follower unfollowing a channel is not news; in a group everyone sees who left.
            if (! $conversation->isChannel() || $wasStaff) {
                $this->messages->system($conversation, $me, 'member_left');
            }

            $remaining = $conversation->activeParticipants()->get();

            if ($remaining->isEmpty()) {
                $conversation->delete();

                return;
            }

            if ($wasOwner) {
                $heir = $remaining->where('role', ParticipantRole::Admin)->sortBy('joined_at')->first()
                    ?? $remaining->sortBy('joined_at')->first();
                $heir->update(['role' => ParticipantRole::Owner]);
                $this->messages->system($conversation, null, 'owner_changed', [$heir->key()]);
            }

            $this->changed($conversation, [$participant]);
        });
    }

    /**
     * @return array{token: string, link: string}
     */
    public function inviteLink(Model $me, ChatConversation $conversation, bool $reset = false): array
    {
        $this->assertAdmin($me, $conversation);
        $group = $conversation->group;

        if ($reset || $group->invite_token === null) {
            $group->update(['invite_token' => Str::random(24)]);
        }

        return ['token' => $group->invite_token, 'link' => 'dorr://chat/join/'.$group->invite_token];
    }

    /**
     * What someone sees before tapping "Join" on an invite link.
     *
     * @return array<string, mixed>
     */
    public function previewInvite(Model $me, string $token): array
    {
        $group = $this->groupByInvite($token);
        $conversation = $group->conversation;

        return [
            'conversation_id' => $conversation->uuid,
            'name' => $group->name,
            'description' => $group->description,
            'avatar' => $group->avatarUrl(),
            'members_count' => $conversation->activeParticipants()->count(),
            'is_member' => $conversation->activeParticipants()->of($me)->exists(),
            // The admins approve new members: "Join" becomes "Ask to join".
            'approve_joins' => $group->approve_joins,
            'request_status' => ChatGroupJoinRequest::query()->where('conversation_id', $conversation->id)->by($me)->pending()->exists() ? 'pending' : null,
        ];
    }

    /**
     * Join through the invite link — or, when the admins approve new members, ask to join
     * (returns the pending request; asking twice keeps the one request).
     */
    public function join(Model $me, string $token): ChatParticipant|ChatGroupJoinRequest
    {
        $group = $this->groupByInvite($token);
        $conversation = $group->conversation;

        $existing = $conversation->activeParticipants()->of($me)->first();
        if ($existing !== null) {
            return $existing;
        }

        // A channel has followers, not members: no group size limit.
        if (! $conversation->isChannel()) {
            $this->assertRoom($conversation);
        }

        if ($group->approve_joins) {
            $request = ChatGroupJoinRequest::query()->where('conversation_id', $conversation->id)->by($me)->pending()->first()
                ?? ChatGroupJoinRequest::query()->create([
                    'conversation_id' => $conversation->id,
                    'requester_type' => ParticipantType::aliasFor($me),
                    'requester_id' => $me->getKey(),
                ]);

            if ($request->wasRecentlyCreated) {
                $this->tellAdmins($conversation);
            }

            return $request;
        }

        if ($conversation->isChannel()) {
            return app(ChannelService::class)->follow($me, $conversation);
        }

        return DB::transaction(function () use ($me, $conversation) {
            $row = $this->conversations->addParticipant($conversation, $me, ParticipantRole::Member, (int) $conversation->last_message_id ?: null);
            $this->messages->system($conversation, $me, 'member_joined_via_link');
            $this->changed($conversation);

            return $row;
        });
    }

    /**
     * I changed my mind before an admin answered.
     */
    public function cancelJoinRequest(Model $me, string $token): void
    {
        $conversation = $this->groupByInvite($token)->conversation;

        ChatGroupJoinRequest::query()->where('conversation_id', $conversation->id)->by($me)->pending()
            ->update(['status' => ChatGroupJoinRequest::CANCELLED, 'decided_at' => now()]);
        $this->tellAdmins($conversation);
    }

    /**
     * Pending requests, oldest first — admins only.
     *
     * @return list<array<string, mixed>>
     */
    public function joinRequests(Model $me, ChatConversation $conversation): array
    {
        $this->assertAdmin($me, $conversation);
        $rows = ChatGroupJoinRequest::query()->where('conversation_id', $conversation->id)->pending()->orderBy('id')->get();
        $directory = app(ParticipantDirectory::class);
        $directory->prime($me, $rows->map(fn ($r) => [$r->requester_type, $r->requester_id]));

        return $rows->map(fn (ChatGroupJoinRequest $r) => [
            'id' => $r->id,
            'profile' => $directory->profile($me, $r->requester_type, $r->requester_id),
            'requested_at' => $r->created_at?->toIso8601String(),
        ])->all();
    }

    /**
     * An admin lets someone in (or not). The requester hears about it either way.
     */
    public function decideJoinRequest(Model $me, ChatConversation $conversation, int $requestId, bool $approve): void
    {
        $admin = $this->assertAdmin($me, $conversation);
        $request = ChatGroupJoinRequest::query()->where('conversation_id', $conversation->id)->pending()->find($requestId)
            ?? throw new ChatException('join_request_not_found', 404);
        $requester = $request->requester() ?? throw ChatException::userNotFound();

        if ($approve) {
            $this->assertRoom($conversation);
        }

        DB::transaction(function () use ($me, $conversation, $request, $requester, $admin, $approve) {
            $request->update([
                'status' => $approve ? ChatGroupJoinRequest::APPROVED : ChatGroupJoinRequest::REJECTED,
                'decided_by_participant_id' => $admin->id,
                'decided_at' => now(),
            ]);

            if ($approve && ! $conversation->activeParticipants()->of($requester)->exists()) {
                $this->conversations->addParticipant($conversation, $requester, ParticipantRole::Member, (int) $conversation->last_message_id ?: null);
                $this->messages->system($conversation, $me, 'join_request_approved', [ParticipantType::key($requester)]);
                $this->changed($conversation);
            }
        });

        $this->broadcaster->toAccounts([[$request->requester_type, (int) $request->requester_id]], 'chat.group.join_decided', [
            'conversation_id' => $conversation->uuid,
            'status' => $request->status,
            'group_name' => $conversation->group?->name,
        ]);
        $this->tellAdmins($conversation);
    }

    public function pendingJoinCount(ChatConversation $conversation): int
    {
        return ChatGroupJoinRequest::query()->where('conversation_id', $conversation->id)->pending()->count();
    }

    private function assertRoom(ChatConversation $conversation): void
    {
        $max = ChatSetting::current()->max_group_members;
        if ($conversation->activeParticipants()->count() >= $max) {
            throw ChatException::groupFull($max);
        }
    }

    /**
     * The admins' badge ("3 asking to join") changed.
     */
    private function tellAdmins(ChatConversation $conversation): void
    {
        $admins = $conversation->activeParticipants()->get()->filter(fn (ChatParticipant $p) => $p->isAdmin());

        $this->broadcaster->toParticipants($admins, 'chat.group.join_requests', [
            'conversation_id' => $conversation->uuid,
            'pending' => $this->pendingJoinCount($conversation),
        ]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  list<Model>  $accounts
     * @return array{added: list<string>, not_added: list<string>}
     */
    private function addAccounts(Model $me, ChatConversation $conversation, array $accounts, bool $seeHistory = false): array
    {
        $max = ChatSetting::current()->max_group_members;
        $current = $conversation->activeParticipants()->count();
        $added = [];
        $notAdded = [];

        foreach ($accounts as $account) {
            $key = ParticipantType::key($account);

            if ($key === ParticipantType::key($me) || $conversation->activeParticipants()->of($account)->exists()) {
                continue;
            }

            // Their privacy says no (or one blocked the other): they can still join by invite link.
            if (! ParticipantType::isEnabled(ParticipantType::aliasFor($account)) || ! $this->privacy->canAddToGroup($me, $account)) {
                $notAdded[] = $key;

                continue;
            }

            if ($current + 1 > $max) {
                throw ChatException::groupFull($max);
            }

            $this->conversations->addParticipant($conversation, $account, ParticipantRole::Member, $seeHistory ? null : ((int) $conversation->last_message_id ?: null));
            $added[] = $key;
            $current++;
        }

        if ($added !== []) {
            $this->messages->system($conversation, $me, 'members_added', $added);
            $this->changed($conversation);
        }

        return ['added' => $added, 'not_added' => $notAdded];
    }

    private function assertGroupMember(Model $me, ChatConversation $conversation): ChatParticipant
    {
        if (! $conversation->isGroup()) {
            throw ChatException::groupOnly();
        }

        return $this->conversations->participantOf($me, $conversation, true);
    }

    private function assertAdmin(Model $me, ChatConversation $conversation): ChatParticipant
    {
        $participant = $this->assertGroupMember($me, $conversation);

        if (! $participant->isAdmin()) {
            throw ChatException::adminsOnly();
        }

        return $participant;
    }

    private function activeMember(ChatConversation $conversation, int $participantId): ChatParticipant
    {
        return $conversation->activeParticipants()->whereKey($participantId)->first()
            ?? throw ChatException::notParticipant();
    }

    private function groupByInvite(string $token): ChatGroup
    {
        $group = ChatGroup::query()->where('invite_token', $token)->with('conversation')->first();

        if ($group === null || $group->conversation === null) {
            throw ChatException::inviteInvalid();
        }

        return $group;
    }

    /**
     * @param  list<ChatParticipant>  $alsoNotify  people who just left (they need to know too)
     */
    private function changed(ChatConversation $conversation, array $alsoNotify = []): void
    {
        $this->broadcaster->toParticipants(
            $conversation->activeParticipants()->get()->concat($alsoNotify),
            'chat.conversation.updated',
            ['conversation_id' => $conversation->uuid],
        );
    }
}
