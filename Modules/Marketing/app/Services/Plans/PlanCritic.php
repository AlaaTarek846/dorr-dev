<?php

namespace Modules\Marketing\Services\Plans;

use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Services\Research\EvidenceGuard;

/**
 * Orchestrator step 08, in code: consistency, budget, sources and claims. It fixes what must never pass (a source
 * the project does not have, a budget the owner never gave) and reports the rest. `blocking` warnings keep the plan
 * from going to review.
 */
class PlanCritic
{
    /**
     * @param  array<string, mixed>  $plan  normalized
     * @param  list<string>  $knownRefs  the project's source refs (S<id>)
     * @return array{plan: array<string, mixed>, warnings: list<array{code: string, severity: string, detail?: mixed}>}
     */
    public function review(string $type, array $plan, MarketingProject $project, array $knownRefs): array
    {
        $warnings = [];

        $unknown = array_values(array_diff($plan['sources'], $knownRefs));
        if ($unknown !== []) {
            $plan['sources'] = array_values(array_intersect($plan['sources'], $knownRefs));
            $warnings[] = ['code' => 'unknown_sources_removed', 'severity' => 'warning', 'detail' => $unknown];
        }

        $budget = $project->profile?->monthly_budget_minor;
        if ($budget === null) {
            $note = __('marketing.plans.budget_unknown');
            if (! in_array($note, $plan['assumptions'], true)) {
                $plan['assumptions'][] = $note;
            }
            $warnings[] = ['code' => 'budget_missing', 'severity' => 'warning'];
            if ($type === 'business' && $plan['financial']['budget_monthly_minor'] !== null) {
                $plan['financial']['budget_monthly_minor'] = null;
                $warnings[] = ['code' => 'invented_budget_removed', 'severity' => 'warning'];
            }
        } elseif ($type === 'business') {
            $plan['financial']['budget_monthly_minor'] = $budget;
        }

        if ($plan['sources'] === [] && EvidenceGuard::hasNumber(json_encode($this->prose($type, $plan), JSON_UNESCAPED_UNICODE) ?: '')) {
            $warnings[] = ['code' => 'numbers_without_sources', 'severity' => 'warning'];
        }

        if ($type === 'marketing') {
            $share = array_sum(array_map(fn ($c) => (float) ($c['budget_share_pct'] ?? 0), $plan['channels']));
            if ($share > 100.0) {
                $warnings[] = ['code' => 'budget_share_over_100', 'severity' => 'blocking', 'detail' => $share];
            }
        }

        if ($type === 'gtm') {
            usort($plan['phases'], fn ($a, $b) => ($a['day_from'] ?? 0) <=> ($b['day_from'] ?? 0));
            $previousEnd = 0;
            foreach ($plan['phases'] as $i => $phase) {
                $from = $phase['day_from'];
                $to = $phase['day_to'];
                if ($from === null || $to === null || $from < 1 || $to < $from || $to > $plan['horizon_days']) {
                    $warnings[] = ['code' => 'invalid_phase_range', 'severity' => 'blocking', 'detail' => $i];
                } elseif ($from <= $previousEnd) {
                    $warnings[] = ['code' => 'overlapping_phases', 'severity' => 'warning', 'detail' => $i];
                }
                $previousEnd = max($previousEnd, (int) $to);
                if ($phase['go_no_go_criteria'] === []) {
                    $warnings[] = ['code' => 'phase_without_go_no_go', 'severity' => 'warning', 'detail' => $i];
                }
            }
        }

        return ['plan' => $plan, 'warnings' => $warnings];
    }

    /** @param  list<array<string, mixed>>  $warnings */
    public static function blocking(array $warnings): bool
    {
        return collect($warnings)->contains(fn ($w) => ($w['severity'] ?? null) === 'blocking');
    }

    /** The text a reader sees, without the money fields the critic sets itself. */
    private function prose(string $type, array $plan): array
    {
        unset($plan['finance'], $plan['horizon_days'], $plan['sources']);
        if (isset($plan['financial'])) {
            unset($plan['financial']['budget_monthly_minor']);
        }
        if ($type === 'gtm') {
            $plan['phases'] = array_map(fn ($p) => array_diff_key($p, ['day_from' => 1, 'day_to' => 1]), $plan['phases']);
        }
        if ($type === 'marketing') {
            $plan['channels'] = array_map(fn ($c) => array_diff_key($c, ['budget_share_pct' => 1]), $plan['channels']);
        }

        return $plan;
    }
}
