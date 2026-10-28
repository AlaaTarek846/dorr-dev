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
        return Approval::query()->where('project_id', $projectId)
            ->when($agreementIds !== null, fn ($q) => $q->whereIn('agreement_id', $agreementIds))
            ->orderBy('id')->get()
            ->map(fn (Approval $r) => [
                'id' => $r->id,
                'action' => $r->action,
                'label' => Lang::has($key = 'freelance.trail.'.str_replace('.', '_', $r->action)) ? __($key) : $r->action,
                'actor' => $r->actor_type,
                'mine' => $viewer !== 'admin' && $r->actor_type === $viewer,
                'version' => $r->version,
                'details' => $r->payload ?? (object) [],
                'at' => $r->created_at?->toIso8601String(),
            ] + ($viewer === 'admin' ? ['actor_id' => $r->actor_id, 'agreement_id' => $r->agreement_id] : []))
            ->values()->all();
    }
}
