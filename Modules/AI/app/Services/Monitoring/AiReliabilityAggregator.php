<?php

namespace Modules\AI\Services\Monitoring;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\AI\Models\AiReliabilityMetric;

/**
 * Fills ai_reliability_metrics (admin.ai-reliability-metrics.index) from
 * ai_provider_logs - one row per real provider call (AiGateway::logCall),
 * success = no error_code - joined to ai_requests for the intent.
 *
 * For each period it writes one row per provider (intent_id null = all
 * intents) and one per provider + intent. Re-running a period replaces
 * its rows (updateOrCreate on provider/intent/period), so the command is
 * safe to re-run. model_key, region and plan stay null: a provider log
 * row only knows the provider, and the request's model is the model that
 * finally answered (not the one each failed attempt used).
 */
class AiReliabilityAggregator
{
    /**
     * @return int rows written
     */
    public function aggregate(Carbon $periodStart, Carbon $periodEnd): int
    {
        $calls = DB::table('ai_provider_logs as l')
            ->leftJoin('ai_requests as r', 'r.id', '=', 'l.request_id')
            ->where('l.created_at', '>=', $periodStart)
            ->where('l.created_at', '<', $periodEnd)
            ->whereNotNull('l.provider_id')
            ->get(['l.provider_id', 'r.intent_id', 'l.response_time_ms', 'l.error_code']);

        if ($calls->isEmpty()) {
            return 0;
        }

        $groups = [];

        foreach ($calls as $call) {
            $groups[$call->provider_id.':'][] = $call;

            if ($call->intent_id !== null) {
                $groups[$call->provider_id.':'.$call->intent_id][] = $call;
            }
        }

        $written = 0;

        foreach ($groups as $key => $rows) {
            [$providerId, $intentId] = explode(':', $key);
            $total = count($rows);
            $failed = count(array_filter($rows, fn ($row) => $row->error_code !== null));

            $latencies = array_values(array_filter(
                array_map(fn ($row) => $row->response_time_ms, $rows),
                fn ($ms) => $ms !== null,
            ));
            sort($latencies);

            AiReliabilityMetric::query()->updateOrCreate([
                'provider_id' => (int) $providerId,
                'intent_id' => $intentId === '' ? null : (int) $intentId,
                'model_key' => null,
                'region' => null,
                'plan_id' => null,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ], [
                'success_rate' => round(($total - $failed) * 100 / $total, 2),
                'error_rate' => round($failed * 100 / $total, 2),
                'latency_p50_ms' => $this->percentile($latencies, 50),
                'latency_p95_ms' => $this->percentile($latencies, 95),
                'latency_p99_ms' => $this->percentile($latencies, 99),
            ]);

            $written++;
        }

        return $written;
    }

    /**
     * Nearest-rank percentile of an ascending list (null when empty).
     *
     * @param  list<int>  $sorted
     */
    protected function percentile(array $sorted, int $p): ?int
    {
        if ($sorted === []) {
            return null;
        }

        $rank = (int) ceil($p / 100 * count($sorted));

        return (int) $sorted[max(0, $rank - 1)];
    }
}
