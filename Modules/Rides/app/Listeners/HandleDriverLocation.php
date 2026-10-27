<?php

namespace Modules\Rides\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Driver\Events\DriverLocationUpdated;
use Modules\Rides\Services\Airport\AirportQueue;
use Modules\Rides\Services\Airport\RestrictedZones;
use Throwable;

/**
 * A captain's latest point arrived: their place in an airport queue (in, out, grace clock) and the
 * alert for a restricted area follow it. Best effort — a failure here never fails the location upload.
 */
class HandleDriverLocation
{
    public function __construct(private readonly AirportQueue $queue, private readonly RestrictedZones $zones) {}

    public function handle(DriverLocationUpdated $event): void
    {
        try {
            $this->queue->onLocation($event->driver, $event->lat, $event->lng);
            $this->zones->onLocation($event->driver, $event->lat, $event->lng);
            // O-72: the rider hears the captain is about to arrive.
            if ($event->rideId !== null) {
                app(\Modules\Rides\Services\Notifications\ArrivingSoon::class)->onLocation((int) $event->rideId, $event->lat, $event->lng);
            }
            // O-79: requests waiting near a free captain go out now.
            if ($event->rideId === null) {
                app(\Modules\Rides\Services\Dispatch\DispatchEngine::class)->nudgeNear((int) $event->driver->id, $event->lat, $event->lng);
            }
        } catch (Throwable $e) {
            Log::warning('[Rides] driver location follow-up failed: '.$e->getMessage());
        }
    }
}
