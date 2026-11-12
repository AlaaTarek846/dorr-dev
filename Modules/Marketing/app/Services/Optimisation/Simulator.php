<?php

namespace Modules\Marketing\Services\Optimisation;

use Modules\Marketing\Services\Finance\FinancialCalculator;

/**
 * Advanced simulation (phase D), deterministic: which input moves the monthly profit most (each input pushed down and up
 * by `swing_pct` while the others stay at base — a tornado table), and a grid of profit for spend × conversions.
 * Estimates from the person's inputs; never a forecast of results.
 */
class Simulator
{
    public const VARIED = ['price_minor', 'variable_cost_minor', 'fixed_costs_minor', 'marketing_spend_minor', 'expected_new_customers', 'monthly_churn_pct'];

    public function __construct(private readonly FinancialCalculator $calculator) {}

    /** @return array<string, mixed> */
    public function run(array $base, int $swingPct): array
    {
        $profit = fn (array $in) => $this->calculator->compute($in)['monthly_profit_minor']['value'] ?? null;
        $baseProfit = $profit($base);
        $tornado = [];
        foreach (self::VARIED as $key) {
            if (! isset($base[$key]) || $base[$key] === null) {
                continue;
            }
            $low = $profit([$key => $base[$key] * (1 - $swingPct / 100)] + $base);
            $high = $profit([$key => $base[$key] * (1 + $swingPct / 100)] + $base);
            if ($low === null || $high === null) {
                continue;
            }
            $tornado[] = ['input' => $key, 'low_profit_minor' => (int) $low, 'high_profit_minor' => (int) $high, 'swing_minor' => (int) abs($high - $low)];
        }
        usort($tornado, fn ($a, $b) => $b['swing_minor'] <=> $a['swing_minor']);

        $grid = [];
        if (isset($base['marketing_spend_minor'], $base['expected_new_customers'])) {
            foreach ([-50, -25, 0, 25, 50] as $s) {
                $row = [];
                foreach ([-50, -25, 0, 25, 50] as $c) {
                    $row[] = $profit(['marketing_spend_minor' => $base['marketing_spend_minor'] * (1 + $s / 100), 'expected_new_customers' => $base['expected_new_customers'] * (1 + $c / 100)] + $base);
                }
                $grid[] = ['spend_change_pct' => $s, 'profits_minor' => array_map(fn ($v) => $v === null ? null : (int) $v, $row)];
            }
        }

        return [
            'base_profit_minor' => $baseProfit === null ? null : (int) $baseProfit,
            'swing_pct' => $swingPct,
            'tornado' => $tornado,
            'grid' => ['customers_change_pct' => [-50, -25, 0, 25, 50], 'rows' => $grid],
            'basis' => 'estimated',
            'calculation_version' => config('marketing.calculation_version'),
        ];
    }
}
