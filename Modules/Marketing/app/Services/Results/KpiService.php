<?php

namespace Modules\Marketing\Services\Results;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Marketing\Models\MarketingMetric;
use Modules\Marketing\Models\MarketingProject;

/**
 * The KPI board (U11, AT-10/15/16): measured results (`imported`/`actual`) and estimates are added up **apart** and
 * never mixed. A rate is computed only from what was entered: a missing number gives `value: null` with a reason,
 * never a made-up 0, and nothing is divided by zero.
 */
class KpiService
{
    /** @return array<string, mixed> */
    public function board(MarketingProject $project, Carbon $from, Carbon $to): array
    {
        $rows = MarketingMetric::query()->where('project_id', $project->id)
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])->orderBy('metric_date')->get();
        $measured = $rows->whereIn('basis', MarketingMetric::MEASURED);
        $estimated = $rows->where('basis', 'estimated');

        $days = (int) $from->diffInDays($to) + 1;
        $measuredDays = $measured->map(fn ($r) => $r->metric_date->toDateString())->unique()->count();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'currency' => $project->budget_currency,
            'last_updated_at' => $rows->max('updated_at')?->toIso8601String(),
            'measured' => $this->group($measured),
            'estimated' => $estimated->isEmpty() ? null : $this->group($estimated),
            'by_channel' => $rows->groupBy('channel')->map(fn (Collection $g, string $channel) => [
                'channel' => $channel,
                'measured' => $this->group($g->whereIn('basis', MarketingMetric::MEASURED)),
                'estimated' => $g->where('basis', 'estimated')->isEmpty() ? null : $this->group($g->where('basis', 'estimated')),
                'last_date' => $g->max('metric_date')?->toDateString(),
            ])->sortByDesc(fn ($c) => $c['measured']['totals']['spend_minor'] ?? 0)->values()->all(),
            'series' => $measured->groupBy(fn ($r) => $r->metric_date->toDateString())->map(fn (Collection $g, string $date) => [
                'date' => $date,
                'impressions' => self::sum($g, 'impressions'),
                'clicks' => self::sum($g, 'clicks'),
                'spend_minor' => self::sum($g, 'spend_minor'),
                'conversions' => self::sum($g, 'conversions'),
                'revenue_minor' => self::sum($g, 'revenue_minor'),
            ])->values()->all(),
            'gaps' => [
                'days' => $days,
                'days_with_data' => $measuredDays,
                'days_without_data' => max(0, $days - $measuredDays),
                'channels_without_revenue' => $measured->groupBy('channel')->filter(fn (Collection $g) => self::sum($g, 'revenue_minor') === null)->keys()->values()->all(),
                'channels_without_spend' => $measured->groupBy('channel')->filter(fn (Collection $g) => self::sum($g, 'spend_minor') === null)->keys()->values()->all(),
            ],
            'sources' => $rows->groupBy('source')->map->count()->all(),
        ];
    }

    /** @return array{rows: int, totals: array<string, ?int>, rates: array<string, array{value: ?float, reason: ?string}>} */
    public function group(Collection $rows): array
    {
        $t = [];
        foreach (MarketingMetric::FIELDS as $field) {
            $t[$field] = self::sum($rows, $field);
        }

        return ['rows' => $rows->count(), 'totals' => $t, 'rates' => self::rates($rows)];
    }

    /**
     * Each rate from the rows that have **both** of its numbers: clicks entered without impressions don't raise the
     * click rate, and spend without revenue doesn't lower the return on ad spend.
     *
     * @return array<string, array{value: ?float, reason: ?string}>
     */
    public static function rates(Collection $rows): array
    {
        $revenueKnown = $rows->contains(fn ($r) => $r->revenue_minor !== null);

        return [
            'ctr_pct' => self::paired($rows, 'clicks', 'impressions', 100, 'no_impressions', 2),
            'cpc_minor' => self::paired($rows, 'spend_minor', 'clicks', 1, 'no_clicks', 0),
            'cpa_minor' => self::paired($rows, 'spend_minor', 'conversions', 1, 'no_conversions', 0),
            'conversion_rate_pct' => self::paired($rows, 'conversions', 'clicks', 100, 'no_clicks', 2),
            'roas' => $revenueKnown
                ? self::paired($rows, 'revenue_minor', 'spend_minor', 1, 'no_spend', 2)
                : ['value' => null, 'reason' => 'no_revenue_data'],
        ];
    }

    /** @return array{value: ?float, reason: ?string} */
    private static function paired(Collection $rows, string $top, string $bottom, int $scale, string $zeroReason, int $places): array
    {
        $both = $rows->filter(fn ($r) => $r->{$top} !== null && $r->{$bottom} !== null);

        return self::ratio(self::sum($both, $top), self::sum($both, $bottom), $scale, $zeroReason, $places);
    }

    /** Sum of a field over rows that have it; null when no row has it (unknown is not zero). */
    public static function sum(Collection $rows, string $field): ?int
    {
        $known = $rows->filter(fn ($r) => $r->{$field} !== null);

        return $known->isEmpty() ? null : (int) $known->sum($field);
    }

    /** @return array{value: ?float, reason: ?string} */
    private static function ratio(?int $top, ?int $bottom, int $scale, string $zeroReason, int $places): array
    {
        if ($top === null || $bottom === null) {
            return ['value' => null, 'reason' => 'missing_data'];
        }
        if ($bottom === 0) {
            return ['value' => null, 'reason' => $zeroReason];
        }

        return ['value' => round($top * $scale / $bottom, $places), 'reason' => null];
    }
}
