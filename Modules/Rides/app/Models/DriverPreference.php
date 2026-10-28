<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;

/** What a driver wants to receive, and how the app behaves for them. */
class DriverPreference extends Model
{
    protected $primaryKey = 'driver_id';

    public $incrementing = false;

    public const CREATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['categories' => 'array', 'destination' => 'array', 'auto_accept' => 'array', 'sounds' => 'array', 'voice_readout' => 'boolean', 'hide_earnings' => 'boolean', 'pickup_radius_m' => 'integer', 'daily_goal_minor' => 'integer', 'pet_friendly' => 'boolean', 'child_seats' => 'integer', 'next_trips' => 'boolean'];
    }
}
