<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;

/** A rider's favourite driver (DORR Circle, phase 7). */
class FavoriteDriver extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }
}
