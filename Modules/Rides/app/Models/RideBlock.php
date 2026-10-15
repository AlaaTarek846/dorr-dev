<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;

/** Never match these two again (rider ↔ driver, either side, R-225 / D-410). */
class RideBlock extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }
}
