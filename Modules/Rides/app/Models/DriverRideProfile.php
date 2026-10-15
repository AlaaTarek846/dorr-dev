<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;

/** A driver's numbers in passenger rides: tier, rating, trips, rates, points balance. */
class DriverRideProfile extends Model
{
    protected $primaryKey = 'driver_id';

    public $incrementing = false;

    public const CREATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['rating_avg' => 'float', 'rating_count' => 'integer', 'trips_count' => 'integer', 'acceptance_bp' => 'integer', 'cancel_bp' => 'integer', 'completion_bp' => 'integer', 'points_balance' => 'integer', 'last_trip_at' => 'datetime'];
    }
}
