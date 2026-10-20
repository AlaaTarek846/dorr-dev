<?php

namespace Modules\Rides\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Driver\Events\DriverLocationUpdated;
use Modules\Rides\Events\RideDriverMoved;
use Modules\Rides\Models\Ride;
use Throwable;

/** A driver on a ride moved: the rider's map follows (best effort — a broadcast hiccup never fails the upload). */
class RelayDriverLocation
{
    public function handle(DriverLocationUpdated $event): void
    {
        if ($event->rideId === null) {
            return;
        }

        $uuid = Ride::query()->whereKey($event->rideId)->value('uuid');
        if ($uuid === null) {
            return;
        }

        try {
            broadcast(new RideDriverMoved($uuid, $event->lat, $event->lng, $event->heading, $event->speedMps));
        } catch (Throwable $e) {
            Log::warning('[Rides] driver location relay failed: '.$e->getMessage());
        }
    }
}
