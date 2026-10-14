<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;

class RideCancelReasonTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['ride_cancel_reason_id', 'locale', 'label'];
}
