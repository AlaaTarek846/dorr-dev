<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Admin\Models\Admin;

/** An SOS from the rider or the captain: where and when, then who handled it (R-110, D-141). */
class RideSafetyIncident extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'lat' => 'float', 'lng' => 'float', 'reporter_id' => 'integer',
            'acknowledged_at' => 'datetime', 'resolved_at' => 'datetime',
        ];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'handled_by_admin_id');
    }
}
