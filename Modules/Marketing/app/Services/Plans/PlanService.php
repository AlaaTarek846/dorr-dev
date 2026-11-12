<?php

namespace Modules\Marketing\Services\Plans;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Marketing\Enums\PlanStatus;
use Modules\Marketing\Exceptions\MarketingException;
use Modules\Marketing\Jobs\GeneratePlanJob;
use Modules\Marketing\Models\MarketingApproval;
use Modules\Marketing\Models\MarketingJob;
use Modules\Marketing\Models\MarketingPlan;
use Modules\Marketing\Models\MarketingPlanVersion;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Models\MarketingSource;
use Modules\Marketing\Services\Finance\FinancialCalculator;
use Modules\Marketing\Services\MarketingAccess;
use Modules\Marketing\Services\MarketingAudit;
use Modules\Marketing\Services\MarketingJobs;
use Modules\Marketing\Services\Packages\MarketingEntitlement;

/**
 * Plans (U04/U05, step 09). One plan per type per project; every change is a new version — the AI writing it again,
 * a person editing it, or new financial inputs. A new version sends the plan back to DRAFT and leaves any approval
 * of the old one `superseded` (POL-02/14, AT-03). The owner or a manager approves one exact version, by its hash.
 */
class PlanService
{
    public function __construct(
        private readonly MarketingAccess $access,
        private readonly MarketingAudit $audit,
        private readonly MarketingEntitlement $entitlement,
        private readonly MarketingJobs $jobs,
        private readonly PlanCritic $critic,
        private readonly FinancialCalculator $calculator,
    ) {}

    /** @return array{plan: MarketingPlan, job: MarketingJob} */
    public function generate(MarketingProject $project, Model $actor, string $type, ?string $instructions): array
    {
        $this->access->assert($project, $actor, 'edit');
        $this->entitlement->assertRoom($project->owner, 'plan_generations');

        $running = MarketingJob::query()->where('project_id', $project->id)->where('job_type', 'plan')
            ->whereNotIn('status', MarketingJob::FINISHED)->where('payload->plan_type', $type)->exists();
        if ($running) {
            throw new MarketingException('job_running', 409);
        }

        [$plan, $job] = DB::transaction(function () use ($project, $actor, $type, $instructions) {
            $plan = MarketingPlan::query()->firstOrCreate(
                ['project_id' => $project->id, 'plan_type' => $type],
                ['status' => PlanStatus::Draft, 'created_by_type' => $actor->getMorphClass(), 'created_by_id' => $actor->getKey()],
            );
            if ($plan->status === PlanStatus::Archived) {
                $plan->update(['status' => PlanStatus::Draft]);
            }
            $job = $this->jobs->open($project, $actor, 'plan', $plan, [
                'plan_type' => $type,
                'locale' => app()->getLocale(),
                'instructions' => $instructions === null ? null : mb_substr($instructions, 0, 1000),
            ]);
            $this->audit->record($project, $actor, 'plan.generation_requested', $plan, meta: ['type' => $type]);

            return [$plan, $job];
        });

        GeneratePlanJob::dispatch($job->id)->afterCommit();

        return ['plan' => $plan->fresh(), 'job' => $job->fresh()];
    }

    /**
     * Writes the next version (the AI's, an edit, or new finance inputs) — the only way a plan's content changes.
     *
     * @param  array<string, mixed>  $structured  normalized
     * @param  list<array<string, mixed>>  $warnings
     */
    public function writeVersion(MarketingPlan $plan, ?Model $actor, array $structured, array $warnings, string $origin): MarketingPlanVersion
    {
        return DB::transaction(function () use ($plan, $actor, $structured, $warnings, $origin) {
            $plan = MarketingPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $number = (int) $plan->versions()->max('version_no') + 1;
            $version = $plan->versions()->create([
                'version_no' => $number,
                'structured_json' => $structured,
                'content_hash' => MarketingAudit::hash($structured),
                'assumption_set' => $structured['assumptions'] ?? [],
                'critic' => $warnings,
                'origin' => $origin,
                'created_by_type' => $actor?->getMorphClass(),
                'created_by_id' => $actor?->getKey(),
            ]);

            $superseded = MarketingApproval::query()->where('subject_type', 'plan')->where('subject_id', $plan->id)
                ->where('status', MarketingApproval::ACTIVE)->update(['status' => MarketingApproval::SUPERSEDED, 'updated_at' => now()]);
            $plan->update(['current_version_id' => $version->id, 'status' => PlanStatus::Draft]);
            $this->storeScenarios($version);

            $this->audit->record($plan->project, $actor, 'plan.version_written', $version, after: $structured, meta: [
                'version' => $number, 'origin' => $origin, 'approvals_superseded' => $superseded,
            ]);

            return $version;
        });
    }

    /**
     * A person's edit of the whole plan. `version` must be the current one (409 otherwise): nobody overwrites a
     * version they have not seen.
     *
     * @param  array<string, mixed>  $content
     */
    public function edit(MarketingPlan $plan, Model $actor, int $version, array $content): MarketingPlanVersion
    {
        $this->access->assert($plan->project, $actor, 'edit');
        $current = $this->assertCurrent($plan, $version);
        $this->assertNotArchived($plan);

        $structured = PlanSchema::normalize($plan->plan_type, $content);
        if (isset($current->structured_json['finance'])) {
            $structured['finance'] = $current->structured_json['finance'];
        }
        $errors = PlanSchema::errors($plan->plan_type, $structured);
        if ($errors !== []) {
            throw new MarketingException('plan_incomplete', 422, [], ['missing' => $errors]);
        }
        $reviewed = $this->critic->review($plan->plan_type, $structured, $plan->project()->with('profile')->first(), $this->knownRefs($plan->project));
        if (MarketingAudit::hash($reviewed['plan']) === $current->content_hash) {
            throw new MarketingException('no_changes', 422);
        }

        return $this->writeVersion($plan, $actor, $reviewed['plan'], $reviewed['warnings'], 'edit');
    }

    /**
     * New financial inputs: a new version whose scenarios are computed by code (MK-A08).
     *
     * @param  array<string, int|float|null>  $inputs
     */
    public function setFinance(MarketingPlan $plan, Model $actor, int $version, array $inputs): MarketingPlanVersion
    {
        $this->access->assert($plan->project, $actor, 'edit');
        $current = $this->assertCurrent($plan, $version);
        $this->assertNotArchived($plan);

        $structured = $current->structured_json;
        $structured['finance'] = [
            'currency' => $plan->project->budget_currency,
            'inputs' => collect(FinancialCalculator::INPUTS)->mapWithKeys(fn ($k) => [$k => $inputs[$k] ?? null])->all(),
        ];
        if (MarketingAudit::hash($structured) === $current->content_hash) {
            throw new MarketingException('no_changes', 422);
        }

        return $this->writeVersion($plan, $actor, $structured, $current->critic ?? [], 'edit');
    }

    public function submit(MarketingPlan $plan, Model $actor, int $version): MarketingPlan
    {
        $this->access->assert($plan->project, $actor, 'edit');
        $current = $this->assertCurrent($plan, $version);
        if ($plan->status !== PlanStatus::Draft) {
            throw new MarketingException('invalid_transition', 409, [], ['status' => $plan->status->value]);
        }
        if (PlanCritic::blocking($current->critic ?? [])) {
            throw new MarketingException('plan_has_blocking_issues', 422, [], ['critic' => $current->critic]);
        }
        $plan->update(['status' => PlanStatus::ReadyForReview]);
        $this->audit->record($plan->project, $actor, 'plan.submitted', $plan, meta: ['version' => $version]);

        return $plan->fresh();
    }

    public function approve(MarketingPlan $plan, Model $actor, int $version): MarketingApproval
    {
        $this->access->assert($plan->project, $actor, 'approve');

        return DB::transaction(function () use ($plan, $actor, $version) {
            $plan = MarketingPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $current = $this->assertCurrent($plan, $version);
            if ($plan->status !== PlanStatus::ReadyForReview) {
                throw new MarketingException('invalid_transition', 409, [], ['status' => $plan->status->value]);
            }

            $approval = MarketingApproval::query()->create([
                'project_id' => $plan->project_id,
                'subject_type' => 'plan',
                'subject_id' => $plan->id,
                'subject_version' => $current->version_no,
                'payload_hash' => $current->content_hash,
                'scope' => 'plan',
                'status' => MarketingApproval::ACTIVE,
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'approved_at' => now(),
            ]);
            $plan->update(['status' => PlanStatus::Approved]);
            $this->audit->record($plan->project, $actor, 'plan.approved', $plan, after: ['hash' => $current->content_hash], meta: ['version' => $version, 'approval' => $approval->uuid]);

            return $approval;
        });
    }

    /** Back to DRAFT from review (the owner wants changes); an approved plan only leaves APPROVED by a new version. */
    public function reject(MarketingPlan $plan, Model $actor, int $version, ?string $note): MarketingPlan
    {
        $this->access->assert($plan->project, $actor, 'approve');
        $this->assertCurrent($plan, $version);
        if ($plan->status !== PlanStatus::ReadyForReview) {
            throw new MarketingException('invalid_transition', 409, [], ['status' => $plan->status->value]);
        }
        $plan->update(['status' => PlanStatus::Draft]);
        $this->audit->record($plan->project, $actor, 'plan.returned', $plan, meta: ['version' => $version, 'note' => $note]);

        return $plan->fresh();
    }

    public function archive(MarketingPlan $plan, Model $actor): MarketingPlan
    {
        $this->access->assert($plan->project, $actor, 'owner');
        DB::transaction(function () use ($plan, $actor) {
            MarketingApproval::query()->where('subject_type', 'plan')->where('subject_id', $plan->id)->where('status', MarketingApproval::ACTIVE)
                ->update(['status' => MarketingApproval::REVOKED, 'revoked_at' => now(), 'updated_at' => now()]);
            $plan->update(['status' => PlanStatus::Archived]);
            $this->audit->record($plan->project, $actor, 'plan.archived', $plan);
        });

        return $plan->fresh();
    }

    /** The project's sources as plans cite them: `S<id>` (unique across all of its runs). */
    public function knownRefs(MarketingProject $project): array
    {
        return MarketingSource::query()->whereIn('research_run_id', $project->researchRuns()->select('id'))
            ->pluck('id')->map(fn ($id) => 'S'.$id)->all();
    }

    public function activeApproval(MarketingPlan $plan): ?MarketingApproval
    {
        return MarketingApproval::query()->where('subject_type', 'plan')->where('subject_id', $plan->id)
            ->where('status', MarketingApproval::ACTIVE)->latest('id')->first();
    }

    private function storeScenarios(MarketingPlanVersion $version): void
    {
        $inputs = $version->structured_json['finance']['inputs'] ?? null;
        if (! is_array($inputs)) {
            return;
        }
        foreach ($this->calculator->scenarios($inputs) as $name => $scenario) {
            $version->scenarios()->create([
                'scenario' => $name,
                'inputs' => $scenario['inputs'],
                'outputs' => $scenario['outputs'],
                'calculation_version' => (string) config('marketing.calculation_version', 'fin-1'),
            ]);
        }
    }

    private function assertCurrent(MarketingPlan $plan, int $version): MarketingPlanVersion
    {
        $current = $plan->currentVersion()->first() ?? throw new MarketingException('plan_not_ready', 409);
        if ($current->version_no !== $version) {
            throw new MarketingException('version_conflict', 409, [], ['current_version' => $current->version_no]);
        }

        return $current;
    }

    private function assertNotArchived(MarketingPlan $plan): void
    {
        if ($plan->status === PlanStatus::Archived) {
            throw new MarketingException('invalid_transition', 409, [], ['status' => $plan->status->value]);
        }
    }
}
