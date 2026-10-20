<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** An in-app voice call between a ride's rider and captain (O-09). */
class RideCall extends Model
{
    public const LIVE = ['ringing', 'active'];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (RideCall $c) => $c->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['answered_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }
}
