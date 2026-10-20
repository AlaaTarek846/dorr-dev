<?php

namespace Modules\Rides\Listeners;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Driver\Enums\PresenceStatus;
use Modules\Driver\Events\DriverLocationUpdated;
use Modules\Driver\Models\DriverPresence;
use Modules\Rides\Events\AreaDriverMoved;
use Throwable;

/**
 * O-59: a free, online captain's new position goes to the riders' maps around him — at most every few
 * seconds per captain. A captain on a ride isn't shown as free (his car leaves the map).
 */
class BroadcastAreaDriver
{
    private const EVERY_S = 4;

    public function handle(DriverLocationUpdated $event): void
    {
        try {
            if (! in_array(config('broadcasting.default'), ['pusher', 'reverb'], true)) {
                return;
            }
            $driverId = (int) $event->driver->getKey();
            if (! Cache::add('rides:area-car:'.$driverId, 1, self::EVERY_S)) {
                return;
            }
            $online = DriverPresence::query()->whereKey($driverId)->value('status') === PresenceStatus::Online->value;
            $free = $event->rideId === null;
            $cell = AreaDriverMoved::cellOf($event->lat, $event->lng);
            $alias = AreaDriverMoved::alias($driverId);

            // Moved to another cell: the old one hears it left.
            $last = Cache::get('rides:area-cell:'.$driverId);
            if ($last !== null && $last !== $cell) {
                broadcast(new AreaDriverMoved($last, $alias, $event->lat, $event->lng, $event->heading, true));
            }
            Cache::put('rides:area-cell:'.$driverId, $cell, now()->addHour());

            broadcast(new AreaDriverMoved($cell, $alias, $event->lat, $event->lng, $event->heading, ! ($online && $free)));
        } catch (Throwable $e) {
            Log::warning('[Rides] area car broadcast failed: '.$e->getMessage());
        }
    }
}
