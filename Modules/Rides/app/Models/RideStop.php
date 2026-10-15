<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A stop between the pickup and the destination (R-11). */
class RideStop extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float', 'seq' => 'integer', 'wait_s' => 'integer', 'arrived_at' => 'datetime', 'departed_at' => 'datetime'];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }
}
