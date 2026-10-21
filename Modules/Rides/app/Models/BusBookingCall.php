<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** An in-app voice call between a bus passenger and the captain (O-68). */
class BusBookingCall extends Model
{
    public const LIVE = ['ringing', 'active'];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (BusBookingCall $c) => $c->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['answered_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(BusBooking::class, 'bus_booking_id');
    }
}
