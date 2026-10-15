<?php

namespace Modules\Rides\Models;

use App\Models\Country;
use App\Models\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\Vehicle;
use Modules\Rides\Enums\BookingMode;
use Modules\Rides\Enums\CancelActor;
use Modules\Rides\Enums\CancelStage;
use Modules\Rides\Enums\DistanceSource;
use Modules\Rides\Enums\PricingMode;
use Modules\Rides\Enums\RidePaymentMethod;
use Modules\Rides\Enums\RidePaymentStatus;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Enums\WaslTripStatus;
use Modules\User\Models\User;

/**
 * One ride (docs/rides-structure.md §3.2). Never deleted; its status only changes through
 * RideStateMachine, which writes a ride_events row in the same transaction. `confirmed_snapshot`
 * is fixed at confirmation — later policy changes never touch it (D-371).
 */
class Ride extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'beneficiary' => 'array',
            'pickup_point' => 'array',
            'dropoff_point' => 'array',
            'confirmed_snapshot' => 'array',
            'vehicle_snapshot' => 'array',
            'status' => RideStatus::class,
            'booking_mode' => BookingMode::class,
            'pricing_mode' => PricingMode::class,
            'payment_method' => RidePaymentMethod::class,
            'payment_status' => RidePaymentStatus::class,
            'cancel_actor' => CancelActor::class,
            'cancel_stage' => CancelStage::class,
            'distance_source' => DistanceSource::class,
            'wasl_status' => WaslTripStatus::class,
            'open_destination' => 'boolean',
            'city_id' => 'integer',
            'origin_lat' => 'float',
            'origin_lng' => 'float',
            'destination_lat' => 'float',
            'destination_lng' => 'float',
            'rider_offer_minor' => 'integer',
            'bid_floor_minor' => 'integer',
            'bid_suggested_minor' => 'integer',
            'agreed_price_minor' => 'integer',
            'final_total_minor' => 'integer',
            'commission_minor' => 'integer',
            'tax_minor' => 'integer',
            'driver_net_minor' => 'integer',
            'previous_balance_minor' => 'integer',
            'tip_minor' => 'integer',
            'passengers' => 'integer',
            'wait_warned_at' => 'datetime',
            'tipped_at' => 'datetime',
            'next_dispatch_at' => 'datetime',
            'rider_asked_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'reminded_at' => 'datetime',
            'reserved_at' => 'datetime',
            'requested_at' => 'datetime',
            'search_started_at' => 'datetime',
            'accepted_at' => 'datetime',
            'arrived_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'canceled_at' => 'datetime',
            'expired_at' => 'datetime',
            'wasl_last_attempt_at' => 'datetime',
            'wasl_sent_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /** A value fixed when the ride was confirmed (policy key, tax rule, prices…). */
    public function snapshot(string $key, mixed $default = null): mixed
    {
        return data_get($this->confirmed_snapshot, $key, $default);
    }

    /** A policy value fixed at confirmation (keys are flat: "settlement.pending_days"). */
    public function policy(string $key, mixed $default = null): mixed
    {
        return $this->confirmed_snapshot['policy'][$key] ?? $default;
    }

    /**
     * When the free waiting at the pickup begins: the arrival — but a scheduled ride's waiting never
     * starts before its time, however early the captain came (R-108, D-119, D-168).
     */
    public function waitingFrom(): ?Carbon
    {
        if ($this->arrived_at === null) {
            return null;
        }

        return $this->scheduled_at !== null && $this->scheduled_at->greaterThan($this->arrived_at) ? $this->scheduled_at->copy() : $this->arrived_at->copy();
    }

    /** Seconds waited at the pickup so far (0 before the waiting begins). */
    public function waitedSeconds(): int
    {
        $from = $this->waitingFrom();

        return $from === null || $from->isFuture() ? 0 : (int) $from->diffInSeconds(now());
    }

    /** When the search really began: a scheduled ride is booked long before it looks for a captain. */
    public function searchingSince(): ?Carbon
    {
        return $this->search_started_at ?? $this->requested_at;
    }

    /** The rider may still cancel a scheduled ride for free until then (R-106). */
    public function freeCancelUntil(): ?Carbon
    {
        return $this->scheduled_at?->copy()->subSeconds((int) $this->policy('scheduled.free_cancel_before_s', 3600));
    }

    public function reservedDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'reserved_driver_id');
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(RideCategory::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(RideQuote::class);
    }

    public function stops(): HasMany
    {
        return $this->hasMany(RideStop::class)->orderBy('seq');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RideEvent::class)->orderBy('id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(RideOffer::class);
    }

    public function bids(): HasMany
    {
        return $this->hasMany(RideBid::class);
    }

    public function holds(): HasMany
    {
        return $this->hasMany(RideHold::class);
    }

    public function priceLines(): HasMany
    {
        return $this->hasMany(RidePriceLine::class)->orderBy('sort_order');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(RideAdjustment::class);
    }

    public function settlementItems(): HasMany
    {
        return $this->hasMany(RideSettlementItem::class);
    }

    public function track(): HasOne
    {
        return $this->hasOne(RideTrack::class);
    }

    public function cancelReason(): BelongsTo
    {
        return $this->belongsTo(RideCancelReason::class);
    }
}
