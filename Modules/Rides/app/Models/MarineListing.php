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

/** A sea trip or a yacht hire a captain published (O-25). */
class MarineListing extends Model
{
    public const AMENITIES = ['life_jackets', 'snorkeling', 'fishing', 'food', 'drinks', 'music', 'cabin', 'toilet', 'crew', 'swimming', 'sunbeds', 'photos'];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (MarineListing $l) => $l->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'marina_lat' => 'float', 'marina_lng' => 'float', 'amenities' => 'array', 'capacity' => 'integer',
            'depart_at' => 'datetime', 'duration_min' => 'integer', 'seat_price_minor' => 'integer', 'private_price_minor' => 'integer',
            'hour_price_minor' => 'integer', 'min_hours' => 'integer',
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
        return $this->hasMany(MarineBooking::class);
    }

    /** Seats still free on a sea trip (a private booking takes the whole boat). */
    public function seatsLeft(): int
    {
        $live = $this->bookings()->whereIn('status', ['confirmed', 'boarded']);
        if ((clone $live)->where('mode', 'private')->exists()) {
            return 0;
        }

        return max(0, $this->capacity - (int) $live->sum('guests'));
    }
}
