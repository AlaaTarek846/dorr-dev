<?php

namespace Modules\Marketing\Services\Optimisation;

use Illuminate\Support\Collection;
use Modules\Marketing\Models\MarketingBudget;
use Modules\Marketing\Models\MarketingMetric;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Services\Budgets\BudgetService;
use Modules\Marketing\Services\Results\KpiService;

/**
 * Suggested optimisations (phase D): rules over the measured results, each with the numbers behind it. They are
 * suggestions for a person — nothing is changed automatically (spec 01: no unauthorised spend change).
 */
class OptimisationAdvisor
{
    public const MIN_SPEND_MINOR = 10000;

    public function __construct(private readonly BudgetService $budgets) {}

    /** @return list<array{code: string, priority: string, channel: ?string, evidence: array<string, mixed>, text: string}> */
    public function suggest(MarketingProject $project): array
    {
        $rows = MarketingMetric::query()->where('project_id', $project->id)->whereIn('basis', MarketingMetric::MEASURED)
            ->whereDate('metric_date', '>=', now()->subDays(27)->toDateString())->get();
        $out = [];
        if ($rows->isEmpty()) {
            return [$this->line('need_results', 'high', null, [])];
        }

        // CPA per channel against the project's average.
        $all = KpiService::rates($rows)['cpa_minor']['value'];
        $channels = $rows->groupBy('channel')->map(fn (Collection $g) => [
            'cpa' => KpiService::rates($g)['cpa_minor']['value'],
            'spend' => KpiService::sum($g, 'spend_minor') ?? 0,
            'conversions' => KpiService::sum($g, 'conversions'),
        ]);
        foreach ($channels as $channel => $c) {
            if ($c['spend'] >= self::MIN_SPEND_MINOR && $c['conversions'] === 0) {
                $out[] = $this->line('spend_without_results', 'high', $channel, ['spend_minor' => $c['spend']]);
            } elseif ($all !== null && $c['cpa'] !== null && $c['spend'] >= self::MIN_SPEND_MINOR && $c['cpa'] > 2 * $all) {
                $out[] = $this->line('expensive_channel', 'medium', $channel, ['cpa_minor' => $c['cpa'], 'average_cpa_minor' => $all]);
            }
        }
        $best = $channels->filter(fn ($c) => $c['cpa'] !== null && ($c['conversions'] ?? 0) >= 5)->sortBy('cpa');
        if ($best->count() >= 2) {
            $out[] = $this->line('test_shift_budget', 'medium', $best->keys()->first(), ['cpa_minor' => $best->first()['cpa'], 'next_cpa_minor' => $best->values()[1]['cpa']]);
        }

        // Ad fatigue: click rate this week vs the week before, per channel.
        foreach ($rows->groupBy('channel') as $channel => $g) {
            $now = KpiService::rates($g->filter(fn ($r) => $r->metric_date->gte(now()->subDays(6)->startOfDay())))['ctr_pct']['value'];
            $before = KpiService::rates($g->filter(fn ($r) => $r->metric_date->lt(now()->subDays(6)->startOfDay()) && $r->metric_date->gte(now()->subDays(13)->startOfDay())))['ctr_pct']['value'];
            if ($now !== null && $before !== null && $before > 0 && $now < $before * 0.7) {
                $out[] = $this->line('creative_fatigue', 'medium', $channel, ['ctr_pct' => $now, 'previous_ctr_pct' => $before]);
            }
        }

        // Tracking gaps.
        $noRevenue = $rows->groupBy('channel')->filter(fn ($g) => KpiService::sum($g, 'revenue_minor') === null && (KpiService::sum($g, 'spend_minor') ?? 0) > 0)->keys();
        if ($noRevenue->isNotEmpty()) {
            $out[] = $this->line('track_revenue', 'low', null, ['channels' => $noRevenue->values()->all()]);
        }

        // Pacing: at this daily rate the ads cap is passed before the period ends.
        $budget = MarketingBudget::query()->where('project_id', $project->id)->where('kind', 'ads')->where('approval_status', MarketingBudget::APPROVED)
            ->whereDate('period_start', '<=', now()->toDateString())->whereDate('period_end', '>=', now()->toDateString())->first();
        if ($budget !== null) {
            $state = $this->budgets->state($budget);
            $elapsed = max(1, $budget->period_start->diffInDays(now()) + 1);
            $length = $budget->period_start->diffInDays($budget->period_end) + 1;
            $projected = (int) round($state['used'] / $elapsed * $length);
            if ($state['cap'] > 0 && $projected > $state['cap'] && $state['level'] !== 'exceeded') {
                $out[] = $this->line('pacing_over_cap', 'high', null, ['projected_minor' => $projected, 'cap_minor' => $state['cap']]);
            }
        }

        $order = ['high' => 0, 'medium' => 1, 'low' => 2];

        return collect($out)->sortBy(fn ($l) => $order[$l['priority']])->values()->all();
    }

    private function line(string $code, string $priority, ?string $channel, array $evidence): array
    {
        return [
            'code' => $code, 'priority' => $priority, 'channel' => $channel, 'evidence' => $evidence,
            'text' => __('marketing.optimisations.'.$code, ['channel' => $channel === null ? '' : __('marketing.channels.'.$channel)]),
        ];
    }
}
