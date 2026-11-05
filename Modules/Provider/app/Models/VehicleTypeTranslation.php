<?php

namespace Modules\Provider\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleTypeTranslation extends Model
{
    public $timestamps = false;

    protected $table = 'provider_vehicle_type_translations';

    protected $fillable = ['provider_vehicle_type_id', 'locale', 'name', 'description'];
}
