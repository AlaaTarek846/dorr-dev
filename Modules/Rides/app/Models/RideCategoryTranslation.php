<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;

class RideCategoryTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['ride_category_id', 'locale', 'name', 'tagline', 'description'];
}
