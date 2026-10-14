<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;

/** The rider's price on a bidding ride: the first one and each raise (append-only). */
class RidePriceOffer extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['price_minor' => 'integer', 'floor_minor' => 'integer', 'created_at' => 'datetime'];
    }
}
