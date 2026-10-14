<?php

namespace Modules\Driver\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One upload of location points from the driver's phone; its uuid makes a resent batch a no-op. */
class DriverLocationBatch extends Model
{
    public $timestamps = false;

    protected $fillable = ['driver_id', 'batch_uuid', 'points_count', 'first_recorded_at', 'last_recorded_at', 'clock_offset_ms', 'received_at'];

    protected function casts(): array
    {
        return [
            'points_count' => 'integer',
            'clock_offset_ms' => 'integer',
            'first_recorded_at' => 'datetime',
            'last_recorded_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function points(): HasMany
    {
        return $this->hasMany(DriverLocationLog::class, 'batch_id');
    }
}
