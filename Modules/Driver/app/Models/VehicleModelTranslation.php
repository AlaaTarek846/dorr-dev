<?php

namespace Modules\Driver\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleModelTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['vehicle_model_id', 'locale', 'name'];
}
