<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Driver\Models\Driver;

/** A captain's place in line at an airport zone (D-276..D-280). */
class RideQueueEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'position_at_join' => 'integer',
            'wait_s' => 'integer',
            'joined_at' => 'datetime',
            'outside_since' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(RideZone::class, 'zone_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
