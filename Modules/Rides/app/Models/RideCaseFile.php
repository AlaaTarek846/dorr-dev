<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;

/** A photo or document attached to a case (D-232, D-291). */
class RideCaseFile extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];
}
