<?php

namespace Modules\Marketing\Services\Optimisation;

use Illuminate\Database\Eloquent\Model;
use Modules\Marketing\Exceptions\MarketingException;
use Modules\Marketing\Models\MarketingContentItem;
use Modules\Marketing\Models\MarketingExperiment;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Services\MarketingAccess;
use Modules\Marketing\Services\MarketingAudit;

/**
 * A/B experiments (phase D, orchestrator step 12): the result is computed in code with a two-proportion z-test.
 * A winner is named only with enough data (MIN_TRIALS per variant) and p < 0.05; otherwise the answer is
 * "not enough evidence" — never a causal claim the data can't carry.
 */
class ExperimentService
{
    public const MIN_TRIALS = 100;

    public const ALPHA = 0.05;

    public function __construct(private readonly MarketingAccess $access, private readonly MarketingAudit $audit) {}

    /** @param  array<string, mixed>  $data  validated */
    public function create(MarketingProject $project, Model $actor, array $data): MarketingExperiment
    {
        $this->access->assert($project, $actor, 'edit');
        $contentId = null;
        $variants = [['key' => 'A', 'label' => $data['labels'][0] ?? 'A'], ['key' => 'B', 'label' => $data['labels'][1] ?? 'B']];
        if (! empty($data['content'])) {
            $item = MarketingContentItem::query()->where('project_id', $project->id)->where('uuid', $data['content'])->first()
                ?? throw new MarketingException('invalid_answer', 422);
            $contentId = $item->id;
            $keys = array_column($item->content['variants'] ?? [], 'key');
            if (count($keys) >= 2) {
                $variants = array_map(fn ($k) => ['key' => $k, 'label' => __('marketing.experiments.variant', ['key' => $k])], $keys);
            }
        }
        $experiment = MarketingExperiment::query()->create([
            'project_id' => $project->id,
            'content_item_id' => $contentId,
            'name' => $data['name'],
            'hypothesis' => $data['hypothesis'],
            'success_metric' => $data['success_metric'],
            'variants' => $variants,
            'results' => [],
            'status' => 'DRAFT',
            'created_by_type' => $actor->getMorphClass(),
            'created_by_id' => $actor->getKey(),
        ]);
        $this->audit->record($project, $actor, 'experiment.created', $experiment);

        return $experiment;
    }

    public function start(MarketingExperiment $experiment, Model $actor): MarketingExperiment
    {
        $this->access->assert($experiment->project, $actor, 'edit');
        if ($experiment->status !== 'DRAFT') {
            throw new MarketingException('invalid_transition', 409, [], ['status' => $experiment->status]);
        }
        $experiment->update(['status' => 'RUNNING', 'started_at' => now()]);
        $this->audit->record($experiment->project, $actor, 'experiment.started', $experiment);

        return $experiment;
    }

    /** Results per variant (measured on the platform, entered by the person); replaces that variant's numbers. */
    public function record(MarketingExperiment $experiment, Model $actor, string $variant, array $numbers): MarketingExperiment
    {
        $this->access->assert($experiment->project, $actor, 'research');
        if ($experiment->status === 'CONCLUDED') {
            throw new MarketingException('invalid_transition', 409, [], ['status' => $experiment->status]);
        }
        if (! in_array($variant, array_column($experiment->variants, 'key'), true)) {
            throw new MarketingException('invalid_answer', 422);
        }
        if (($numbers['clicks'] ?? 0) > ($numbers['impressions'] ?? PHP_INT_MAX) || ($numbers['conversions'] ?? 0) > ($numbers['clicks'] ?? PHP_INT_MAX)) {
            throw new MarketingException('invalid_rows', 422, [], ['errors' => [['line' => 1, 'field' => 'clicks']]]);
        }
        $results = $experiment->results ?? [];
        $results[$variant] = array_map(fn ($v) => $v === null ? null : (int) $v, [
            'impressions' => $numbers['impressions'] ?? null, 'clicks' => $numbers['clicks'] ?? null,
            'conversions' => $numbers['conversions'] ?? null, 'spend_minor' => $numbers['spend_minor'] ?? null,
        ]);
        $experiment->update(['results' => $results, 'status' => $experiment->status === 'DRAFT' ? 'RUNNING' : $experiment->status, 'started_at' => $experiment->started_at ?? now()]);
        $this->audit->record($experiment->project, $actor, 'experiment.results', $experiment, meta: ['variant' => $variant]);

        return $experiment;
    }

    public function conclude(MarketingExperiment $experiment, Model $actor): MarketingExperiment
    {
        $this->access->assert($experiment->project, $actor, 'edit');
        if ($experiment->status === 'CONCLUDED') {
            throw new MarketingException('invalid_transition', 409, [], ['status' => $experiment->status]);
        }
        $experiment->update(['status' => 'CONCLUDED', 'ended_at' => now(), 'conclusion' => $this->evaluate($experiment)]);
        $this->audit->record($experiment->project, $actor, 'experiment.concluded', $experiment, after: $experiment->conclusion);

        return $experiment;
    }

    /**
     * @return array{verdict: string, winner: ?string, p_value: ?float, lift_pct: ?float, rates: array<string, ?float>, reason: ?string, text: string}
     */
    public function evaluate(MarketingExperiment $experiment): array
    {
        [$num, $den] = $experiment->success_metric === 'conversion_rate' ? ['conversions', 'clicks'] : ['clicks', 'impressions'];
        $rows = [];
        foreach ($experiment->variants as $v) {
            $r = $experiment->results[$v['key']] ?? [];
            $rows[$v['key']] = ['x' => $r[$num] ?? null, 'n' => $r[$den] ?? null];
        }
        $rates = array_map(fn ($r) => $r['x'] === null || ! $r['n'] ? null : round($r['x'] * 100 / $r['n'], 2), $rows);
        $done = fn (string $verdict, ?string $reason, ?string $winner = null, ?float $p = null, ?float $lift = null) => [
            'verdict' => $verdict, 'winner' => $winner, 'p_value' => $p, 'lift_pct' => $lift, 'rates' => $rates, 'reason' => $reason,
            'text' => __('marketing.experiments.verdicts.'.$verdict, ['winner' => $winner, 'lift' => $lift, 'p' => $p, 'min' => self::MIN_TRIALS]),
        ];

        $complete = array_filter($rows, fn ($r) => $r['x'] !== null && $r['n'] !== null);
        if (count($complete) < 2) {
            return $done('missing_data', 'missing_data');
        }
        if (collect($complete)->contains(fn ($r) => $r['n'] < self::MIN_TRIALS)) {
            return $done('too_small', 'too_small');
        }
        // Best against the runner-up.
        $sorted = collect($complete)->map(fn ($r, $k) => $r + ['k' => $k, 'p' => $r['x'] / $r['n']])->sortByDesc('p')->values();
        [$a, $b] = [$sorted[0], $sorted[1]];
        $pool = ($a['x'] + $b['x']) / ($a['n'] + $b['n']);
        $se = sqrt($pool * (1 - $pool) * (1 / $a['n'] + 1 / $b['n']));
        if ($se == 0.0) {
            return $done('no_difference', 'no_variance', null, 1.0, 0.0);
        }
        $z = ($a['p'] - $b['p']) / $se;
        $p = round(2 * (1 - self::normalCdf(abs($z))), 4);
        $lift = $b['p'] > 0 ? round(($a['p'] - $b['p']) * 100 / $b['p'], 1) : null;

        return $p < self::ALPHA ? $done('winner', null, $a['k'], $p, $lift) : $done('no_difference', 'not_significant', null, $p, $lift);
    }

    /** Standard normal CDF (Abramowitz–Stegun 7.1.26, error < 1.5e-7). */
    public static function normalCdf(float $x): float
    {
        $t = 1 / (1 + 0.3275911 * abs($x) / sqrt(2));
        $erf = 1 - ((((1.061405429 * $t - 1.453152027) * $t + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t * exp(-($x * $x) / 2);

        return 0.5 * (1 + ($x >= 0 ? $erf : -$erf));
    }
}
