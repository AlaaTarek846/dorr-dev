<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Command;
use Modules\AI\Services\Monitoring\AiReliabilityAggregator;

/**
 * Builds ai_reliability_metrics from ai_provider_logs, one period per full
 * hour. Scheduled hourly (AIServiceProvider::configureSchedules) for the
 * hour that just ended; --hours=N recomputes the last N full hours (e.g.
 * to backfill after downtime). Re-running an hour replaces its rows.
 */
class AggregateAiReliabilityMetrics extends Command
{
    protected $signature = 'ai:aggregate-reliability {--hours=1 : How many past full hours to (re)compute}';

    protected $description = 'Compute per-provider success/error rates and latency percentiles from the AI provider logs.';

    public function handle(AiReliabilityAggregator $aggregator): int
    {
        $hours = max(1, min(24 * 31, (int) $this->option('hours')));
        $currentHour = now()->startOfHour();
        $written = 0;

        for ($i = $hours; $i >= 1; $i--) {
            $start = $currentHour->copy()->subHours($i);
            $written += $aggregator->aggregate($start, $start->copy()->addHour());
        }

        $this->info("Reliability metrics: {$written} row(s) written for {$hours} hour(s).");

        return self::SUCCESS;
    }
}
