<?php

namespace Modules\Rides\Models;

use App\Models\Country;
use App\Models\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\Vehicle;

/** A bus trip a captain published (O-20, O-21): from → to, leaving and coming back, three seat prices. */
class BusTrip extends Model
{
    public const STATUSES = ['scheduled', 'departed', 'returning', 'completed', 'canceled'];

    public const LEGS = ['outbound', 'return'];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (BusTrip $trip) => $trip->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'origin_lat' => 'float', 'origin_lng' => 'float', 'destination_lat' => 'float', 'destination_lng' => 'float',
            'depart_at' => 'datetime', 'return_at' => 'datetime', 'departed_at' => 'datetime', 'completed_at' => 'datetime', 'canceled_at' => 'datetime',
            'price_outbound_minor' => 'integer', 'price_return_minor' => 'integer', 'price_round_minor' => 'integer',
            'seats_total' => 'integer', 'seats_per_row' => 'integer',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(BusBooking::class);
    }

    public function seats(): HasMany
    {
        return $this->hasMany(BusBookingSeat::class);
    }

    public function hasReturn(): bool
    {
        return $this->return_at !== null && $this->price_return_minor !== null;
    }

    /** What one seat costs for this kind of ticket; null when the trip doesn't sell it. */
    public function seatPrice(string $tripType): ?int
    {
        return match ($tripType) {
            'outbound' => $this->price_outbound_minor,
            'return' => $this->hasReturn() ? $this->price_return_minor : null,
            'round' => $this->hasReturn() ? ($this->price_round_minor ?? $this->price_outbound_minor + $this->price_return_minor) : null,
            default => null,
        };
    }

    /** @return list<int> the seats already taken on a leg */
    public function takenSeats(string $leg): array
    {
        return $this->seats()->where('leg', $leg)->pluck('seat_no')->map(fn ($n) => (int) $n)->sort()->values()->all();
    }
}
