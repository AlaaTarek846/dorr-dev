<?php

namespace Modules\Driver\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Driver\Enums\PresenceStatus;

/** One row per driver: their state and where they are right now (dispatch and the live map read it). */
class DriverPresence extends Model
{
    public const CREATED_AT = null;

    protected $table = 'driver_presence';

    protected $primaryKey = 'driver_id';

    public $incrementing = false;

    protected $fillable = [
        'driver_id', 'status', 'lat', 'lng', 'heading', 'speed_mps', 'accuracy_m', 'city_id', 'gps_enabled',
        'gps_off_since', 'last_ride_requested', 'paused_until', 'online_since', 'last_seen_at', 'app_version',
    ];

    protected function casts(): array
    {
        return [
            'status' => PresenceStatus::class,
            'lat' => 'float',
            'lng' => 'float',
            'heading' => 'integer',
            'speed_mps' => 'float',
            'accuracy_m' => 'integer',
            'city_id' => 'integer',
            'gps_enabled' => 'boolean',
            'last_ride_requested' => 'boolean',
            'gps_off_since' => 'datetime',
            'paused_until' => 'datetime',
            'online_since' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
