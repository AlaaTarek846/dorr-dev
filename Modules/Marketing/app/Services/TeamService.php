<?php

namespace Modules\Marketing\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Marketing\Enums\ProjectRole;
use Modules\Marketing\Exceptions\MarketingException;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Models\MarketingProjectMember;
use Modules\Marketing\Services\Packages\MarketingEntitlement;
use Modules\Provider\Models\Provider;
use Modules\User\Models\User;

/**
 * The project's team (D2, U13). The owner invites people of the same kind of account (a user invites users, a
 * provider invites providers) by phone number, gives them a role, changes it or removes them. The owner's role
 * never changes and is never removed.
 */
class TeamService
{
    public function __construct(
        private readonly MarketingAccess $access,
        private readonly MarketingAudit $audit,
        private readonly MarketingEntitlement $entitlement,
    ) {}

    public function add(MarketingProject $project, Model $actor, string $phone, string $role): MarketingProjectMember
    {
        $this->access->assert($project, $actor, 'owner');
        $this->entitlement->assertRoom($project->owner);

        $member = $this->findAccount($project, $phone) ?? throw new MarketingException('member_not_found', 404);
        if ($member->is($actor)) {
            throw new MarketingException('member_is_owner', 422);
        }
        if ($this->access->role($project, $member) !== null) {
            throw new MarketingException('member_exists', 409);
        }

        $limit = $this->entitlement->memberLimit($project->owner);
        $count = $project->members()->where('role', '!=', ProjectRole::Owner->value)->count();
        if ($limit !== null && $count >= $limit) {
            throw new MarketingException('limit_reached', 429, ['limit' => $limit], ['limit_key' => 'members', 'limit' => $limit, 'used' => $count]);
        }

        $row = $project->members()->create(['member_type' => $member->getMorphClass(), 'member_id' => $member->getKey(), 'role' => $role]);
        $this->audit->record($project, $actor, 'member.added', $row, after: ['member' => $member->getMorphClass().':'.$member->getKey(), 'role' => $role]);

        return $row->load('member');
    }

    public function changeRole(MarketingProject $project, Model $actor, MarketingProjectMember $row, string $role): MarketingProjectMember
    {
        $this->access->assert($project, $actor, 'owner');
        $this->assertTeammate($project, $row);
        $before = ['role' => $row->role->value];
        $row->update(['role' => $role]);
        $this->audit->record($project, $actor, 'member.role_changed', $row, $before, ['role' => $role]);

        return $row->load('member');
    }

    public function remove(MarketingProject $project, Model $actor, MarketingProjectMember $row): void
    {
        $this->access->assert($project, $actor, 'owner');
        $this->assertTeammate($project, $row);
        $this->audit->record($project, $actor, 'member.removed', $row, ['role' => $row->role->value]);
        $row->delete();
    }

    /** A member leaving on their own (anyone but the owner). */
    public function leave(MarketingProject $project, Model $actor): void
    {
        $role = $this->access->assert($project, $actor);
        if ($role->isOwner()) {
            throw new MarketingException('owner_cannot_leave', 422);
        }
        $project->members()->where('member_type', $actor->getMorphClass())->where('member_id', $actor->getKey())->delete();
        $this->audit->record($project, $actor, 'member.left', $project);
    }

    private function assertTeammate(MarketingProject $project, MarketingProjectMember $row): void
    {
        if ($row->project_id !== $project->id) {
            throw new MarketingException('member_not_found', 404);
        }
        if ($row->role === ProjectRole::Owner) {
            throw new MarketingException('member_is_owner', 422);
        }
    }

    private function findAccount(MarketingProject $project, string $phone): ?Model
    {
        $phone = preg_replace('/[^\d+]/', '', $phone) ?? '';
        $model = $project->owner_type === 'provider' ? Provider::class : User::class;

        return $model::query()->where('phone', $phone)->where('status', 'active')->first();
    }
}
