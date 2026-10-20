<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\User\Models\User;

/** A rider's ticket on a bus trip (O-22, O-23): its seats per leg, paid from the wallet. */
class BusBooking extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (BusBooking $booking) => $booking->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['seats' => 'array', 'wallet_transaction_ids' => 'array', 'amount_minor' => 'integer', 'canceled_at' => 'datetime'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(BusTrip::class, 'bus_trip_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function seatRows(): HasMany
    {
        return $this->hasMany(BusBookingSeat::class);
    }
}
