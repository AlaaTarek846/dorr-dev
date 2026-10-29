<?php

namespace Modules\Rides\Services\Safety;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Driver\Models\DriverLocationLog;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Models\Ride;
use Modules\Rides\Support\Geo;
use Throwable;

/**
 * AI-06 — signs a finished ride may not be what it looks like, for the team to look at (never an automatic
 * punishment: the ride, the money and the accounts are untouched):
 *
 *  - `mock_location`: the captain's phone reported a faked (mock) position during the trip;
 *  - `gps_jump`: two positions of the trip further apart than any car can go in that time;
 *  - `no_movement`: charged as a trip, but it started and ended in the same place and barely moved;
 *  - `repeat_pair`: the same rider and captain again and again within a day (`fraud.repeat_pair_max`);
 *  - `short_promo`: a coupon on a ride under a kilometre;
 *  - `instant_end`: started and ended within a minute with a fare.
 *
 * Each sign adds to `risk_score` (0–100); the flags with their details are kept in `risk_flags`. The admin
 * rides list filters on them and the ride page shows them.
 */
class FraudSignals
{
    private const WEIGHTS = ['mock_location' => 45, 'gps_jump' => 25, 'no_movement' => 35, 'repeat_pair' => 30, 'short_promo' => 20, 'instant_end' => 30];

    /** Faster than this between two positions is not a car (km/h). */
    private const IMPOSSIBLE_KMH = 220;

    public function check(Ride $ride): void
    {
        if ($ride->status !== RideStatus::Completed || $ride->driver_id === null) {
            return;
        }
        try {
            $flags = array_values(array_filter([
                ...$this->track($ride),
                $this->noMovement($ride),
                $this->repeatPair($ride),
                $this->shortPromo($ride),
                $this->instantEnd($ride),
            ]));
            $score = (int) min(100, array_sum(array_map(fn (array $f) => self::WEIGHTS[$f['code']] ?? 10, $flags)));
            $ride->forceFill(['risk_flags' => $flags === [] ? null : $flags, 'risk_score' => $score === 0 ? null : $score])->save();
        } catch (Throwable $e) {
            // A check that fails never stops a ride from ending.
            Log::warning('[Rides] fraud signals failed for ride '.$ride->id.': '.$e->getMessage());
        }
    }

    /** @return list<array{code: string, detail: array<string, mixed>}> */
    private function track(Ride $ride): array
    {
        if ($ride->started_at === null || $ride->completed_at === null) {
            return [];
        }
        $points = DriverLocationLog::query()->where('driver_id', $ride->driver_id)
            ->whereBetween('recorded_at', [$ride->started_at, $ride->completed_at])
            ->orderBy('recorded_at')->limit(3000)->get(['lat', 'lng', 'recorded_at', 'is_mock']);
        $flags = [];
        $mock = $points->where('is_mock', true)->count();
        if ($mock > 0) {
            $flags[] = ['code' => 'mock_location', 'detail' => ['points' => $mock, 'of' => $points->count()]];
        }
        $jumps = 0;
        $worst = 0;
        foreach ($points->values() as $i => $point) {
            $prev = $points->values()[$i - 1] ?? null;
            if ($prev === null) {
                continue;
            }
            $seconds = max(1, $prev->recorded_at->diffInSeconds($point->recorded_at));
            $kmh = Geo::metres((float) $prev->lat, (float) $prev->lng, (float) $point->lat, (float) $point->lng) / $seconds * 3.6;
            if ($kmh > self::IMPOSSIBLE_KMH) {
                $jumps++;
                $worst = max($worst, (int) $kmh);
            }
        }
        if ($jumps > 0) {
            $flags[] = ['code' => 'gps_jump', 'detail' => ['jumps' => $jumps, 'max_kmh' => $worst]];
        }

        return $flags;
    }

    /** @return array{code: string, detail: array<string, mixed>}|null */
    private function noMovement(Ride $ride): ?array
    {
        $end = DB::table('ride_events')->where('ride_id', $ride->id)->where('type', 'completed')->first(['lat', 'lng']);
        if ($end?->lat === null || (int) $ride->final_total_minor <= 0) {
            return null;
        }
        $apart = Geo::metres($ride->origin_lat, $ride->origin_lng, (float) $end->lat, (float) $end->lng);
        $planned = (int) ($ride->planned_distance_m ?? 0);
        if ($apart < 150 && (int) ($ride->metered_distance_m ?? 0) < 300 && $planned > 1500) {
            return ['code' => 'no_movement', 'detail' => ['apart_m' => (int) $apart, 'driven_m' => (int) $ride->metered_distance_m, 'planned_m' => $planned]];
        }

        return null;
    }

    /** @return array{code: string, detail: array<string, mixed>}|null */
    private function repeatPair(Ride $ride): ?array
    {
        $max = (int) $ride->policy('fraud.repeat_pair_max', 4);
        if ($max <= 0) {
            return null;
        }
        $count = Ride::query()->where('user_id', $ride->user_id)->where('driver_id', $ride->driver_id)
            ->where('status', RideStatus::Completed->value)->where('completed_at', '>=', now()->subDay())->count();

        return $count > $max ? ['code' => 'repeat_pair', 'detail' => ['rides_24h' => $count, 'max' => $max]] : null;
    }

    /** @return array{code: string, detail: array<string, mixed>}|null */
    private function shortPromo(Ride $ride): ?array
    {
        $coupon = DB::table('wallet_coupon_redemptions')->where('ride_id', $ride->id)->exists();

        return $coupon && (int) ($ride->metered_distance_m ?? 0) < 1000 ? ['code' => 'short_promo', 'detail' => ['driven_m' => (int) $ride->metered_distance_m]] : null;
    }

    /** @return array{code: string, detail: array<string, mixed>}|null */
    private function instantEnd(Ride $ride): ?array
    {
        if ($ride->started_at === null || $ride->completed_at === null || (int) $ride->final_total_minor <= 0) {
            return null;
        }
        $seconds = $ride->started_at->diffInSeconds($ride->completed_at);

        return $seconds < 60 && (int) ($ride->planned_distance_m ?? 0) > 1000 ? ['code' => 'instant_end', 'detail' => ['seconds' => (int) $seconds]] : null;
    }
}
