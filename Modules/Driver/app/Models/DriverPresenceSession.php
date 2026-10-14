<?php

namespace Modules\Driver\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One stretch of being online — the "hours online" in the driver's stats. */
class DriverPresenceSession extends Model
{
    public $timestamps = false;

    protected $fillable = ['driver_id', 'started_at', 'ended_at', 'end_reason'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
