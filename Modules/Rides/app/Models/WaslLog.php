<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Driver\Models\Driver;

/**
 * Append-only: every call to Wasl and its answer (no secrets) — Jawad's `wasl_logs`. The subject is
 * the driver (registration, eligibility), the ride (trip) or nobody (a bulk of locations).
 */
class WaslLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['request' => 'array', 'response' => 'array', 'success' => 'boolean', 'status_code' => 'integer', 'duration_ms' => 'integer'];
    }

    /** Only meaningful when `subject_type` is "driver" — read it through subject(). */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'subject_id');
    }

    /** Only meaningful when `subject_type` is "ride" — read it through subject(). */
    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class, 'subject_id');
    }

    /** The driver or the ride this call was about (eager-load both relations for a list). */
    public function subject(): Driver|Ride|null
    {
        return match ($this->subject_type) {
            'driver' => $this->driver,
            'ride' => $this->ride,
            default => null,
        };
    }
}
