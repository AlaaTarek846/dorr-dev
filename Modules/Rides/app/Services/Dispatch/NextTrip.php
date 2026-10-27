<?php

namespace Modules\Rides\Services\Dispatch;

use Illuminate\Support\Collection;
use Modules\Driver\Enums\PresenceStatus;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverPresence;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Models\DriverPreference;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideActiveLock;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\RideProgress;
use Modules\Rides\Services\Notifications\RideNotifier;
use Modules\Rides\Support\Geo;

/**
 * The next trip (Block 06 phase 2; D-INN «اقتراح الرحلة التالية»): a captain about to finish a ride is
 * offered a request that starts near where it ends — before the end, so they don't drive back empty.
 *
 *  - Only where the `next_trip` switch is on, only while the current ride has at most
 *    `next_trip.before_end_s` left and the new pickup is within `next_trip.radius_m` of its destination.
 *  - Not for a captain who asked for a last ride, or switched next trips off in their preferences.
 *  - One next trip at a time: its own lock `next:{driver}` (the captain's one-ride lock stays on the
 *    ride they are driving), so nothing is booked twice; accepting is the usual atomic accept.
 *  - The card shows the captain's net before accepting, like any request, and doesn't ring full-screen.
 *  - When the current ride ends, the next one becomes the captain's ride (`driver:{id}`) and they head
 *    to the pickup; before that, arriving at it is refused.
 */
class NextTrip
{
    public const LOCK_PREFIX = 'next:';

    public function __construct(private readonly RideFeatures $features, private readonly RideNotifier $notifier) {}

    public static function lockKey(int $driverId): string
    {
        return self::LOCK_PREFIX.$driverId;
    }

    /**
     * Captains finishing a ride near this pickup, with the distance they will really cover to get there
     * (what's left of their ride, then from its end to the pickup).
     *
     * @param  array<string, mixed>  $policy
     * @param  Collection<int, int>  $excluded
     * @return Collection<int, array{0: DriverPresence, 1: float}>
     */
    public function candidates(Ride $ride, array $policy, Collection $excluded): Collection
    {
        if ($ride->status !== RideStatus::Searching || $ride->scheduled_at !== null || $ride->pool_id !== null
            || ! $this->features->allows((int) $ride->country_id, $ride->city_id, 'next_trip')) {
            return collect();
        }
        $radius = (int) ($policy['next_trip.radius_m'] ?? 2000);
        $beforeEnd = (int) ($policy['next_trip.before_end_s'] ?? 300);
        [$minLat, $maxLat, $minLng, $maxLng] = Geo::boxAround($ride->origin_lat, $ride->origin_lng, $radius);

        // Rides on the road that end near this pickup, whose captain has no next trip yet.
        $finishing = Ride::query()->where('status', RideStatus::InProgress->value)->whereNotNull('driver_id')
            ->where('open_destination', false)->whereNull('pool_id')
            ->whereBetween('destination_lat', [$minLat, $maxLat])->whereBetween('destination_lng', [$minLng, $maxLng])
            ->whereNotIn('driver_id', $excluded->all())
            ->whereNotIn('driver_id', RideActiveLock::query()->where('lock_key', 'like', self::LOCK_PREFIX.'%')->pluck('lock_key')->map(fn (string $k) => (int) substr($k, strlen(self::LOCK_PREFIX))))
            ->get();
        if ($finishing->isEmpty()) {
            return collect();
        }
        $optedOut = DriverPreference::query()->whereIn('driver_id', $finishing->pluck('driver_id'))->where('next_trips', false)->pluck('driver_id')->all();
        $presences = DriverPresence::query()->whereIn('driver_id', $finishing->pluck('driver_id'))
            ->where('status', PresenceStatus::OnTrip->value)->where('gps_enabled', true)->where('last_ride_requested', false)
            ->where('last_seen_at', '>=', now()->subSeconds((int) ($policy['driver.stale_after_s'] ?? 900)))
            ->get()->keyBy('driver_id');

        return $finishing
            ->reject(fn (Ride $current) => in_array($current->driver_id, $optedOut, true) || ! $presences->has($current->driver_id))
            ->map(function (Ride $current) use ($ride, $presences, $radius, $beforeEnd) {
                $progress = RideProgress::of($current);
                $toEnd = Geo::metres((float) $current->destination_lat, (float) $current->destination_lng, $ride->origin_lat, $ride->origin_lng);
                if ($progress === null || $progress['to'] !== 'destination' || (int) $progress['eta_s'] > $beforeEnd || $toEnd > $radius) {
                    return null;
                }

                return [$presences[$current->driver_id], (float) $progress['distance_m'] + $toEnd];
            })
            ->filter()->values();
    }

    /** The ride this captain is driving now, if they are finishing one — a request offered to them is a next trip. */
    public static function drivingNow(int $driverId): ?Ride
    {
        return Ride::query()->where('driver_id', $driverId)->where('status', RideStatus::InProgress->value)->latest('id')->first();
    }

    /** The lock an accepted next trip takes, and the ride it follows (null = a normal accept). */
    public function lockFor(Driver $driver, Ride $ride): ?array
    {
        $current = self::drivingNow($driver->id);
        if ($current === null || $current->id === $ride->id || ! $this->features->allows((int) $ride->country_id, $ride->city_id, 'next_trip')) {
            return null;
        }

        return ['key' => self::lockKey($driver->id), 'after' => $current];
    }

    /**
     * A ride ended: the captain's next trip (if any) becomes their ride, and both sides hear the captain is
     * on the way now.
     */
    public function promoteAfter(Ride $ended): void
    {
        if ($ended->driver_id === null) {
            return;
        }
        $lock = RideActiveLock::query()->find(self::lockKey((int) $ended->driver_id));
        if ($lock === null) {
            return;
        }
        $next = Ride::query()->find($lock->ride_id);
        $lock->delete();
        if ($next === null || ! in_array($next->status, [RideStatus::Accepted, RideStatus::Arrived], true)) {
            return;
        }
        RideActiveLock::query()->firstOrCreate(['lock_key' => RideActiveLock::forDriver((int) $ended->driver_id)], ['ride_id' => $next->id]);
        $driver = Driver::query()->find($ended->driver_id);
        if ($driver !== null) {
            $this->notifier->driver($driver, $next, 'next_trip_go');
        }
        $this->notifier->rider($next, 'next_trip_on_way');
        $this->notifier->changed($next, 'next_trip_started');
    }

    /** Whether this ride is a next trip whose captain is still driving the ride before it. */
    public static function waiting(Ride $ride): ?Ride
    {
        if ($ride->after_ride_id === null || ! in_array($ride->status, [RideStatus::Accepted, RideStatus::Arrived], true)) {
            return null;
        }
        $before = Ride::query()->find($ride->after_ride_id);

        return $before !== null && $before->status === RideStatus::InProgress && $before->driver_id === $ride->driver_id ? $before : null;
    }

    /**
     * For the rider of a next trip: their captain is finishing a ride nearby, and about how long until they come.
     *
     * @return array{finishing: bool, eta_s: int}|null
     */
    public static function present(Ride $ride): ?array
    {
        $before = self::waiting($ride);
        if ($before === null) {
            return null;
        }
        $progress = RideProgress::of($before);
        $speed = \Modules\Rides\Services\Geo\LearnedEta::speedKmh($ride) / 3.6;
        $toPickup = Geo::metres((float) $before->destination_lat, (float) $before->destination_lng, $ride->origin_lat, $ride->origin_lng);

        return ['finishing' => true, 'eta_s' => (int) (($progress['eta_s'] ?? 0) + ceil($toPickup / $speed / 60) * 60)];
    }
}
