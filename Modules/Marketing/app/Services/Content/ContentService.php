<?php

namespace Modules\Marketing\Services\Content;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Marketing\Exceptions\MarketingException;
use Modules\Marketing\Jobs\GenerateContentJob;
use Modules\Marketing\Models\MarketingApproval;
use Modules\Marketing\Models\MarketingContentItem;
use Modules\Marketing\Models\MarketingJob;
use Modules\Marketing\Models\MarketingPlan;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Services\Budgets\BudgetService;
use Modules\Marketing\Services\MarketingAccess;
use Modules\Marketing\Services\MarketingAudit;
use Modules\Marketing\Services\MarketingJobs;
use Modules\Marketing\Services\Packages\MarketingEntitlement;

/**
 * The calendar and the ad studio (U06, U07): items are written by a person or proposed by the AI, reviewed for
 * claims, approved by the owner or a manager, then posted **by the person** (phase B publishes nothing). Changing the
 * words of an approved item sends it back to draft and the approval stops counting (POL-14), with `lock_version` so
 * nobody overwrites what they have not seen.
 */
class ContentService
{
    public function __construct(
        private readonly MarketingAccess $access,
        private readonly MarketingAudit $audit,
        private readonly MarketingEntitlement $entitlement,
        private readonly MarketingJobs $jobs,
        private readonly ClaimsReviewer $reviewer,
        private readonly BudgetService $budgets,
    ) {}

    /** @param  array<string, mixed>  $data  validated */
    public function create(MarketingProject $project, Model $actor, array $data): MarketingContentItem
    {
        $this->access->assert($project, $actor, 'edit');
        $this->entitlement->assertRoom($project->owner);
        $kind = $data['kind'];
        $content = $kind === 'task' ? null : ContentSchema::normalize($data['variants'] ?? [], $data['selected'] ?? null);

        $item = MarketingContentItem::query()->create([
            'project_id' => $project->id,
            'plan_id' => $this->planId($project, $data['plan'] ?? null),
            'kind' => $kind,
            'channel' => $data['channel'],
            'format' => $kind === 'task' ? null : ($data['format'] ?? null),
            'title' => $data['title'],
            'objective' => $data['objective'] ?? null,
            'content' => $content,
            'claims' => $content === null ? null : $this->reviewer->review($data['channel'], $content),
            'status' => $kind === 'task' ? MarketingContentItem::OPEN : MarketingContentItem::DRAFT,
            'planned_at' => $data['planned_at'] ?? null,
            'cost_estimate_minor' => $data['cost_estimate_minor'] ?? null,
            'origin' => 'manual',
            'created_by_type' => $actor->getMorphClass(),
            'created_by_id' => $actor->getKey(),
        ]);
        $this->audit->record($project, $actor, 'content.created', $item, after: $item->approvalPayload());

        return $item;
    }

    /** @param  array<string, mixed>  $data  validated, with `lock_version` */
    public function update(MarketingContentItem $item, Model $actor, array $data): MarketingContentItem
    {
        $this->access->assert($item->project, $actor, 'edit');
        if ($item->status === MarketingContentItem::PUBLISHED) {
            throw new MarketingException('invalid_transition', 409, [], ['status' => $item->status]);
        }

        return DB::transaction(function () use ($item, $actor, $data) {
            $item = MarketingContentItem::query()->lockForUpdate()->findOrFail($item->id);
            if ((int) $data['lock_version'] !== $item->lock_version) {
                throw new MarketingException('version_conflict', 409, [], ['lock_version' => $item->lock_version]);
            }
            $before = $item->approvalPayload();

            foreach (['title', 'objective', 'channel', 'planned_at', 'cost_estimate_minor'] as $field) {
                if (array_key_exists($field, $data)) {
                    $item->{$field} = $data[$field];
                }
            }
            if (! $item->isTask()) {
                if (array_key_exists('format', $data)) {
                    $item->format = $data['format'];
                }
                if (array_key_exists('variants', $data) || array_key_exists('selected', $data)) {
                    $item->content = ContentSchema::normalize($data['variants'] ?? ($item->content['variants'] ?? []), $data['selected'] ?? ($item->content['selected'] ?? null));
                }
                $item->claims = $this->reviewer->review($item->channel, $item->content);
            }

            $changed = MarketingAudit::hash($before) !== MarketingAudit::hash($item->approvalPayload());
            $superseded = 0;
            if ($changed && ! $item->isTask() && $item->status !== MarketingContentItem::DRAFT) {
                $item->status = MarketingContentItem::DRAFT;
                $superseded = $this->supersede($item);
            }
            $item->lock_version++;
            $item->save();
            $this->audit->record($item->project, $actor, 'content.updated', $item, before: $before, after: $item->approvalPayload(), meta: ['content_changed' => $changed, 'approvals_superseded' => $superseded]);

            return $item;
        });
    }

    public function submit(MarketingContentItem $item, Model $actor): MarketingContentItem
    {
        $this->access->assert($item->project, $actor, 'edit');
        $this->assertStatus($item, [MarketingContentItem::DRAFT, MarketingContentItem::REJECTED]);
        $claims = $this->reviewer->review($item->channel, $item->content);
        $item->update(['claims' => $claims]);
        if (ClaimsReviewer::blocking($claims)) {
            throw new MarketingException('content_has_blocking_issues', 422, [], ['claims' => $claims]);
        }
        $item->update(['status' => MarketingContentItem::POLICY_REVIEW, 'review_note' => null]);
        $this->audit->record($item->project, $actor, 'content.submitted', $item);

        return $item;
    }

    /** Owner or manager; bound to the item's words (`payload_hash`) and its `lock_version`. */
    public function approve(MarketingContentItem $item, Model $actor, int $lockVersion): MarketingContentItem
    {
        $this->access->assert($item->project, $actor, 'approve');

        return DB::transaction(function () use ($item, $actor, $lockVersion) {
            $item = MarketingContentItem::query()->lockForUpdate()->findOrFail($item->id);
            if ($lockVersion !== $item->lock_version) {
                throw new MarketingException('version_conflict', 409, [], ['lock_version' => $item->lock_version]);
            }
            $this->assertStatus($item, [MarketingContentItem::POLICY_REVIEW]);
            $hash = MarketingAudit::hash($item->approvalPayload());
            MarketingApproval::query()->create([
                'project_id' => $item->project_id,
                'subject_type' => 'content',
                'subject_id' => $item->id,
                'subject_version' => $item->lock_version,
                'payload_hash' => $hash,
                'scope' => 'content',
                'status' => MarketingApproval::ACTIVE,
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'approved_at' => now(),
            ]);
            $item->update(['status' => MarketingContentItem::APPROVED]);
            $this->audit->record($item->project, $actor, 'content.approved', $item, after: ['hash' => $hash], meta: ['lock_version' => $lockVersion]);

            return $item;
        });
    }

    public function reject(MarketingContentItem $item, Model $actor, ?string $note): MarketingContentItem
    {
        $this->access->assert($item->project, $actor, 'approve');
        $this->assertStatus($item, [MarketingContentItem::POLICY_REVIEW]);
        $item->update(['status' => MarketingContentItem::REJECTED, 'review_note' => $note]);
        $this->audit->record($item->project, $actor, 'content.rejected', $item, meta: ['note' => $note]);

        return $item;
    }

    public function schedule(MarketingContentItem $item, Model $actor, Carbon $at): MarketingContentItem
    {
        $this->access->assert($item->project, $actor, 'edit');
        $this->assertStatus($item, [MarketingContentItem::APPROVED, MarketingContentItem::SCHEDULED]);
        $this->assertApprovalHolds($item);
        $item->update(['status' => MarketingContentItem::SCHEDULED, 'planned_at' => $at]);
        $this->audit->record($item->project, $actor, 'content.scheduled', $item, meta: ['planned_at' => $at->toIso8601String()]);

        return $item;
    }

    /** The person posted it (or could not): phase B never posts by itself. */
    public function outcome(MarketingContentItem $item, Model $actor, bool $published, ?string $url, ?string $note): MarketingContentItem
    {
        $this->access->assert($item->project, $actor, 'edit');
        $this->assertStatus($item, [MarketingContentItem::APPROVED, MarketingContentItem::SCHEDULED]);
        $this->assertApprovalHolds($item);
        $item->update([
            'status' => $published ? MarketingContentItem::PUBLISHED : MarketingContentItem::FAILED,
            'published_url' => $published ? $url : null,
            'review_note' => $note,
            'done_at' => now(),
        ]);
        $this->audit->record($item->project, $actor, $published ? 'content.published' : 'content.failed', $item, meta: ['url' => $url]);

        return $item;
    }

    public function done(MarketingContentItem $item, Model $actor, bool $done): MarketingContentItem
    {
        $this->access->assert($item->project, $actor, 'edit');
        if (! $item->isTask()) {
            throw new MarketingException('invalid_transition', 409, [], ['status' => $item->status]);
        }
        $item->update(['status' => $done ? MarketingContentItem::DONE : MarketingContentItem::OPEN, 'done_at' => $done ? now() : null]);
        $this->audit->record($item->project, $actor, $done ? 'task.done' : 'task.reopened', $item);

        return $item;
    }

    public function delete(MarketingContentItem $item, Model $actor): void
    {
        $this->access->assert($item->project, $actor, 'edit');
        if ($item->status === MarketingContentItem::PUBLISHED) {
            throw new MarketingException('invalid_transition', 409, [], ['status' => $item->status]);
        }
        $this->supersede($item);
        $this->audit->record($item->project, $actor, 'content.deleted', $item, before: $item->approvalPayload());
        $item->delete();
    }

    /**
     * The ad studio: A/B(/C) variants for one ad or post, written by the AI from the brief (202 + job).
     *
     * @param  array<string, mixed>  $data  validated
     * @return array{job: MarketingJob}
     */
    public function generate(MarketingProject $project, Model $actor, array $data): array
    {
        $this->access->assert($project, $actor, 'edit');
        $this->entitlement->assertRoom($project->owner, 'content_generations');
        $this->budgets->assertAiRoom($project);

        $job = $this->jobs->open($project, $actor, 'content', $project, [
            'locale' => app()->getLocale(),
            'kind' => $data['kind'] ?? 'ad',
            'channel' => $data['channel'],
            'format' => $data['format'] ?? null,
            'objective' => $data['objective'] ?? null,
            'brief' => mb_substr((string) $data['brief'], 0, 1000),
            'variants' => (int) ($data['variants'] ?? 2),
            'planned_at' => isset($data['planned_at']) ? Carbon::parse($data['planned_at'])->toIso8601String() : null,
            'plan_id' => $this->planId($project, $data['plan'] ?? null),
        ]);
        $this->audit->record($project, $actor, 'content.generation_requested', $project, meta: ['channel' => $data['channel']]);
        GenerateContentJob::dispatch($job->id)->afterCommit();

        return ['job' => $job->fresh()];
    }

    /**
     * The daily calendar (U06) from an approved GTM or marketing plan: posts, ads and tasks for the next days.
     *
     * @param  array<string, mixed>  $data  validated
     * @return array{job: MarketingJob, plan: MarketingPlan}
     */
    public function generateCalendar(MarketingProject $project, Model $actor, array $data): array
    {
        $this->access->assert($project, $actor, 'edit');
        $plan = $this->approvedPlan($project, $data['plan'] ?? null);
        $this->entitlement->assertRoom($project->owner, 'content_generations');
        $this->budgets->assertAiRoom($project);

        $running = MarketingJob::query()->where('project_id', $project->id)->where('job_type', 'calendar')
            ->whereNotIn('status', MarketingJob::FINISHED)->exists();
        if ($running) {
            throw new MarketingException('job_running', 409);
        }
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : now()->startOfDay();

        $job = $this->jobs->open($project, $actor, 'calendar', $plan, [
            'locale' => app()->getLocale(),
            'plan_id' => $plan->id,
            'plan_version_id' => $plan->current_version_id,
            'from' => $from->toDateString(),
            'days' => (int) $data['days'],
            'channels' => array_values($data['channels'] ?? []),
            'replace_drafts' => (bool) ($data['replace_drafts'] ?? true),
            'timezone' => $data['timezone'] ?? null,
        ]);
        $this->audit->record($project, $actor, 'calendar.generation_requested', $plan, meta: ['from' => $from->toDateString(), 'days' => (int) $data['days']]);
        GenerateContentJob::dispatch($job->id)->afterCommit();

        return ['job' => $job->fresh(), 'plan' => $plan];
    }

    /** The plan a calendar is made from: the one asked for, else the approved GTM plan, else the approved marketing plan. */
    public function approvedPlan(MarketingProject $project, ?string $uuid): MarketingPlan
    {
        $query = MarketingPlan::query()->where('project_id', $project->id)->where('status', 'APPROVED')->whereNotNull('current_version_id');
        $plan = $uuid !== null
            ? (clone $query)->where('uuid', $uuid)->first()
            : (clone $query)->whereIn('plan_type', ['gtm', 'marketing'])->orderByRaw("CASE WHEN plan_type = 'gtm' THEN 0 ELSE 1 END")->first();

        return $plan ?? throw new MarketingException('approved_plan_required', 409);
    }

    private function planId(MarketingProject $project, ?string $uuid): ?int
    {
        if ($uuid === null) {
            return null;
        }

        return MarketingPlan::query()->where('project_id', $project->id)->where('uuid', $uuid)->value('id')
            ?? throw new MarketingException('plan_not_found', 404);
    }

    /** @param  list<string>  $allowed */
    private function assertStatus(MarketingContentItem $item, array $allowed): void
    {
        if ($item->isTask() || ! in_array($item->status, $allowed, true)) {
            throw new MarketingException('invalid_transition', 409, [], ['status' => $item->status]);
        }
    }

    /** An approved item is posted only while its approval still matches its words (POL-01 in phase B terms). */
    private function assertApprovalHolds(MarketingContentItem $item): void
    {
        $holds = MarketingApproval::query()->where('subject_type', 'content')->where('subject_id', $item->id)
            ->where('status', MarketingApproval::ACTIVE)->where('payload_hash', MarketingAudit::hash($item->approvalPayload()))->exists();
        if (! $holds) {
            throw new MarketingException('approval_required', 409);
        }
    }

    private function supersede(MarketingContentItem $item): int
    {
        return MarketingApproval::query()->where('subject_type', 'content')->where('subject_id', $item->id)
            ->where('status', MarketingApproval::ACTIVE)->update(['status' => MarketingApproval::SUPERSEDED, 'updated_at' => now()]);
    }
}
