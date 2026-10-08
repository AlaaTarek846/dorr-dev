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
 * State is a sequence of ai_provider_health rows (check_type=functional)
 * rather than a dedicated table: each call outcome writes one row, and
 * the breaker's current state is always "whatever the latest row for
 * this provider says" - this deliberately reuses the table the v2.0 doc
 * already describes as what routing should be looking at, rather than
 * inventing a second, competing source of provider-health truth.
 *
 * This is intentionally simple (closed -> open -> half-open probe on the
 * next call after open_seconds), not a full state machine with a
 * separate half-open trial-count - good enough for the current call
 * volume, and easy to extend if that stops being true.
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

        // The cooldown has elapsed - this call is let through as a
        // "half-open" probe. It is not marked recovering here; that only
        // happens once the probe itself reports success or failure via
        // recordSuccess()/recordFailure() below.
        return Carbon::parse($openUntil)->isFuture();
    }

    public function recordSuccess(AiProvider $provider): void
    {
        AiProviderHealth::query()->create([
            'provider_id' => $provider->id,
            'check_type' => self::CHECK_TYPE,
            'health_score' => 1,
            'status' => AiProviderHealth::STATUS_HEALTHY,
            'details' => ['consecutive_failures' => 0],
        ]);
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

        if ($consecutiveFailures >= $threshold) {
            AiProviderHealth::query()->create([
                'provider_id' => $provider->id,
                'check_type' => self::CHECK_TYPE,
                'health_score' => 0,
                'status' => AiProviderHealth::STATUS_UNHEALTHY,
                'details' => [
                    'consecutive_failures' => $consecutiveFailures,
                    'open_until' => now()->addSeconds($openSeconds)->toISOString(),
                ],
            ]);

            return;
        }

        AiProviderHealth::query()->create([
            'provider_id' => $provider->id,
            'check_type' => self::CHECK_TYPE,
            'health_score' => max(0, 1 - ($consecutiveFailures / $threshold)),
            'status' => AiProviderHealth::STATUS_DEGRADED,
            'details' => ['consecutive_failures' => $consecutiveFailures],
        ]);
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
