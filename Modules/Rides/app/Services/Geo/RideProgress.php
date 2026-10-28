<?php

namespace Modules\Rides\Services\Geo;

use Modules\Driver\Models\DriverPresence;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Models\Ride;
use Modules\Rides\Support\Geo;

/**
 * Where the ride is heading now and how far it still is (rides_tasks D-129): from the captain's last
 * position to the next point — the pickup before the trip, then each stop left, then the destination.
 * No map call: the straight line × `gps_gap.straight_factor_bp`, at the speed {@see LearnedEta} gives. Both apps show it,
 * so they never disagree.
 */
final class RideProgress
{
    /** @return array{0: float, 1: float, 2: string}|null lat, lng, and what it is: pickup | stop | destination */
    public static function nextPoint(Ride $ride): ?array
    {
        if (in_array($ride->status, [RideStatus::Accepted, RideStatus::Arrived], true)) {
            return [$ride->origin_lat, $ride->origin_lng, 'pickup'];
        }
        if ($ride->status !== RideStatus::InProgress) {
            return null;
        }
        $stop = $ride->stops->first(fn ($s) => $s->departed_at === null);
        if ($stop !== null) {
            return [(float) $stop->lat, (float) $stop->lng, 'stop'];
        }

        return $ride->destination_lat === null ? null : [$ride->destination_lat, $ride->destination_lng, 'destination'];
    }

    /**
     * Null while waiting at the pickup, before a captain, after the end, or without a recent position.
     *
     * @return array{to: string, distance_m: int, eta_s: int, at: ?string}|null
     */
    public static function of(Ride $ride): ?array
    {
        if (! in_array($ride->status, [RideStatus::Accepted, RideStatus::InProgress], true) || $ride->driver_id === null) {
            return null;
        }
        $next = self::nextPoint($ride);
        $presence = DriverPresence::query()->find($ride->driver_id);
        if ($next === null || $presence?->lat === null) {
            return null;
        }

        $factor = (int) $ride->policy('gps_gap.straight_factor_bp', 13000);
        $distance = (int) round(Geo::metres((float) $presence->lat, (float) $presence->lng, $next[0], $next[1]) * $factor / 10000);
        // AI-03: the city's own speed at this hour, learned from its trips (the policy's speed until there are enough).
        $speed = LearnedEta::speedKmh($ride);

        return [
            'to' => $next[2],
            'distance_m' => $distance,
            // Rounded up to the minute: "1 min" rather than "0 min" until really there.
            'eta_s' => (int) ceil($distance / ($speed / 3.6) / 60) * 60,
            'at' => $presence->last_seen_at?->toIso8601String(),
        ];
    }
}
