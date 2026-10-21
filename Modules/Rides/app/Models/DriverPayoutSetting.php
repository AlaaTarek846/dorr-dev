<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;

/** A captain's payout safeguards: international payouts, an emergency freeze (D-223, D-224, D-299). */
class DriverPayoutSetting extends Model
{
    protected $primaryKey = 'driver_id';

    public $incrementing = false;

    public const CREATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['international_requested_at' => 'datetime', 'international_answered_at' => 'datetime', 'frozen_at' => 'datetime'];
    }
}
