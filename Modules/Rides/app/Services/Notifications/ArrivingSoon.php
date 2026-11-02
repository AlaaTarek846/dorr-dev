<?php

namespace Modules\Rides\Services\Notifications;

use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Models\Ride;
use Modules\Rides\Services\Lifecycle\RideStateMachine;
use Modules\Rides\Support\Geo;

/**
 * O-72: "your captain is almost there" — once per ride, when the captain on the way is within
 * `notify.driver_arriving_s` (from the dashboard, 0 = off) of the pickup. Follows the captain's own
 * location uploads; nothing asks on a timer.
 */
class ArrivingSoon
{
    /** Average city speed for the estimate (the same one the request card uses). */
    private const SPEED_MPS = 8.33;

    public function __construct(
        private readonly RideNotifier $notifier,
        private readonly RideStateMachine $machine,
    ) {}

    public function onLocation(int $rideId, float $lat, float $lng): void
    {
        $ride = Ride::query()->find($rideId);
        if ($ride === null || $ride->status !== RideStatus::Accepted) {
            return;
        }
        $before = (int) $ride->livePolicy('notify.driver_arriving_s', 180);
        if ($before <= 0) {
            return;
        }
        $eta = Geo::metres($lat, $lng, (float) $ride->origin_lat, (float) $ride->origin_lng) / self::SPEED_MPS;
        if ($eta > $before || $ride->events()->where('type', 'driver_arriving')->exists()) {
            return;
        }

        $this->machine->record($ride, 'driver_arriving', 'system', null, [], ['eta_s' => (int) round($eta)]);
        $this->notifier->rider($ride, 'arriving_soon', [
            'name' => $ride->driver?->name ?? '',
            'minutes' => max(1, (int) ceil($eta / 60)),
        ]);
    }
}
