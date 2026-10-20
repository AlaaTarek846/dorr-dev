<?php

namespace Modules\Driver\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleMakeTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['vehicle_make_id', 'locale', 'name'];
}
