<?php

namespace Modules\Marketing\Services\Platforms;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Marketing\Enums\ProjectRole;
use Modules\Marketing\Exceptions\MarketingException;
use Modules\Marketing\Models\MarketingApproval;
use Modules\Marketing\Models\MarketingCampaign;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Models\MarketingProjectMember;
use Modules\Marketing\Services\MarketingAccess;
use Modules\Marketing\Services\MarketingAudit;

/**
 * Spend delegation (D7, POL-02): the owner lets one manager approve campaigns up to a total cap until a date.
 * Each campaign approved under it uses part of the cap; revoking it (or reaching the date) stops every campaign
 * approved under it that was not published yet (AT-11).
 */
class DelegationService
{
    public const COMMITTED = [MarketingCampaign::APPROVED, MarketingCampaign::SUBMITTING, MarketingCampaign::PUBLISHED, MarketingCampaign::UNKNOWN];

    public function __construct(private readonly MarketingAccess $access, private readonly MarketingAudit $audit) {}

    public function create(MarketingProject $project, Model $actor, int $memberId, int $capMinor, Carbon $expiresAt): MarketingApproval
    {
        $this->access->assert($project, $actor, 'owner');
        $member = MarketingProjectMember::query()->where('project_id', $project->id)->whereKey($memberId)->with('member')->first();
        if ($member === null || $member->role !== ProjectRole::Manager || $member->member === null) {
            throw new MarketingException('delegation_invalid', 422);
        }
        $data = ['delegate' => $member->member_type.':'.$member->member_id, 'cap_minor' => $capMinor, 'currency' => $project->budget_currency, 'expires_at' => $expiresAt->toIso8601String()];

        $delegation = MarketingApproval::query()->create([
            'project_id' => $project->id,
            'subject_type' => 'delegation',
            'subject_id' => $project->id,
            'subject_version' => 1,
            'payload_hash' => MarketingAudit::hash($data),
            'scope' => 'delegation',
            'cap_minor' => $capMinor,
            'currency' => $project->budget_currency,
            'status' => MarketingApproval::ACTIVE,
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actor->getKey(),
            'delegate_type' => $member->member_type,
            'delegate_id' => $member->member_id,
            'approved_at' => now(),
            'expires_at' => $expiresAt,
        ]);
        $this->audit->record($project, $actor, 'delegation.created', $delegation, after: $data);

        return $delegation;
    }

    public function revoke(MarketingApproval $delegation, Model $actor): MarketingApproval
    {
        $project = MarketingProject::query()->findOrFail($delegation->project_id);
        $this->access->assert($project, $actor, 'owner');
        if ($delegation->scope !== 'delegation' || $delegation->status !== MarketingApproval::ACTIVE) {
            throw new MarketingException('invalid_transition', 409, [], ['status' => $delegation->status]);
        }
        DB::transaction(function () use ($delegation, $project, $actor) {
            $delegation->update(['status' => MarketingApproval::REVOKED, 'revoked_at' => now()]);
            // Campaign approvals given under it and not used yet stop counting too.
            $derived = MarketingApproval::query()->where('delegation_id', $delegation->id)->where('status', MarketingApproval::ACTIVE)->get();
            foreach ($derived as $approval) {
                $approval->update(['status' => MarketingApproval::REVOKED, 'revoked_at' => now()]);
                MarketingCampaign::query()->whereKey($approval->subject_id)->where('status', MarketingCampaign::APPROVED)
                    ->update(['status' => MarketingCampaign::AWAITING_APPROVAL, 'updated_at' => now()]);
            }
            $this->audit->record($project, $actor, 'delegation.revoked', $delegation, meta: ['campaign_approvals_revoked' => $derived->count()]);
        });

        return $delegation->fresh();
    }

    /** The active delegation of this person in this project, if any. */
    public function activeFor(MarketingProject $project, Model $actor): ?MarketingApproval
    {
        return MarketingApproval::query()->where('project_id', $project->id)->where('scope', 'delegation')->where('status', MarketingApproval::ACTIVE)
            ->where('delegate_type', $actor->getMorphClass())->where('delegate_id', $actor->getKey())
            ->where('expires_at', '>', now())->latest('id')->first();
    }

    public function valid(?MarketingApproval $delegation): bool
    {
        return $delegation !== null && $delegation->status === MarketingApproval::ACTIVE && $delegation->expires_at !== null && $delegation->expires_at->isFuture();
    }

    /** The part of the cap already given to campaigns (approved and not dropped since). */
    public function used(MarketingApproval $delegation, ?int $exceptCampaign = null): int
    {
        $campaigns = MarketingApproval::query()->where('delegation_id', $delegation->id)->whereIn('status', [MarketingApproval::ACTIVE])
            ->pluck('subject_id')->all();

        return (int) MarketingCampaign::query()->whereIn('id', $campaigns)->whereIn('status', self::COMMITTED)
            ->when($exceptCampaign !== null, fn ($q) => $q->whereKeyNot($exceptCampaign))->sum('total_budget_minor');
    }
}
