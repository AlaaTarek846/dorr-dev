<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;

class RideRatingTagTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['ride_rating_tag_id', 'locale', 'label'];
}
