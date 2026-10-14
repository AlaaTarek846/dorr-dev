<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;

/** A rider's numbers in rides — rating_avg is the customerRating Wasl receives. */
class RiderRideProfile extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public const CREATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['rating_avg' => 'float', 'rating_count' => 'integer', 'trips_count' => 'integer', 'cancels_count' => 'integer', 'no_show_count' => 'integer', 'last_trip_at' => 'datetime'];
    }
}
