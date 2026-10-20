<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;

/** One rider joining a pool: what the captain took, and what each rider got off (kept for disputes). */
class RidePoolSplit extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['discounts' => 'array', 'price_minor' => 'integer', 'driver_cut_minor' => 'integer', 'created_at' => 'datetime'];
    }
}
