<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Driver\Models\Driver;
use Modules\Rides\Enums\RideStatus;

/** «أنا معك» (O-30..O-33): the riders a captain carries together, going his way. */
class RidePool extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (RidePool $pool) => $pool->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['destination_lat' => 'float', 'destination_lng' => 'float', 'radius_m' => 'integer', 'seats' => 'integer', 'closed_at' => 'datetime'];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function rides(): HasMany
    {
        return $this->hasMany(Ride::class, 'pool_id');
    }

    /** The rides still on: picked up or on the way to be. */
    public function activeRides(): HasMany
    {
        return $this->rides()->whereIn('status', [RideStatus::Accepted->value, RideStatus::Arrived->value, RideStatus::InProgress->value]);
    }

    public function splits(): HasMany
    {
        return $this->hasMany(RidePoolSplit::class);
    }
}
