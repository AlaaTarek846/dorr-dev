<?php

namespace Modules\Driver\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleColorTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['vehicle_color_id', 'locale', 'name'];
}
