<?php

namespace Modules\Driver\Contracts;

use Modules\Driver\Enums\DriverStatus;
use Modules\Driver\Models\Driver;

/** Used only when no service registered its own gate: an active account may work, never on a ride. */
class NoWorkGate implements DriverWorkGate
{
    public function reasonNotToWork(Driver $driver): ?string
    {
        return $driver->status === DriverStatus::Active ? null : 'account_'.$driver->status->value;
    }

    public function activeRide(Driver $driver): ?array
    {
        return null;
    }
}
