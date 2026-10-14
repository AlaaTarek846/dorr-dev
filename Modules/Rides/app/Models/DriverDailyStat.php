<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;

/** One driver's day (in their city's time zone): what the earnings and stats screens read. */
class DriverDailyStat extends Model
{
    public const CREATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['online_s' => 'integer', 'trips' => 'integer', 'offers_received' => 'integer', 'offers_accepted' => 'integer', 'offers_declined' => 'integer', 'cancels' => 'integer', 'gross_minor' => 'integer', 'net_minor' => 'integer', 'tips_minor' => 'integer', 'bonus_minor' => 'integer', 'commission_minor' => 'integer', 'tax_minor' => 'integer', 'distance_with_rider_m' => 'integer', 'distance_empty_m' => 'integer', 'points' => 'integer'];
    }
}
