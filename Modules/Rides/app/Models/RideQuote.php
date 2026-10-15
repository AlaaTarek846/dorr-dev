<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Rides\Enums\PricingMode;
use Modules\User\Models\User;

/**
 * A priced trip for one category, valid until `expires_at` (R-54). The rider confirms the quote
 * (its uuid + fingerprint), never a raw number; the server re-checks it before creating the ride.
 */
class RideQuote extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'pricing_mode' => PricingMode::class,
            'stops' => 'array',
            'lines' => 'array',
            'policy_snapshot' => 'array',
            'route_estimated' => 'boolean',
            'city_id' => 'integer',
            'origin_lat' => 'float',
            'origin_lng' => 'float',
            'destination_lat' => 'float',
            'destination_lng' => 'float',
            'distance_m' => 'integer',
            'duration_s' => 'integer',
            'total_minor' => 'integer',
            'estimate_min_minor' => 'integer',
            'estimate_max_minor' => 'integer',
            'hold_minor' => 'integer',
            'driver_net_minor' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(RideCategory::class);
    }
}
