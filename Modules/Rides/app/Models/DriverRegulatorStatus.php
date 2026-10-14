<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\Vehicle;

/** The regulator's (Wasl's) answer about a driver and their vehicle. */
class DriverRegulatorStatus extends Model
{
    protected $table = 'driver_regulator_status';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['driver_expires_at' => 'date', 'vehicle_expires_at' => 'date', 'blocked_until' => 'date', 'rejection_codes' => 'array', 'vehicle_rejection_codes' => 'array', 'registration_attempts' => 'integer', 'last_registered_at' => 'datetime', 'last_checked_at' => 'datetime', 'next_check_at' => 'datetime'];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
