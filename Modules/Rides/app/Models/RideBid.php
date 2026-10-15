<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Driver\Models\Driver;
use Modules\Rides\Enums\BidStatus;

/** A driver's higher offer on a bidding ride (docs/rides-plan.md §9.2): the rider has `bids.ttl_s` to take it. */
class RideBid extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (RideBid $bid): void {
            $bid->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => BidStatus::class,
            'attempt' => 'integer',
            'rider_price_minor' => 'integer',
            'bid_price_minor' => 'integer',
            'driver_net_minor' => 'integer',
            'distance_m' => 'integer',
            'eta_s' => 'integer',
            'shown_at' => 'datetime',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
