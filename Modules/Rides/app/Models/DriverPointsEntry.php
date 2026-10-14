<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only: points a driver earned or lost, and the balance after. */
class DriverPointsEntry extends Model
{
    protected $table = 'driver_points_ledger';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['points' => 'integer', 'balance_after' => 'integer'];
    }
}
