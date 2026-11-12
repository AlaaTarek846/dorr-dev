<?php

namespace Modules\Marketing\Services\Budgets;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Marketing\Exceptions\MarketingException;
use Modules\Marketing\Models\MarketingAiCall;
use Modules\Marketing\Models\MarketingBudget;
use Modules\Marketing\Models\MarketingMetric;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Models\MarketingProjectMember;
use Modules\Marketing\Services\MarketingAccess;
use Modules\Marketing\Services\MarketingAudit;
use Throwable;

/**
 * Budgets (U08). Two caps that never mix (spec 09): `ads` — money spent on the platforms, counted from the results
 * imported for the period (estimates are shown apart, never counted); `ai` — marketing AI calls, which the server
 * refuses once an approved cap is used up (POL-10). Crossing the alert line or the cap tells the team once.
 * The owner sets or approves a cap; a manager may propose one or change one, and then it waits for the owner (AT-13).
 */
class BudgetService
{
    private const RANK = ['ok' => 0, 'warning' => 1, 'exceeded' => 2];

    public function __construct(
        private readonly MarketingAccess $access,
        private readonly MarketingAudit $audit,
        private readonly NotificationCenter $notifications,
    ) {}

    /** @param  array<string, mixed>  $data  validated */
    public function create(MarketingProject $project, Model $actor, array $data): MarketingBudget
    {
        $role = $this->access->assert($project, $actor, 'edit');
        $this->assertNoOverlap($project, $data['kind'], $data['period_start'], $data['period_end'], null);
        $owner = $role->isOwner();

        $budget = MarketingBudget::query()->create([
            'project_id' => $project->id,
            'kind' => $data['kind'],
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'currency' => $data['kind'] === 'ads' ? $project->budget_currency : null,
            'cap_minor' => $data['kind'] === 'ads' ? $data['cap_minor'] : null,
            'cap_calls' => $data['kind'] === 'ai' ? $data['cap_calls'] : null,
            'alert_pct' => $data['alert_pct'] ?? 80,
            'note' => $data['note'] ?? null,
            'approval_status' => $owner ? MarketingBudget::APPROVED : MarketingBudget::PENDING,
            'created_by_type' => $actor->getMorphClass(),
            'created_by_id' => $actor->getKey(),
            'approved_by_type' => $owner ? $actor->getMorphClass() : null,
            'approved_by_id' => $owner ? $actor->getKey() : null,
            'approved_at' => $owner ? now() : null,
        ]);
        $this->audit->record($project, $actor, 'budget.created', $budget, after: $this->snapshot($budget));
        $this->checkAlerts($project);

        return $budget->fresh();
    }

    /** @param  array<string, mixed>  $data  validated, with `lock_version` */
    public function update(MarketingBudget $budget, Model $actor, array $data): MarketingBudget
    {
        $role = $this->access->assert($budget->project, $actor, 'edit');

        return DB::transaction(function () use ($budget, $actor, $data, $role) {
            $budget = MarketingBudget::query()->lockForUpdate()->findOrFail($budget->id);
            if ($budget->approval_status === MarketingBudget::CLOSED) {
                throw new MarketingException('invalid_transition', 409, [], ['status' => $budget->approval_status]);
            }
            if ((int) $data['lock_version'] !== $budget->lock_version) {
                throw new MarketingException('version_conflict', 409, [], ['lock_version' => $budget->lock_version]);
            }
            $before = $this->snapshot($budget);
            $end = $data['period_end'] ?? $budget->period_end->toDateString();
            $this->assertNoOverlap($budget->project, $budget->kind, $budget->period_start->toDateString(), $end, $budget->id);

            $budget->fill([
                'period_end' => $end,
                'cap_minor' => $budget->kind === 'ads' ? ($data['cap_minor'] ?? $budget->cap_minor) : null,
                'cap_calls' => $budget->kind === 'ai' ? ($data['cap_calls'] ?? $budget->cap_calls) : null,
                'alert_pct' => $data['alert_pct'] ?? $budget->alert_pct,
                'note' => array_key_exists('note', $data) ? $data['note'] : $budget->note,
            ]);
            $capChanged = $budget->isDirty(['cap_minor', 'cap_calls', 'period_end']);
            if ($capChanged) {
                // A new cap is a new decision: the owner's own change counts as approved, anyone else's waits (AT-13).
                $budget->fill($role->isOwner()
                    ? ['approval_status' => MarketingBudget::APPROVED, 'approved_by_type' => $actor->getMorphClass(), 'approved_by_id' => $actor->getKey(), 'approved_at' => now()]
                    : ['approval_status' => MarketingBudget::PENDING, 'approved_by_type' => null, 'approved_by_id' => null, 'approved_at' => null]);
                $budget->last_alert_level = null;
            }
            $budget->lock_version++;
            $budget->save();
            $this->audit->record($budget->project, $actor, 'budget.updated', $budget, before: $before, after: $this->snapshot($budget));
            $this->checkAlerts($budget->project);

            return $budget->fresh();
        });
    }

    public function approve(MarketingBudget $budget, Model $actor, int $lockVersion): MarketingBudget
    {
        $this->access->assert($budget->project, $actor, 'owner');
        if ($lockVersion !== $budget->lock_version) {
            throw new MarketingException('version_conflict', 409, [], ['lock_version' => $budget->lock_version]);
        }
        if ($budget->approval_status !== MarketingBudget::PENDING) {
            throw new MarketingException('invalid_transition', 409, [], ['status' => $budget->approval_status]);
        }
        $budget->update(['approval_status' => MarketingBudget::APPROVED, 'approved_by_type' => $actor->getMorphClass(), 'approved_by_id' => $actor->getKey(), 'approved_at' => now()]);
        $this->audit->record($budget->project, $actor, 'budget.approved', $budget, after: $this->snapshot($budget));
        $this->checkAlerts($budget->project);

        return $budget->fresh();
    }

    public function close(MarketingBudget $budget, Model $actor): MarketingBudget
    {
        $this->access->assert($budget->project, $actor, 'owner');
        $budget->update(['approval_status' => MarketingBudget::CLOSED]);
        $this->audit->record($budget->project, $actor, 'budget.closed', $budget);

        return $budget->fresh();
    }

    /**
     * How much of the cap is used. Ads: spend imported for the period, in the project's currency (estimates apart).
     *
     * @return array{used: int, estimated: ?int, cap: int, remaining: int, pct: ?float, level: string}
     */
    public function state(MarketingBudget $budget): array
    {
        if ($budget->kind === 'ai') {
            $used = MarketingAiCall::query()->where('project_id', $budget->project_id)
                ->whereBetween('created_at', [$budget->period_start->copy()->startOfDay(), $budget->period_end->copy()->endOfDay()])->count();
            $estimated = null;
        } else {
            $rows = MarketingMetric::query()->where('project_id', $budget->project_id)
                ->whereBetween('metric_date', [$budget->period_start->toDateString(), $budget->period_end->toDateString()])
                ->where(fn ($q) => $q->whereNull('currency')->orWhere('currency', $budget->currency));
            $used = (int) (clone $rows)->whereIn('basis', MarketingMetric::MEASURED)->sum('spend_minor');
            $estimated = (int) (clone $rows)->where('basis', 'estimated')->sum('spend_minor');
        }
        $cap = $budget->cap();
        $pct = $cap > 0 ? round($used * 100 / $cap, 1) : null;

        return [
            'used' => $used,
            'estimated' => $estimated,
            'cap' => $cap,
            'remaining' => max(0, $cap - $used),
            'pct' => $pct,
            'level' => $this->level($budget, $used),
        ];
    }

    /** Refuses a marketing AI call once an approved AI cap that covers today is used up (POL-10). */
    public function assertAiRoom(MarketingProject $project): void
    {
        $today = now()->toDateString();
        $budgets = MarketingBudget::query()->where('project_id', $project->id)->where('kind', 'ai')
            ->where('approval_status', MarketingBudget::APPROVED)->whereDate('period_start', '<=', $today)->whereDate('period_end', '>=', $today)->get();
        foreach ($budgets as $budget) {
            $state = $this->state($budget);
            if ($state['used'] >= $state['cap']) {
                throw new MarketingException('ai_budget_reached', 429, [], ['cap' => $state['cap'], 'used' => $state['used'], 'budget_id' => $budget->uuid]);
            }
        }
    }

    public function recordAiCall(MarketingProject $project, ?Model $actor, string $source, int $inputChars, int $outputChars): void
    {
        MarketingAiCall::query()->create([
            'project_id' => $project->id,
            'source' => mb_substr($source, 0, 60),
            'input_chars' => $inputChars,
            'output_chars' => $outputChars,
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
        ]);
        $this->checkAlerts($project, 'ai');
    }

    /** Tells the team once when an approved budget crosses its alert line or its cap (and again only after it drops back). */
    public function checkAlerts(MarketingProject $project, ?string $kind = null): void
    {
        $budgets = MarketingBudget::query()->where('project_id', $project->id)->where('approval_status', MarketingBudget::APPROVED)
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))->get();
        foreach ($budgets as $budget) {
            $level = $this->state($budget)['level'];
            $last = $budget->last_alert_level ?? 'ok';
            if ($level === $last) {
                continue;
            }
            $budget->forceFill(['last_alert_level' => $level === 'ok' ? null : $level])->saveQuietly();
            if (self::RANK[$level] > self::RANK[$last]) {
                $this->audit->record($project, null, 'budget.alert', $budget, meta: ['level' => $level]);
                $this->notify($project, $budget, $level);
            }
        }
    }

    /** @return array<string, mixed> */
    public function snapshot(MarketingBudget $budget): array
    {
        return [
            'kind' => $budget->kind,
            'period_start' => $budget->period_start?->toDateString(),
            'period_end' => $budget->period_end?->toDateString(),
            'cap' => $budget->cap(),
            'currency' => $budget->currency,
            'alert_pct' => $budget->alert_pct,
            'status' => $budget->approval_status,
        ];
    }

    private function level(MarketingBudget $budget, int $used): string
    {
        $cap = $budget->cap();
        if ($cap <= 0) {
            return 'ok';
        }
        if ($used >= $cap) {
            return 'exceeded';
        }

        return $used * 100 >= $cap * $budget->alert_pct ? 'warning' : 'ok';
    }

    private function assertNoOverlap(MarketingProject $project, string $kind, string $start, string $end, ?int $except): void
    {
        $overlap = MarketingBudget::query()->where('project_id', $project->id)->where('kind', $kind)
            ->where('approval_status', '!=', MarketingBudget::CLOSED)->when($except !== null, fn ($q) => $q->where('id', '!=', $except))
            ->whereDate('period_start', '<=', $end)->whereDate('period_end', '>=', $start)->exists();
        if ($overlap) {
            throw new MarketingException('budget_overlap', 409);
        }
    }

    private function notify(MarketingProject $project, MarketingBudget $budget, string $level): void
    {
        try {
            $members = MarketingProjectMember::query()->where('project_id', $project->id)->with('member')->get()
                ->pluck('member')->filter()->values();
            $this->notifications->send(
                $members,
                'marketing.budget.alert',
                'marketing_budget_'.$level.'_title',
                'marketing_budget_'.$level.'_body',
                ['project' => $project->name, 'kind' => $budget->kind, 'pct' => (string) ($this->state($budget)['pct'] ?? '')],
                ['type' => 'marketing_budget', 'project_id' => $project->uuid, 'budget_id' => $budget->uuid, 'level' => $level],
            );
        } catch (Throwable $e) {
            Log::warning('[Marketing] budget alert failed: '.$e->getMessage());
        }
    }
}
