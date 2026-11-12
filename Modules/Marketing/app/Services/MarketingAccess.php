<?php

namespace Modules\Marketing\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Marketing\Enums\ProjectRole;
use Modules\Marketing\Exceptions\MarketingException;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Models\MarketingProjectMember;

/**
 * Who may do what on a project (POL-03), checked on the server for every call: the caller must be on the project's
 * team, and their role must allow the action. A refusal on someone else's project is written to the audit trail
 * (AT-07) and says nothing about the project.
 */
class MarketingAccess
{
    public function __construct(private readonly MarketingAudit $audit) {}

    public function role(MarketingProject $project, Model $actor): ?ProjectRole
    {
        return MarketingProjectMember::query()->where('project_id', $project->id)
            ->where('member_type', $actor->getMorphClass())->where('member_id', $actor->getKey())
            ->first()?->role;
    }

    /** @param  'read'|'edit'|'research'|'approve'|'owner'  $ability */
    public function assert(MarketingProject $project, Model $actor, string $ability = 'read'): ProjectRole
    {
        $role = $this->role($project, $actor);

        if ($role === null) {
            $this->audit->record($project, $actor, 'access.denied', $project, meta: ['ability' => $ability]);

            throw new MarketingException('not_member', 403);
        }

        $allowed = match ($ability) {
            'read' => true,
            'edit' => $role->canEdit(),
            'research' => $role->canResearch(),
            'approve' => $role->canApprove(),
            'owner' => $role->isOwner(),
        };

        if (! $allowed) {
            $this->audit->record($project, $actor, 'access.denied', $project, meta: ['ability' => $ability, 'role' => $role->value]);

            throw new MarketingException('role_forbidden', 403, [], ['ability' => $ability, 'role' => $role->value]);
        }

        return $role;
    }
}
