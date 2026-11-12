<?php

namespace Modules\Marketing\Services\Results;

use Modules\Marketing\Models\MarketingBudget;
use Modules\Marketing\Models\MarketingContentItem;
use Modules\Marketing\Models\MarketingJob;
use Modules\Marketing\Models\MarketingPlan;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Services\Budgets\BudgetService;

/**
 * The executive report (U12): today's decisions, the results of the period against the one before, the risks and
 * what to do next — built by rules from the project's own data, so it opens even when the AI is down (AT-09) and
 * never states a number nobody entered. Every line has a stable `code` and a ready text in the reader's language.
 */
class ExecutiveReport
{
    public const PERIODS = ['day' => 1, 'week' => 7, 'month' => 30];

    public function __construct(private readonly KpiService $kpis, private readonly BudgetService $budgets) {}

    /** @return array<string, mixed> */
    public function build(MarketingProject $project, string $period): array
    {
        $days = self::PERIODS[$period] ?? 7;
        $to = now()->startOfDay();
        $from = $to->copy()->subDays($days - 1);
        $current = $this->kpis->board($project, $from, $to);
        $previous = $this->kpis->board($project, $from->copy()->subDays($days), $from->copy()->subDay());

        $items = MarketingContentItem::query()->where('project_id', $project->id);
        $today = (clone $items)->whereBetween('planned_at', [now()->startOfDay(), now()->endOfDay()])
            ->whereNotIn('status', [MarketingContentItem::PUBLISHED, MarketingContentItem::DONE, MarketingContentItem::FAILED])->orderBy('planned_at')->get();
        $toReview = (clone $items)->where('status', MarketingContentItem::POLICY_REVIEW)->count();
        $overdue = (clone $items)->where('planned_at', '<', now()->startOfDay())
            ->whereIn('status', [MarketingContentItem::APPROVED, MarketingContentItem::SCHEDULED, MarketingContentItem::OPEN])->count();
        $upcoming = (clone $items)->where('planned_at', '>=', now())->count();
        $plansToReview = MarketingPlan::query()->where('project_id', $project->id)->where('status', 'READY_FOR_REVIEW')->count();
        $approvedPlan = MarketingPlan::query()->where('project_id', $project->id)->where('status', 'APPROVED')->exists();
        $pendingBudgets = MarketingBudget::query()->where('project_id', $project->id)->where('approval_status', MarketingBudget::PENDING)->count();
        $budgets = MarketingBudget::query()->where('project_id', $project->id)->where('approval_status', MarketingBudget::APPROVED)
            ->whereDate('period_end', '>=', now()->toDateString())->get()->map(fn ($b) => ['budget' => $b, 'state' => $this->budgets->state($b)]);
        $failedJobs = MarketingJob::query()->where('project_id', $project->id)->where('status', MarketingJob::FAILED)->where('created_at', '>=', now()->subDays($days))->count();

        $decisions = [];
        if ($toReview > 0) {
            $decisions[] = $this->line('content_to_review', 'action', ['count' => $toReview]);
        }
        if ($plansToReview > 0) {
            $decisions[] = $this->line('plans_to_review', 'action', ['count' => $plansToReview]);
        }
        if ($pendingBudgets > 0) {
            $decisions[] = $this->line('budgets_to_approve', 'action', ['count' => $pendingBudgets]);
        }
        foreach ($today as $item) {
            $decisions[] = $this->line($item->kind === 'task' ? 'task_today' : 'post_today', 'info', [
                'title' => $item->title, 'channel' => __('marketing.channels.'.$item->channel), 'time' => $item->planned_at?->format('H:i'),
            ]) + ['item_id' => $item->uuid, 'status' => $item->status];
        }

        $risks = [];
        foreach ($budgets as $row) {
            if ($row['state']['level'] !== 'ok') {
                $risks[] = $this->line('budget_'.$row['state']['level'], $row['state']['level'] === 'exceeded' ? 'high' : 'medium', [
                    'kind' => __('marketing.budgets.kinds.'.$row['budget']->kind), 'pct' => (string) $row['state']['pct'],
                ]);
            }
        }
        if ($overdue > 0) {
            $risks[] = $this->line('overdue_items', 'medium', ['count' => $overdue]);
        }
        if ($current['gaps']['days_with_data'] === 0) {
            $risks[] = $this->line('no_results', 'medium', []);
        } elseif ($current['gaps']['days_without_data'] > 0) {
            $risks[] = $this->line('missing_days', 'low', ['count' => $current['gaps']['days_without_data']]);
        }
        if ($current['gaps']['channels_without_revenue'] !== []) {
            $risks[] = $this->line('no_revenue_tracking', 'low', ['channels' => $this->channels($current['gaps']['channels_without_revenue'])]);
        }
        if ($failedJobs > 0) {
            $risks[] = $this->line('failed_jobs', 'low', ['count' => $failedJobs]);
        }

        $recommendations = [];
        if (! $approvedPlan) {
            $recommendations[] = $this->line('approve_a_plan', 'action', []);
        } elseif ($upcoming === 0) {
            $recommendations[] = $this->line('generate_calendar', 'action', []);
        }
        if ($current['gaps']['days_with_data'] === 0) {
            $recommendations[] = $this->line('import_results', 'action', []);
        }
        $best = collect($current['by_channel'])->filter(fn ($c) => $c['measured']['rates']['cpa_minor']['value'] !== null && ($c['measured']['totals']['conversions'] ?? 0) >= 5)
            ->sortBy(fn ($c) => $c['measured']['rates']['cpa_minor']['value']);
        if ($best->count() >= 2) {
            // A comparison of what was measured, not a cause: worth a controlled test before moving money (spec 08 step 12).
            $recommendations[] = $this->line('test_best_channel', 'info', ['channel' => __('marketing.channels.'.$best->first()['channel'])]);
        }
        foreach ($budgets as $row) {
            if ($row['state']['level'] === 'exceeded') {
                $recommendations[] = $this->line('review_budget', 'action', ['kind' => __('marketing.budgets.kinds.'.$row['budget']->kind)]);
            }
        }

        return [
            'period' => $period,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'generated_at' => now()->toIso8601String(),
            'currency' => $project->budget_currency,
            'decisions' => $decisions,
            'results' => [
                'current' => $current['measured'],
                'previous' => $previous['measured'],
                'change_pct' => $this->changes($current['measured']['totals'], $previous['measured']['totals']),
                'estimated' => $current['estimated'],
                'last_updated_at' => $current['last_updated_at'],
                'days_with_data' => $current['gaps']['days_with_data'],
            ],
            'risks' => $risks,
            'recommendations' => $recommendations,
            'note' => __('marketing.report.note'),
        ];
    }

    /** @return array<string, ?float> */
    private function changes(array $now, array $before): array
    {
        $out = [];
        foreach ($now as $field => $value) {
            $prev = $before[$field] ?? null;
            $out[$field] = $value === null || $prev === null || $prev === 0 ? null : round(($value - $prev) * 100 / $prev, 1);
        }

        return $out;
    }

    private function channels(array $channels): string
    {
        return collect($channels)->map(fn ($c) => __('marketing.channels.'.$c))->implode('، ');
    }

    /** @return array{code: string, level: string, text: string} */
    private function line(string $code, string $level, array $vars): array
    {
        return ['code' => $code, 'level' => $level, 'text' => __('marketing.report.lines.'.$code, $vars)];
    }
}
