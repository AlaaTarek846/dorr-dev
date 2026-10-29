<?php

namespace Modules\Driver\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Driver\Models\Driver;

/** O-79: a driver started work at this point (after DriverLocationUpdated for the same point). */
class DriverCameOnline
{
    use Dispatchable;

    public function __construct(
        public readonly Driver $driver,
        public readonly float $lat,
        public readonly float $lng,
    ) {}
}
