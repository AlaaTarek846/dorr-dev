<?php

namespace Modules\AI\Services;

use Illuminate\Support\Carbon;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\AiProviderHealth;

/**
 * v2.0 requirements doc S15.4/S20.3: a simple per-provider circuit
 * breaker so a provider that is actively failing (down, rate-limited,
 * mis-configured) gets skipped fast for a cooldown window instead of
 * every request in that window paying for a doomed network call before
 * falling back.
 *
 * State lives in ai_provider_health (check_type=functional) rather than a
 * dedicated table, and the breaker's current state is always "whatever
 * the latest row for this provider says" (no row = healthy).
 *
 * A row is written only when the state CHANGES (2026-11-11 fix: it used
 * to insert one row per successful call, so the table grew with traffic
 * and never got purged). While the state stays the same, the latest row
 * is updated in place (failure count, open_until). The table is also
 * purged by ai:enforce-retention (the latest row per provider is kept).
 *
 * States:
 *  healthy    -> no recent failures (no row written per success)
 *  degraded   -> 1..threshold-1 consecutive failures
 *  unhealthy  -> circuit open until details.open_until; calls skipped
 *  recovering -> half-open: open_until passed, the next calls are let
 *                through as probes; one success -> healthy, one failure
 *                -> unhealthy again (re-opened immediately).
 */
class AiCircuitBreaker
{
    protected const CHECK_TYPE = 'functional';

    public function isOpen(AiProvider $provider): bool
    {
        if (! (bool) config('ai.circuit_breaker.enabled', true)) {
            return false;
        }

        $latest = $this->latestState($provider);

        if (! $latest || $latest->status !== AiProviderHealth::STATUS_UNHEALTHY) {
            return false;
        }

        $openUntil = $latest->details['open_until'] ?? null;

        if (! $openUntil) {
            return false;
        }

        if (Carbon::parse($openUntil)->isFuture()) {
            return true;
        }

        // The cooldown has elapsed: half-open. Recorded once (state
        // change), then this call is let through as the probe.
        $this->write($provider, AiProviderHealth::STATUS_RECOVERING, 0.5, [
            'consecutive_failures' => (int) ($latest->details['consecutive_failures'] ?? 0),
        ]);

        return false;
    }

    public function recordSuccess(AiProvider $provider): void
    {
        $latest = $this->latestState($provider);

        // Already healthy (or never failed): nothing changed, nothing written.
        if (! $latest || $latest->status === AiProviderHealth::STATUS_HEALTHY) {
            return;
        }

        $this->write($provider, AiProviderHealth::STATUS_HEALTHY, 1, ['consecutive_failures' => 0]);
    }

    public function recordFailure(AiProvider $provider): void
    {
        $threshold = max(1, (int) config('ai.circuit_breaker.failure_threshold', 3));
        $openSeconds = max(1, (int) config('ai.circuit_breaker.open_seconds', 60));

        $latest = $this->latestState($provider);
        $priorFailures = $latest && $latest->status !== AiProviderHealth::STATUS_HEALTHY
            ? (int) ($latest->details['consecutive_failures'] ?? 0)
            : 0;

        $consecutiveFailures = $priorFailures + 1;

        // A failed half-open probe re-opens the circuit straight away.
        $open = $consecutiveFailures >= $threshold
            || $latest?->status === AiProviderHealth::STATUS_RECOVERING;

        if ($open) {
            $this->write($provider, AiProviderHealth::STATUS_UNHEALTHY, 0, [
                'consecutive_failures' => $consecutiveFailures,
                'open_until' => now()->addSeconds($openSeconds)->toISOString(),
            ], $latest);

            return;
        }

        $this->write($provider, AiProviderHealth::STATUS_DEGRADED, max(0, 1 - ($consecutiveFailures / $threshold)), [
            'consecutive_failures' => $consecutiveFailures,
        ], $latest);
    }

    /**
     * Same status as the latest row -> update that row in place; otherwise
     * a new row (a state change). Never breaks the caller's chat call.
     *
     * @param  array<string, mixed>  $details
     */
    protected function write(AiProvider $provider, string $status, float $score, array $details, ?AiProviderHealth $latest = null): void
    {
        try {
            if ($latest && $latest->status === $status) {
                $latest->forceFill(['health_score' => $score, 'details' => $details])->save();

                return;
            }

            AiProviderHealth::query()->create([
                'provider_id' => $provider->id,
                'check_type' => self::CHECK_TYPE,
                'health_score' => $score,
                'status' => $status,
                'details' => $details,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function latestState(AiProvider $provider): ?AiProviderHealth
    {
        return AiProviderHealth::query()
            ->where('provider_id', $provider->id)
            ->where('check_type', self::CHECK_TYPE)
            ->latest('id')
            ->first();
    }
}
