<?php

namespace Modules\Marketing\Tests\Unit;

use Modules\Marketing\Services\Finance\FinancialCalculator;
use Modules\Marketing\Services\Plans\PlanSchema;
use PHPUnit\Framework\TestCase;

class FinancialCalculatorTest extends TestCase
{
    public function test_nothing_is_computed_from_missing_inputs(): void
    {
        $out = (new FinancialCalculator)->compute([]);
        foreach ($out as $metric) {
            $this->assertNull($metric['value']);
            $this->assertSame('missing_input', $metric['reason']);
        }
    }

    public function test_revenue_defaults_to_customers_times_price_and_roas_is_a_ratio(): void
    {
        $out = (new FinancialCalculator)->compute(['price_minor' => 5000, 'expected_new_customers' => 10, 'marketing_spend_minor' => 25000]);
        $this->assertSame(2.0, $out['roas']['value']);
        $this->assertSame(2500, $out['cac_minor']['value']);
        $this->assertSame('missing_input', $out['break_even_units']['reason']);
    }

    public function test_break_even_rounds_up(): void
    {
        $out = (new FinancialCalculator)->compute(['price_minor' => 300, 'variable_cost_minor' => 100, 'fixed_costs_minor' => 1001]);
        $this->assertSame(6, $out['break_even_units']['value']);
    }

    public function test_the_schema_keeps_known_fields_only_and_clamps_values(): void
    {
        $plan = PlanSchema::normalize('marketing', [
            'title' => '  T ', 'summary' => 'S', 'hack' => 'x', 'sources' => ['s1', 'S1', 7],
            'channels' => [['channel' => 'Instagram', 'budget_share_pct' => 140], ['rationale' => 'no name'], 'junk'],
        ]);
        $this->assertSame('T', $plan['title']);
        $this->assertArrayNotHasKey('hack', $plan);
        $this->assertSame(['S1', '7'], $plan['sources']);
        $this->assertCount(1, $plan['channels']);
        $this->assertEquals(100, $plan['channels'][0]['budget_share_pct']);
        $this->assertSame([], PlanSchema::errors('marketing', $plan));
        $this->assertSame(['title', 'summary', 'phases'], PlanSchema::errors('gtm', PlanSchema::normalize('gtm', [])));
    }
}
