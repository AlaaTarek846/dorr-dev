<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Are you OK?" — after a long stop / a detour (R-222, D-144) or some minutes after arriving (R-223). */
class RideSafetyCheck extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'target_id' => 'integer', 'meta' => 'array',
            'due_at' => 'datetime', 'asked_at' => 'datetime', 'answered_at' => 'datetime', 'escalated_at' => 'datetime',
        ];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }
}
