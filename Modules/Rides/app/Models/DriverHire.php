<?php

namespace Modules\Rides\Models;

use App\Models\Country;
use App\Models\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Driver\Models\Driver;
use Modules\User\Models\User;

/** A rider hiring a captain to drive their own car (O-26c). */
class DriverHire extends Model
{
    public const LIVE = ['accepted', 'arrived', 'started'];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (DriverHire $h) => $h->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'pickup_lat' => 'float', 'pickup_lng' => 'float', 'starts_at' => 'datetime', 'quantity' => 'integer',
            'amount_minor' => 'integer', 'extra_minor' => 'integer', 'extra_due_minor' => 'integer', 'wallet_transaction_ids' => 'array',
            'accepted_at' => 'datetime', 'arrived_at' => 'datetime', 'started_at' => 'datetime', 'ended_at' => 'datetime', 'canceled_at' => 'datetime', 'payment_expires_at' => 'datetime', 'discount_minor' => 'integer',
        ];
    }

    /** How long was booked, in seconds. */
    public function bookedSeconds(): int
    {
        return $this->quantity * ($this->unit === 'day' ? 86400 : 3600);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
