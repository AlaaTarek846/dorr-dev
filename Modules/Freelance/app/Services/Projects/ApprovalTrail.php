<?php

namespace Modules\Freelance\Services\Projects;

use Illuminate\Support\Facades\Lang;
use Modules\Freelance\Models\FreelanceProjectApproval as Approval;

/**
 * A project's record of approvals (FL-406), oldest first: the agreement and its versions, every change asked and answered, each part
 * funded / delivered / approved / cancelled, the requests to end a part, the disputes' decisions and appeals, and the ratings.
 * The two sides read the steps of their own agreement(s); staff read everything, with who did it.
 */
class ApprovalTrail
{
    /**
     * @param  list<int>|null  $agreementIds  null = all of the project (staff)
     * @param  'client'|'freelancer'|'admin'  $viewer
     * @return list<array<string, mixed>>
     */
    public function list(int $projectId, ?array $agreementIds, string $viewer): array
    {
        $rows = Approval::query()->where('project_id', $projectId)
            ->when($agreementIds !== null, fn ($q) => $q->whereIn('agreement_id', $agreementIds))
            ->orderBy('id')->get();
        // FS-104: a step a member of the client's team took for the owner shows his name (nothing else about him).
        $names = \Modules\User\Models\User::query()->whereIn('id', $rows->pluck('acted_by_user_id')->filter()->unique())->pluck('name', 'id');
        // FS-206a: and a step somebody of the freelancers' team took for the Owner who signed shows his name.
        $person = \Modules\Freelance\Services\FreelancerTeam\FreelancerTeamContext::namer($rows->pluck('acted_by_freelancer_id'), $viewer === 'client');

        return $rows
            ->map(fn (Approval $r) => [
                'by' => $r->acted_by_user_id === null ? $person($r->acted_by_freelancer_id) : ['name' => (string) ($names[$r->acted_by_user_id] ?? ''), 'team' => true],
                'id' => $r->id,
                'action' => $r->action,
                'label' => Lang::has($key = 'freelance.trail.'.str_replace('.', '_', $r->action)) ? __($key) : $r->action,
                'actor' => $r->actor_type,
                'mine' => $viewer !== 'admin' && $r->actor_type === $viewer,
                'version' => $r->version,
                'details' => $r->payload ?? (object) [],
                'at' => $r->created_at?->toIso8601String(),
            ] + ($viewer === 'admin' ? ['actor_id' => $r->actor_id, 'acted_by_user_id' => $r->acted_by_user_id, 'acted_by_freelancer_id' => $r->acted_by_freelancer_id, 'agreement_id' => $r->agreement_id] : []))
            ->values()->all();
    }
}
