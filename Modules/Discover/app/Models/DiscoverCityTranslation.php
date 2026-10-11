<?php

namespace Modules\Discover\Models;

use Illuminate\Database\Eloquent\Model;

class DiscoverCityTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['discover_city_id', 'locale', 'name'];
}
