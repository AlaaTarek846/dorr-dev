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
            $data = array_intersect_key($data, array_flip(['only_admins_send', 'only_admins_edit_info', 'only_admins_add_members', 'approve_joins']));
            $before = $group->only_admins_send;
            $group->update($data);

            if (array_key_exists('only_admins_send', $data) && (bool) $data['only_admins_send'] !== $before) {
                $this->messages->system($conversation, $me, $data['only_admins_send'] ? 'group_announcement_on' : 'group_announcement_off');
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
            $participant->update(['left_at' => now(), 'role' => ParticipantRole::Member, 'pinned_at' => null]);
            $this->messages->system($conversation, $me, 'member_left');

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
            'approve_joins' => (bool) $group->approve_joins,
            'request_status' => $this->myJoinRequestStatus($me, $group),
        ];
    }

    /**
     * Where my own join request for this group stands: pending, approved, rejected — or null when
     * I never asked (or the group lets people in straight away).
     */
    private function myJoinRequestStatus(Model $me, ChatGroup $group): ?string
    {
        if (! $group->approve_joins) {
            return null;
        }

        $key = ParticipantType::key($me);
        [$type, $id] = explode(':', $key, 2) + [null, null];

        $request = ChatGroupJoinRequest::query()
            ->where('conversation_id', $group->conversation_id)
            ->where('requester_type', $type)
            ->where('requester_id', $id)
            ->latest('id')
            ->first();

        // An approved request means I am already in — leave that to is_member.
        $status = $request?->status;

        return $status === ChatGroupJoinRequest::APPROVED ? null : $status;
    }

    /**
     * Join by invite link. When the group asks admins first, this leaves a pending request instead
     * of adding the member, and returns null so the caller can tell the two apart.
     */
    public function join(Model $me, string $token): ?ChatParticipant
    {
        $group = $this->groupByInvite($token);
        $conversation = $group->conversation;

        $existing = $conversation->activeParticipants()->of($me)->first();
        if ($existing !== null) {
            return $existing;
        }

        if ($group->approve_joins) {
            $this->requestJoin($me, $group);

            return null;
        }

        return $this->addFromLink($me, $conversation);
    }

    /**
     * Take back a request I sent.
     */
    public function cancelJoin(Model $me, string $token): void
    {
        $group = $this->groupByInvite($token);
        $key = ParticipantType::key($me);

        $request = ChatGroupJoinRequest::query()
            ->where('conversation_id', $group->conversation_id)
            ->where('requester_type', explode(':', $key, 2)[0])
            ->where('requester_id', explode(':', $key, 2)[1])
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        $request?->update(['status' => 'cancelled', 'decided_at' => now()]);
    }

    /**
     * The requests waiting on this group's admins, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function joinRequests(Model $me, ChatConversation $conversation): array
    {
        $this->assertAdmin($me, $conversation);

        $rows = ChatGroupJoinRequest::query()
            ->where('conversation_id', $conversation->id)
            ->where('status', 'pending')
            ->orderBy('id')
            ->get();

        $directory = app(ParticipantDirectory::class);
        $directory->prime($me, $rows->map(fn (ChatGroupJoinRequest $r) => explode(':', $r->requesterKey(), 2)));

        return $rows->map(fn (ChatGroupJoinRequest $r) => [
            'id' => $r->id,
            'profile' => $directory->profile($me, $r->requester_type, $r->requester_id),
            'requested_at' => $r->created_at?->toIso8601String(),
        ])->values()->all();
    }

    /**
     * Approving adds the person to the group; rejecting just closes the request. Both leave the
     * remaining queue, which is what the screen redraws from the response.
     *
     * @return list<array<string, mixed>>
     */
    public function decideJoin(Model $me, ChatConversation $conversation, int $request, bool $approve): array
    {
        $this->assertAdmin($me, $conversation);

        $row = ChatGroupJoinRequest::query()
            ->where('conversation_id', $conversation->id)
            ->where('id', $request)
            ->where('status', 'pending')
            ->first();

        if ($row === null) {
            throw new ChatException('join_request_missing', 404);
        }

        $decider = $this->conversations->participantOf($me, $conversation);

        if (! $approve) {
            $row->update(['status' => 'rejected', 'decided_by_participant_id' => $decider->id, 'decided_at' => now()]);

            return $this->joinRequests($me, $conversation);
        }

        $max = ChatSetting::current()->max_group_members;
        if ($conversation->activeParticipants()->count() >= $max) {
            throw ChatException::groupFull($max);
        }

        DB::transaction(function () use ($row, $conversation, $decider) {
            $account = $this->resolveRequester($row);

            $this->conversations->addParticipant($conversation, $account, ParticipantRole::Member, (int) $conversation->last_message_id ?: null);
            $this->messages->system($conversation, $account, 'member_joined_via_link');
            $this->changed($conversation);

            $row->update(['status' => 'approved', 'decided_by_participant_id' => $decider->id, 'decided_at' => now()]);
        });

        return $this->joinRequests($me, $conversation);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Record a pending request, or refresh the one already waiting. The id of an earlier request is
     * kept so the person can cancel it.
     */
    private function requestJoin(Model $me, ChatGroup $group): void
    {
        $key = ParticipantType::key($me);
        [$type, $id] = explode(':', $key, 2) + [null, null];

        ChatGroupJoinRequest::query()->updateOrCreate(
            [
                'conversation_id' => $group->conversation_id,
                'requester_type' => $type,
                'requester_id' => $id,
                'status' => 'pending',
            ],
            [],
        );
    }

    /**
     * The account behind a finished request, resolved now that it is being approved.
     */
    private function resolveRequester(ChatGroupJoinRequest $row): Model
    {
        return $row->requester() ?? throw new ChatException('join_request_missing', 404);
    }

    private function addFromLink(Model $me, ChatConversation $conversation): ChatParticipant
    {
        $max = ChatSetting::current()->max_group_members;
        if ($conversation->activeParticipants()->count() >= $max) {
            throw ChatException::groupFull($max);
        }

        return DB::transaction(function () use ($me, $conversation) {
            $row = $this->conversations->addParticipant($conversation, $me, ParticipantRole::Member, (int) $conversation->last_message_id ?: null);
            $this->messages->system($conversation, $me, 'member_joined_via_link');
            $this->changed($conversation);

            return $row;
        });
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
