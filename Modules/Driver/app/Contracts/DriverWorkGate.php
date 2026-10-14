<?php

namespace Modules\Driver\Contracts;

use Modules\Driver\Models\Driver;

/**
 * What the services a driver works in (Modules/Rides today) tell the driver module, so it never has
 * to know about rides itself: may this driver go online, and are they on a ride right now.
 */
interface DriverWorkGate
{
    /** Null when the driver may go online; otherwise a reason code the app shows (D-27). */
    public function reasonNotToWork(Driver $driver): ?string;

    /** @return array{id: int, has_customer: bool}|null The driver's active ride, if any. */
    public function activeRide(Driver $driver): ?array;
}
