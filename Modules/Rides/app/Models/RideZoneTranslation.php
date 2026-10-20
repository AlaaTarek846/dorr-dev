<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;

class RideZoneTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['ride_zone_id', 'locale', 'name'];
}
