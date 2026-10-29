<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Enums\GroupKind;
use Modules\Chat\Enums\ParticipantRole;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroupAuditLog;
use Modules\Chat\Models\ChatGroupMemberRole;
use Modules\Chat\Models\ChatGroupRole;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Support\GroupPermissions;

/**
 * Roles in educational and work groups (docs/groups_tasks.md GR-004): the templates a new group
 * starts with, roles its managers make, and who holds which. Managing them takes `roles.manage`;
 * nobody but the owner can hand out a permission they don't hold themselves. A role someone
 * holds can't be deleted before they're moved to another one. Every change is recorded (GR-010)
 * and the members' screens refresh (`chat.conversation.updated`).
 */
class GroupRoleService
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly ChatBroadcaster $broadcaster,
    ) {}

    /** A new educational / work group: its template roles. */
    public function seed(ChatConversation $conversation, GroupKind $kind): void
    {
        $templates = GroupPermissions::templates($kind);
        $order = 0;

        foreach ($templates['roles'] as $template => $permissions) {
            ChatGroupRole::query()->create([
                'conversation_id' => $conversation->id,
                'template' => $template,
                'permissions' => $permissions,
                'is_default' => $template === $templates['default'],
                'sort_order' => $order++,
            ]);
        }
    }

    /** The group's maker leads it: teacher / manager instead of the default role. */
    public function makeLeader(ChatConversation $conversation, ChatParticipant $participant): void
    {
        $leader = GroupPermissions::templates($this->kindOf($conversation))['leader'];
        $role = ChatGroupRole::query()->where('conversation_id', $conversation->id)->where('template', $leader)->first();

        if ($role !== null) {
            ChatGroupMemberRole::query()->where('participant_id', $participant->id)->delete();
            ChatGroupMemberRole::query()->create(['role_id' => $role->id, 'participant_id' => $participant->id]);
        }
    }

    /**
     * Someone (re)joined: they start over with the default role — roles held before leaving never
     * come back on their own.
     */
    public function joined(ChatConversation $conversation, ChatParticipant $participant): void
    {
        if ($this->kindOf($conversation) === GroupKind::Normal) {
            return;
        }

        ChatGroupMemberRole::query()->where('participant_id', $participant->id)->delete();
        $default = ChatGroupRole::query()->where('conversation_id', $conversation->id)->where('is_default', true)->first();

        if ($default !== null) {
            ChatGroupMemberRole::query()->create(['role_id' => $default->id, 'participant_id' => $participant->id]);
        }
    }

    /**
     * The roles, the permissions this kind of group has (with their names), and mine.
     *
     * @return array<string, mixed>
     */
    public function list(Model $me, ChatConversation $conversation): array
    {
        $kind = $this->assertKindGroup($conversation);
        $this->conversations->participantOf($me, $conversation, true);

        $roles = ChatGroupRole::query()->where('conversation_id', $conversation->id)->withCount('members')
            ->orderBy('sort_order')->orderBy('id')->get();

        return [
            'roles' => $roles->map(fn (ChatGroupRole $r) => $this->present($r))->all(),
            'permissions' => array_map(fn (string $key) => ['key' => $key, 'name' => __('chat.group_permissions.'.$key)], GroupPermissions::catalogue($kind)),
            'my_permissions' => GroupPermissions::of($me, $conversation),
        ];
    }

    /**
     * @param  list<string>  $permissions
     */
    public function create(Model $me, ChatConversation $conversation, string $name, array $permissions): ChatGroupRole
    {
        $kind = $this->assertKindGroup($conversation);
        $mine = $this->assertManager($me, $conversation);
        $permissions = $this->clean($kind, $permissions, $mine);

        return DB::transaction(function () use ($me, $conversation, $name, $permissions) {
            $role = ChatGroupRole::query()->create([
                'conversation_id' => $conversation->id,
                'name' => trim($name),
                'permissions' => $permissions,
                'sort_order' => (int) ChatGroupRole::query()->where('conversation_id', $conversation->id)->max('sort_order') + 1,
            ]);
            ChatGroupAuditLog::record($conversation, $me, 'role_created', 'role:'.$role->id, null, ['name' => $role->name, 'permissions' => $permissions]);
            $this->changed($conversation);

            return $role;
        });
    }

    /**
     * @param  array{name?: string, permissions?: list<string>}  $data
     */
    public function update(Model $me, ChatConversation $conversation, int $roleId, array $data): ChatGroupRole
    {
        $kind = $this->assertKindGroup($conversation);
        $mine = $this->assertManager($me, $conversation);
        $role = $this->role($conversation, $roleId);
        $this->assertCanHandOut($role->permissions ?? [], $mine);

        $changes = [];
        if (array_key_exists('name', $data)) {
            $changes['name'] = filled($data['name']) ? trim((string) $data['name']) : null;
        }
        if (array_key_exists('permissions', $data)) {
            $changes['permissions'] = $this->clean($kind, (array) $data['permissions'], $mine);
        }

        DB::transaction(function () use ($me, $conversation, $role, $changes) {
            $before = ['name' => $role->name, 'permissions' => $role->permissions];
            $role->update($changes);
            ChatGroupAuditLog::record($conversation, $me, 'role_updated', 'role:'.$role->id, $before, ['name' => $role->name, 'permissions' => $role->permissions]);
            $this->changed($conversation);
        });

        return $role->refresh()->loadCount('members');
    }

    public function delete(Model $me, ChatConversation $conversation, int $roleId): void
    {
        $this->assertKindGroup($conversation);
        $mine = $this->assertManager($me, $conversation);
        $role = $this->role($conversation, $roleId);
        $this->assertCanHandOut($role->permissions ?? [], $mine);

        if ($role->is_default) {
            throw new ChatException('role_is_default', 409);
        }
        if ($role->members()->exists()) {
            // Move its members to another role first (spec SC-003).
            throw new ChatException('role_in_use', 409);
        }

        DB::transaction(function () use ($me, $conversation, $role) {
            ChatGroupAuditLog::record($conversation, $me, 'role_deleted', 'role:'.$role->id, ['name' => $role->displayName(), 'permissions' => $role->permissions]);
            $role->delete();
            $this->changed($conversation);
        });
    }

    /**
     * The roles one member holds in the whole group, replaced by these.
     *
     * @param  list<int>  $roleIds
     * @return list<int> what they hold now
     */
    public function setMemberRoles(Model $me, ChatConversation $conversation, int $participantId, array $roleIds): array
    {
        $this->assertKindGroup($conversation);
        $mine = $this->assertManager($me, $conversation);

        $target = $conversation->activeParticipants()->find($participantId) ?? throw new ChatException('not_a_member', 404);
        if ($target->role === ParticipantRole::Owner) {
            // The owner may do everything anyway.
            throw new ChatException('owner_action_only', 403);
        }

        $roles = ChatGroupRole::query()->where('conversation_id', $conversation->id)->whereIn('id', array_unique($roleIds))->get();
        if ($roles->count() !== count(array_unique($roleIds))) {
            throw new ChatException('role_not_found', 404);
        }

        $current = ChatGroupMemberRole::query()->where('participant_id', $target->id)->whereNull('space_id')->pluck('role_id')->all();
        $touched = ChatGroupRole::query()->whereIn('id', array_merge($current, $roles->pluck('id')->all()))->get()
            ->filter(fn (ChatGroupRole $r) => in_array($r->id, $current, true) xor $roles->contains('id', $r->id));
        // Giving or taking a role: both only for permissions I hold myself.
        $this->assertCanHandOut($touched->flatMap(fn (ChatGroupRole $r) => (array) $r->permissions)->unique()->all(), $mine);

        DB::transaction(function () use ($me, $conversation, $target, $roles, $current) {
            ChatGroupMemberRole::query()->where('participant_id', $target->id)->whereNull('space_id')->delete();
            foreach ($roles as $role) {
                ChatGroupMemberRole::query()->create(['role_id' => $role->id, 'participant_id' => $target->id]);
            }
            ChatGroupAuditLog::record($conversation, $me, 'member_roles_changed', 'participant:'.$target->id, ['roles' => $current], ['roles' => $roles->pluck('id')->all()]);
            $this->changed($conversation);
            app(GroupNotifier::class)->notify($conversation, [$target], 'roles', 'roles_changed', ['roles' => $roles->map(fn (ChatGroupRole $r) => $r->displayName())->implode('، ')]);
        });

        return $roles->pluck('id')->all();
    }

    /**
     * The roles each member holds in the whole group, for the members list.
     *
     * @param  iterable<int>  $participantIds
     * @return array<int, list<int>> participant id => role ids
     */
    public function rolesOf(iterable $participantIds): array
    {
        return ChatGroupMemberRole::query()->whereIn('participant_id', collect($participantIds)->all())->whereNull('space_id')->get()
            ->groupBy('participant_id')->map(fn ($rows) => $rows->pluck('role_id')->all())->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ChatGroupRole $role): array
    {
        return [
            'id' => $role->id,
            'template' => $role->template,
            'name' => $role->displayName(),
            'permissions' => array_values((array) $role->permissions),
            'is_default' => $role->is_default,
            'members_count' => (int) ($role->members_count ?? $role->members()->count()),
        ];
    }

    private function kindOf(ChatConversation $conversation): GroupKind
    {
        return $conversation->group?->kind ?? GroupKind::Normal;
    }

    private function assertKindGroup(ChatConversation $conversation): GroupKind
    {
        $kind = $conversation->isGroup() && ! $conversation->isChannel() ? $this->kindOf($conversation) : GroupKind::Normal;
        if ($kind === GroupKind::Normal) {
            throw new ChatException('group_kind_required', 422);
        }

        return $kind;
    }

    /** @return list<string> my permissions — `roles.manage` among them */
    private function assertManager(Model $me, ChatConversation $conversation): array
    {
        $this->conversations->participantOf($me, $conversation, true);
        $mine = GroupPermissions::of($me, $conversation);

        if (! in_array('roles.manage', $mine, true)) {
            throw new ChatException('group_permission_denied', 403);
        }

        return $mine;
    }

    /**
     * Only this kind's permissions, and only ones I hold myself (the owner holds them all).
     *
     * @param  list<string>  $permissions
     * @param  list<string>  $mine
     * @return list<string>
     */
    private function clean(GroupKind $kind, array $permissions, array $mine): array
    {
        $permissions = array_values(array_unique(array_map('strval', $permissions)));
        if (array_diff($permissions, GroupPermissions::catalogue($kind)) !== []) {
            throw new ChatException('permission_unknown', 422);
        }
        $this->assertCanHandOut($permissions, $mine);

        return $permissions;
    }

    /**
     * @param  iterable<string>  $permissions
     * @param  list<string>  $mine
     */
    private function assertCanHandOut(iterable $permissions, array $mine): void
    {
        if (array_diff(collect($permissions)->all(), $mine) !== []) {
            throw new ChatException('group_permission_denied', 403);
        }
    }

    private function role(ChatConversation $conversation, int $roleId): ChatGroupRole
    {
        return ChatGroupRole::query()->where('conversation_id', $conversation->id)->find($roleId) ?? throw new ChatException('role_not_found', 404);
    }

    private function changed(ChatConversation $conversation): void
    {
        $this->broadcaster->toParticipants($conversation->activeParticipants()->get(), 'chat.conversation.updated', ['conversation_id' => $conversation->uuid]);
    }
}
