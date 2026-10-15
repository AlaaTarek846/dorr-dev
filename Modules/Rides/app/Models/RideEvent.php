<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only: who did what to a ride, where and when (server time) — the evidence in any dispute. */
class RideEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'lat' => 'float', 'lng' => 'float', 'created_at' => 'datetime'];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }
}
