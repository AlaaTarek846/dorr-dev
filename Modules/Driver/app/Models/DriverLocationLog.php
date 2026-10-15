<?php

namespace Modules\Driver\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One location point (append-only — evidence for disputes, and what Wasl receives). Rows are only
 * ever added, and removed in bulk once older than the retention period.
 */
class DriverLocationLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'driver_location_log';

    protected $fillable = [
        'driver_id', 'ride_id', 'batch_id', 'lat', 'lng', 'accuracy_m', 'speed_mps', 'heading',
        'recorded_at', 'device_time', 'has_customer', 'is_mock', 'wasl_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'accuracy_m' => 'integer',
            'speed_mps' => 'float',
            'heading' => 'integer',
            'ride_id' => 'integer',
            'recorded_at' => 'datetime',
            'device_time' => 'datetime',
            'has_customer' => 'boolean',
            'is_mock' => 'boolean',
            'wasl_sent_at' => 'datetime',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
