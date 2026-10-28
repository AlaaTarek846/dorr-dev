<?php

namespace Modules\Rides\Services\Geo;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Models\Ride;

/**
 * AI-03 — the speed a remaining time is worked out at, learned from the city's own finished trips at this
 * hour of the day (the last `eta.learn_days`, the hour before and after too): the rush hour is slower than
 * the night, and the estimate follows. Below `eta.min_samples` trips it is the policy's `eta.speed_kmh`.
 * Kept between 8 and 90 km/h so one strange trip can't make a promise. No map call.
 */
final class LearnedEta
{
    private const MIN_KMH = 8;

    private const MAX_KMH = 90;

    public static function speedKmh(Ride $ride): int
    {
        $fallback = max(1, (int) $ride->policy('eta.speed_kmh', config('rides.policy_defaults')['eta.speed_kmh'] ?? 30));
        $days = (int) $ride->policy('eta.learn_days', config('rides.policy_defaults')['eta.learn_days'] ?? 14);
        if ($days <= 0 || $ride->city_id === null) {
            return $fallback;
        }
        $minSamples = max(1, (int) $ride->policy('eta.min_samples', config('rides.policy_defaults')['eta.min_samples'] ?? 20));
        $tz = (string) config('rides.default_timezone', 'Asia/Riyadh');
        $hour = (int) CarbonImmutable::now($tz)->format('G');

        $learned = Cache::remember('rides:eta:'.$ride->city_id.':'.$hour.':'.$days, now()->addMinutes(30), function () use ($ride, $days, $hour, $tz) {
            $hours = [($hour + 23) % 24, $hour, ($hour + 1) % 24];
            $rows = DB::table('rides')->where('city_id', $ride->city_id)->where('status', RideStatus::Completed->value)
                ->where('completed_at', '>=', now()->subDays($days))
                ->whereNotNull('started_at')->where('metered_distance_m', '>', 500)
                ->latest('id')->limit(2000)->get(['started_at', 'completed_at', 'metered_distance_m']);
            $speeds = [];
            foreach ($rows as $row) {
                $start = CarbonImmutable::parse($row->started_at)->setTimezone($tz);
                if (! in_array((int) $start->format('G'), $hours, true)) {
                    continue;
                }
                $seconds = CarbonImmutable::parse($row->completed_at)->getTimestamp() - $start->getTimestamp();
                if ($seconds >= 60) {
                    $speeds[] = (int) $row->metered_distance_m / $seconds * 3.6;
                }
            }
            if ($speeds === []) {
                return ['n' => 0, 'kmh' => null];
            }
            sort($speeds);

            // The median: a few odd trips don't move it.
            return ['n' => count($speeds), 'kmh' => $speeds[intdiv(count($speeds), 2)]];
        });

        if ($learned['n'] < $minSamples || $learned['kmh'] === null) {
            return $fallback;
        }

        return (int) round(min(self::MAX_KMH, max(self::MIN_KMH, $learned['kmh'])));
    }
}
