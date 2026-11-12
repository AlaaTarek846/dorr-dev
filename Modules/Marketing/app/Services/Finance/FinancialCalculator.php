<?php

namespace Modules\Marketing\Services\Finance;

/**
 * Orchestrator step 06 and spec 09, in code: the model never does the arithmetic. Money is in minor units; every
 * output says whether it is computed or why it is not (`reason`), and a division by zero is never made (AT-16).
 * All outputs here are estimates (`basis: estimated`) — actual and imported figures come with tracking (phase B).
 */
class FinancialCalculator
{
    public const INPUTS = [
        'price_minor', 'variable_cost_minor', 'fixed_costs_minor', 'marketing_spend_minor',
        'expected_new_customers', 'expected_conversions', 'attributed_revenue_minor',
        'units_per_customer', 'monthly_churn_pct',
    ];

    /** Multipliers of each scenario on the base inputs. */
    public const SCENARIOS = [
        'conservative' => ['volume' => 0.7, 'spend' => 1.1],
        'base' => ['volume' => 1.0, 'spend' => 1.0],
        'optimistic' => ['volume' => 1.3, 'spend' => 1.0],
    ];

    /**
     * @param  array<string, int|float|null>  $in
     * @return array<string, array{value: int|float|null, reason: ?string, basis: string}>
     */
    public function compute(array $in): array
    {
        $price = self::n($in, 'price_minor');
        $variable = self::n($in, 'variable_cost_minor') ?? 0;
        $fixed = self::n($in, 'fixed_costs_minor');
        $spend = self::n($in, 'marketing_spend_minor');
        $customers = self::n($in, 'expected_new_customers');
        $conversions = self::n($in, 'expected_conversions');
        $units = self::n($in, 'units_per_customer') ?? 1;
        $churn = self::n($in, 'monthly_churn_pct');
        $revenue = self::n($in, 'attributed_revenue_minor')
            ?? ($price !== null && $customers !== null ? $price * $customers * $units : null);

        $margin = $price === null ? null : $price - $variable;

        $out = [
            'contribution_margin_per_unit_minor' => $margin === null ? self::none('missing_input') : self::ok((int) round($margin)),
            'break_even_units' => match (true) {
                $margin === null || $fixed === null => self::none('missing_input'),
                $margin <= 0 => self::none('non_positive_margin'),
                default => self::ok((int) ceil($fixed / $margin)),
            },
            'cac_minor' => match (true) {
                $spend === null || $customers === null => self::none('missing_input'),
                $customers <= 0 => self::none('no_customers'),
                default => self::ok((int) round($spend / $customers)),
            },
            'cpa_minor' => match (true) {
                $spend === null || $conversions === null => self::none('missing_input'),
                $conversions <= 0 => self::none('no_conversions'),
                default => self::ok((int) round($spend / $conversions)),
            },
            'roas' => match (true) {
                $spend === null || $revenue === null => self::none('missing_input'),
                $spend <= 0 => self::none('no_spend'),
                default => self::ok(round($revenue / $spend, 2)),
            },
            'ltv_minor' => match (true) {
                $margin === null || $churn === null => self::none('missing_input'),
                $churn <= 0 => self::none('no_churn'),
                default => self::ok((int) round($margin * $units / ($churn / 100))),
            },
            'monthly_profit_minor' => $margin === null || $customers === null || $fixed === null || $spend === null
                ? self::none('missing_input')
                : self::ok((int) round($margin * $units * $customers - $fixed - $spend)),
        ];

        return $out;
    }

    /**
     * @param  array<string, int|float|null>  $base
     * @return array<string, array{inputs: array<string, mixed>, outputs: array<string, mixed>}>
     */
    public function scenarios(array $base): array
    {
        $result = [];
        foreach (self::SCENARIOS as $name => $m) {
            $inputs = $base;
            foreach (['expected_new_customers', 'expected_conversions', 'attributed_revenue_minor'] as $key) {
                if (isset($inputs[$key])) {
                    $inputs[$key] = (int) round($inputs[$key] * $m['volume']);
                }
            }
            if (isset($inputs['marketing_spend_minor'])) {
                $inputs['marketing_spend_minor'] = (int) round($inputs['marketing_spend_minor'] * $m['spend']);
            }
            $result[$name] = ['inputs' => $inputs, 'outputs' => $this->compute($inputs)];
        }

        return $result;
    }

    private static function n(array $in, string $key): int|float|null
    {
        $v = $in[$key] ?? null;

        return is_int($v) || is_float($v) ? $v : null;
    }

    private static function ok(int|float $value): array
    {
        return ['value' => $value, 'reason' => null, 'basis' => 'estimated'];
    }

    private static function none(string $reason): array
    {
        return ['value' => null, 'reason' => $reason, 'basis' => 'estimated'];
    }
}
