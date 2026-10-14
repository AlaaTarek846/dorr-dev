<?php

namespace Modules\Driver\Events;

use Modules\Driver\Models\Driver;

/** A batch of points arrived: the latest one, and the ride it belongs to (rides relays it to the rider). */
class DriverLocationUpdated
{
    public function __construct(
        public readonly Driver $driver,
        public readonly float $lat,
        public readonly float $lng,
        public readonly ?int $heading,
        public readonly ?float $speedMps,
        public readonly ?int $rideId,
    ) {}
}
