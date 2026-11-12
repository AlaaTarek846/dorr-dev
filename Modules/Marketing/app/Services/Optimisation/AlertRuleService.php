<?php

namespace Modules\Marketing\Services\Optimisation;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Support\Facades\Log;
use Modules\Marketing\Models\MarketingAlertRule;
use Modules\Marketing\Models\MarketingMetric;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Models\MarketingProjectMember;
use Modules\Marketing\Services\MarketingAudit;
use Modules\Marketing\Services\Results\KpiService;
use Throwable;

/**
 * Alert rules (phase D): checked whenever results come in (by hand, by file or from a platform). A rule fires at most
 * once a day and only on measured results; it notifies the team and never touches a budget or a campaign.
 */
class AlertRuleService
{
    public function __construct(private readonly MarketingAudit $audit, private readonly NotificationCenter $notifications) {}

    /** @return array{value: ?float, reason: ?string} */
    public function value(MarketingAlertRule $rule): array
    {
        $rows = MarketingMetric::query()->where('project_id', $rule->project_id)->whereIn('basis', MarketingMetric::MEASURED)
            ->whereDate('metric_date', '>=', now()->subDays($rule->window_days - 1)->toDateString())
            ->when($rule->channel !== null, fn ($q) => $q->where('channel', $rule->channel))->get();
        if ($rows->isEmpty()) {
            return ['value' => null, 'reason' => 'missing_data'];
        }
        $rates = KpiService::rates($rows);

        return match ($rule->metric) {
            'spend' => ($s = KpiService::sum($rows, 'spend_minor')) === null ? ['value' => null, 'reason' => 'missing_data'] : ['value' => (float) $s, 'reason' => null],
            'conversions' => ($c = KpiService::sum($rows, 'conversions')) === null ? ['value' => null, 'reason' => 'missing_data'] : ['value' => (float) $c, 'reason' => null],
            'cpa' => $rates['cpa_minor'],
            'roas' => $rates['roas'],
            default => $rates['ctr_pct'],
        };
    }

    /** @return list<string> uuids of the rules that fired */
    public function check(MarketingProject $project): array
    {
        $fired = [];
        foreach (MarketingAlertRule::query()->where('project_id', $project->id)->where('active', true)->get() as $rule) {
            $value = $this->value($rule)['value'];
            if ($value === null) {
                continue;
            }
            $hit = $rule->comparison === 'above' ? $value > $rule->threshold : $value < $rule->threshold;
            if (! $hit || ($rule->last_triggered_at !== null && $rule->last_triggered_at->isToday())) {
                continue;
            }
            $rule->update(['last_triggered_at' => now(), 'last_value' => $value]);
            $this->audit->record($project, null, 'alert.fired', $rule, meta: ['metric' => $rule->metric, 'value' => $value, 'threshold' => $rule->threshold]);
            $this->notify($project, $rule, $value);
            $fired[] = $rule->uuid;
        }

        return $fired;
    }

    private function notify(MarketingProject $project, MarketingAlertRule $rule, float $value): void
    {
        try {
            $members = MarketingProjectMember::query()->where('project_id', $project->id)->with('member')->get()->pluck('member')->filter()->values();
            $this->notifications->send(
                $members, 'marketing.alert.fired', 'marketing_alert_title', 'marketing_alert_body',
                ['project' => $project->name, 'metric' => __('marketing.alerts.metrics.'.$rule->metric), 'value' => (string) round($value, 2), 'threshold' => (string) round($rule->threshold, 2)],
                ['type' => 'marketing_alert', 'project_id' => $project->uuid, 'rule_id' => $rule->uuid],
            );
        } catch (Throwable $e) {
            Log::warning('[Marketing] alert notice failed: '.$e->getMessage());
        }
    }
}
