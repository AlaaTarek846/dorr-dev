<?php

namespace Modules\Rides\Services\Dispatch;

use Illuminate\Support\Collection;
use Modules\Rides\Models\DriverRideProfile;

/**
 * AI-01 — the order captains are asked in, when the country turns `dispatch.smart` on (Prices & rules).
 * Off, it stays nearest first. On, each captain gets a cost in **minutes**, lowest first:
 *
 *   minutes to the pickup
 *   + up to `dispatch.acceptance_min` more for a captain who rarely accepts (0% acceptance = all of it),
 *   + up to `dispatch.fairness_min` more for a captain who just had a trip (none after 2 hours waiting).
 *
 * So the weights read in plain words: "a captain who never accepts counts as 3 minutes farther". Who may
 * be asked at all (eligibility, the vehicle's category, blocks, the airport line, favourites first) is
 * decided before this and never changed by it.
 */
final class SmartDispatch
{
    private const PICKUP_SPEED_MPS = 8.3;

    private const IDLE_FULL_MIN = 120;

    /**
     * @param  Collection<int, array{0: \Modules\Driver\Models\DriverPresence, 1: float}>  $pairs
     * @param  array<string, mixed>  $policy
     * @return array<int, float>|null driver id → cost in minutes; null when smart dispatch is off
     */
    public static function costs(Collection $pairs, array $policy): ?array
    {
        if (empty($policy['dispatch.smart']) || $pairs->isEmpty()) {
            return null;
        }
        $acceptanceMin = max(0, (int) ($policy['dispatch.acceptance_min'] ?? 3));
        $fairnessMin = max(0, (int) ($policy['dispatch.fairness_min'] ?? 2));
        $profiles = DriverRideProfile::query()->whereIn('driver_id', $pairs->map(fn (array $p) => $p[0]->driver_id))->get()->keyBy('driver_id');

        $costs = [];
        foreach ($pairs as [$presence, $distance]) {
            $profile = $profiles->get($presence->driver_id);
            $acceptance = $profile === null ? 1.0 : min(1, (int) $profile->acceptance_bp / 10000);
            $idle = $profile?->last_trip_at === null ? self::IDLE_FULL_MIN : min(self::IDLE_FULL_MIN, (int) $profile->last_trip_at->diffInMinutes(now()));
            $costs[(int) $presence->driver_id] = round(
                $distance / self::PICKUP_SPEED_MPS / 60
                + (1 - $acceptance) * $acceptanceMin
                + (1 - $idle / self::IDLE_FULL_MIN) * $fairnessMin,
                3,
            );
        }

        return $costs;
    }
}
