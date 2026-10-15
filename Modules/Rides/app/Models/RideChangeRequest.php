<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A new destination or an extra stop asked during the ride, with what it does to the price (R-91..95). */
class RideChangeRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'route' => 'array',
            'breakdown' => 'array',
            'distance_delta_m' => 'integer',
            'duration_delta_s' => 'integer',
            'price_delta_minor' => 'integer',
            'new_total_minor' => 'integer',
            'driver_net_delta_minor' => 'integer',
            'rider_consent_at' => 'datetime',
            'driver_responded_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['preview', 'pending'], true) && $this->expires_at->isFuture();
    }
}
