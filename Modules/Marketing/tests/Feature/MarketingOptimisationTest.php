<?php

namespace Modules\Marketing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Marketing\Models\MarketingAuditEvent;
use Modules\Marketing\Services\Optimisation\ExperimentService;
use Modules\Marketing\Tests\Feature\Concerns\MarketingSetup;
use Tests\TestCase;

/** Phase D: A/B experiments judged in code, alert rules, suggestions with their numbers, simulation. Nothing spends. */
class MarketingOptimisationTest extends TestCase
{
    use MarketingSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMarketing();
    }

    public function test_an_experiment_names_a_winner_only_with_enough_data_and_a_real_difference(): void
    {
        $id = $this->readyProject();
        $e = $this->postJson($this->api("projects/{$id}/experiments"), [
            'name' => 'Headline test', 'hypothesis' => 'A delivery-time headline gets more clicks', 'success_metric' => 'ctr',
        ])->assertCreated()->assertJsonPath('data.status', 'DRAFT')->json('data');
        $this->assertSame(['A', 'B'], array_column($e['variants'], 'key'));

        $this->postJson($this->api("experiments/{$e['id']}/results"), ['variant' => 'A', 'impressions' => 50, 'clicks' => 5])->assertOk()
            ->assertJsonPath('data.status', 'RUNNING')->assertJsonPath('data.preview.verdict', 'missing_data');
        $this->postJson($this->api("experiments/{$e['id']}/results"), ['variant' => 'B', 'impressions' => 50, 'clicks' => 2])->assertOk()
            ->assertJsonPath('data.preview.verdict', 'too_small');
        $this->postJson($this->api("experiments/{$e['id']}/results"), ['variant' => 'C', 'impressions' => 50, 'clicks' => 2])->assertStatus(422);
        $this->postJson($this->api("experiments/{$e['id']}/results"), ['variant' => 'A', 'impressions' => 5, 'clicks' => 9])->assertStatus(422);

        // Similar rates → no winner.
        $this->postJson($this->api("experiments/{$e['id']}/results"), ['variant' => 'A', 'impressions' => 1000, 'clicks' => 50])->assertOk();
        $this->postJson($this->api("experiments/{$e['id']}/results"), ['variant' => 'B', 'impressions' => 1000, 'clicks' => 48])->assertOk()
            ->assertJsonPath('data.preview.verdict', 'no_difference')->assertJsonPath('data.preview.winner', null);

        // A clear difference → a winner with its p-value.
        $this->postJson($this->api("experiments/{$e['id']}/results"), ['variant' => 'B', 'impressions' => 1000, 'clicks' => 20])->assertOk();
        $c = $this->postJson($this->api("experiments/{$e['id']}/conclude"))->assertOk()->assertJsonPath('data.status', 'CONCLUDED')->json('data.conclusion');
        $this->assertSame('winner', $c['verdict']);
        $this->assertSame('A', $c['winner']);
        $this->assertLessThan(0.05, $c['p_value']);
        $this->assertSame(150.0, (float) $c['lift_pct']);
        $this->assertStringContainsString('not a guarantee', $c['text']);
        $this->postJson($this->api("experiments/{$e['id']}/results"), ['variant' => 'A', 'impressions' => 1, 'clicks' => 1])->assertStatus(409);

        // The normal CDF is right where it matters.
        $this->assertEqualsWithDelta(0.975, ExperimentService::normalCdf(1.96), 0.0005);
        $this->assertEqualsWithDelta(0.5, ExperimentService::normalCdf(0), 0.0001);
    }

    public function test_an_ab_experiment_uses_the_versions_of_a_studio_ad(): void
    {
        $id = $this->readyProject();
        $ad = $this->postJson($this->api("projects/{$id}/content"), [
            'kind' => 'ad', 'channel' => 'instagram', 'title' => 'Boxes',
            'variants' => [['body' => 'One'], ['body' => 'Two'], ['body' => 'Three']],
        ])->json('data.id');
        $e = $this->postJson($this->api("projects/{$id}/experiments"), ['name' => 'Ad', 'hypothesis' => 'Version C wins', 'success_metric' => 'conversion_rate', 'content' => $ad])
            ->assertCreated()->json('data');
        $this->assertSame(['A', 'B', 'C'], array_column($e['variants'], 'key'));
        $this->assertSame($ad, $e['content_id']);
    }

    public function test_an_alert_rule_fires_once_a_day_on_measured_results_and_never_on_estimates(): void
    {
        $id = $this->readyProject();
        $rule = $this->postJson($this->api("projects/{$id}/alert-rules"), ['metric' => 'cpa', 'comparison' => 'above', 'threshold' => 3000, 'window_days' => 7])
            ->assertCreated()->assertJsonPath('data.current.reason', 'missing_data')->json('data');

        // An estimate never fires it.
        $this->postJson($this->api("projects/{$id}/metrics"), ['rows' => [['date' => now()->subDay()->toDateString(), 'channel' => 'instagram', 'basis' => 'estimated', 'spend_minor' => 90000, 'conversions' => 1]]])->assertOk();
        $this->assertSame(0, MarketingAuditEvent::query()->where('action', 'alert.fired')->count());

        // CPA 5,000 > 3,000 → fires once.
        $this->postJson($this->api("projects/{$id}/metrics"), ['rows' => [['date' => now()->subDay()->toDateString(), 'channel' => 'instagram', 'spend_minor' => 10000, 'conversions' => 2]]])->assertOk();
        $this->postJson($this->api("projects/{$id}/metrics"), ['rows' => [['date' => now()->subDays(2)->toDateString(), 'channel' => 'instagram', 'spend_minor' => 10000, 'conversions' => 2]]])->assertOk();
        $this->assertSame(1, MarketingAuditEvent::query()->where('action', 'alert.fired')->count());
        $this->assertGreaterThan(0, DB::table('notifications')->count());
        $this->getJson($this->api("projects/{$id}/alert-rules"))->assertJsonPath('data.0.current.value', 5000)->assertJsonPath('data.0.active', true);

        $this->patchJson($this->api("alert-rules/{$rule['id']}"), ['active' => false])->assertOk()->assertJsonPath('data.active', false);
        $this->deleteJson($this->api("alert-rules/{$rule['id']}"))->assertOk();
        $this->getJson($this->api("projects/{$id}/alert-rules"))->assertJsonCount(0, 'data');
    }

    public function test_suggestions_come_with_their_numbers_and_change_nothing(): void
    {
        $id = $this->readyProject();
        $this->getJson($this->api("projects/{$id}/optimisations"))->assertOk()->assertJsonPath('data.suggestions.0.code', 'need_results');

        $rows = [];
        foreach (range(1, 6) as $d) {
            $date = now()->subDays($d)->toDateString();
            $rows[] = ['date' => $date, 'channel' => 'instagram', 'impressions' => 1000, 'clicks' => 50, 'spend_minor' => 5000, 'conversions' => 5, 'revenue_minor' => 20000];
            $rows[] = ['date' => $date, 'channel' => 'tiktok', 'impressions' => 1000, 'clicks' => 30, 'spend_minor' => 9000, 'conversions' => 1];
            $rows[] = ['date' => $date, 'channel' => 'snapchat', 'impressions' => 500, 'clicks' => 10, 'spend_minor' => 3000, 'conversions' => 0];
        }
        foreach (range(8, 13) as $d) {
            $rows[] = ['date' => now()->subDays($d)->toDateString(), 'channel' => 'tiktok', 'impressions' => 1000, 'clicks' => 80];
        }
        $this->postJson($this->api("projects/{$id}/metrics"), ['rows' => $rows])->assertOk();
        $this->postJson($this->api("projects/{$id}/budgets"), ['kind' => 'ads', 'period_start' => now()->subDays(6)->toDateString(), 'period_end' => now()->addDays(23)->toDateString(), 'cap_minor' => 150000])->assertCreated();

        $suggestions = collect($this->getJson($this->api("projects/{$id}/optimisations"))->assertOk()->json('data.suggestions'));
        $codes = $suggestions->pluck('code')->all();
        $this->assertContains('spend_without_results', $codes);
        $this->assertSame('snapchat', $suggestions->firstWhere('code', 'spend_without_results')['channel']);
        $this->assertContains('expensive_channel', $codes);
        $this->assertSame('tiktok', $suggestions->firstWhere('code', 'expensive_channel')['channel']);
        $this->assertContains('test_shift_budget', $codes);
        $this->assertContains('creative_fatigue', $codes);
        $this->assertContains('track_revenue', $codes);
        $this->assertContains('pacing_over_cap', $codes);
        $this->assertSame('high', $suggestions->first()['priority']);
        $this->assertSame(1, DB::table('marketing_budgets')->count());
        $this->assertSame(0, DB::table('marketing_campaigns')->count());
    }

    public function test_the_simulation_ranks_inputs_by_their_effect_on_profit(): void
    {
        $id = $this->readyProject();
        $this->aiReturns($this->gtmAnswer());
        $planId = $this->postJson($this->api("projects/{$id}/plans"), ['type' => 'gtm'])->json('data.plan.id');
        $this->putJson($this->api("plans/{$planId}/finance"), ['version' => 1, 'inputs' => [
            'price_minor' => 5000, 'variable_cost_minor' => 2000, 'fixed_costs_minor' => 100000, 'marketing_spend_minor' => 50000, 'expected_new_customers' => 100,
        ]])->assertOk();

        $sim = $this->postJson($this->api("plans/{$planId}/simulate"), ['swing_pct' => 20])->assertOk()->json('data');
        $this->assertSame('estimated', $sim['basis']);
        $this->assertNotNull($sim['base_profit_minor']);
        $this->assertNotEmpty($sim['tornado']);
        $swings = array_column($sim['tornado'], 'swing_minor');
        $sorted = $swings;
        rsort($sorted);
        $this->assertSame($sorted, $swings);
        $this->assertCount(5, $sim['grid']['rows']);
        $this->assertSame($sim['base_profit_minor'], $sim['grid']['rows'][2]['profits_minor'][2]);
        $this->postJson($this->api("plans/{$planId}/simulate"), ['swing_pct' => 33])->assertStatus(422);
    }
}
