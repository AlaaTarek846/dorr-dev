<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;

class RidePickupPointTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['ride_pickup_point_id', 'locale', 'name', 'instructions'];
}
