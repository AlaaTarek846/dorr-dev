<?php

namespace Modules\Rides\Http\Resources;

use App\Models\Rating;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Driver\Models\DriverPresence;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Models\DriverRideProfile;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RidePriceLine;
use Modules\Rides\Models\RideStop;
use Modules\Rides\Services\Lifecycle\RideChangeService;
use Modules\Rides\Services\Safety\SafetyCenterService;
use Modules\Rides\Services\Safety\SafetyCheckService;

/**
 * A ride as the rider sees it: the driver and the car once one accepted (with where the car is now),
 * the PIN to read out, and the price lines meant for the rider — the confirmed ones, then the final
 * ones once the ride ended. The commission and the driver's net are never shown here.
 *
 * @mixin Ride
 */
class RideResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $withDriver = $this->driver_id !== null;
        $profile = $withDriver ? DriverRideProfile::query()->find($this->driver_id) : null;
        $presence = $withDriver && $this->status->hasDriver() ? DriverPresence::query()->find($this->driver_id) : null;

        return [
            'uuid' => $this->uuid,
            'number' => $this->number,
            'status' => $this->status->value,
            'version' => $this->version,
            ...self::common($this->resource),
            'estimate' => $this->snapshot('estimate'),
            'agreed_price_minor' => $this->agreed_price_minor,
            'bidding' => $this->booking_mode?->value !== 'bid' ? null : [
                'rider_price_minor' => $this->rider_offer_minor,
                'floor_minor' => $this->bid_floor_minor,
                'suggested_minor' => $this->bid_suggested_minor,
            ],
            'final_total_minor' => $this->final_total_minor,
            'lines' => self::lines($this->resource, 'rider'),
            // A card ride waiting for payment: the app opens its payment screen on this checkout.
            'checkout_id' => $this->status === RideStatus::AwaitingPayment ? $this->snapshot('checkout_uuid') : null,
            'pin' => $this->status->hasDriver() && $this->status !== RideStatus::InProgress ? $this->pin : null,
            'driver' => ! $withDriver ? null : [
                'name' => $this->driver?->name,
                'avatar_url' => $this->driver?->getFirstMediaUrl('avatar') ?: null,
                'rating' => $profile !== null && $profile->rating_count > 0 ? (float) $profile->rating_avg : null,
                'trips_count' => (int) ($profile?->trips_count ?? 0),
                'vehicle' => $this->vehicle_snapshot,
                'location' => $presence?->lat === null ? null : [
                    'lat' => $presence->lat, 'lng' => $presence->lng, 'heading' => $presence->heading,
                    'at' => $presence->last_seen_at?->toIso8601String(),
                ],
            ],
            // Booked ahead: the captain who reserved it, before the ride starts (R-80 for a booking).
            'reserved_driver' => $this->status !== RideStatus::Scheduled || $this->reserved_driver_id === null ? null : self::reservedDriver($this->resource),
            // "Are you OK?" waiting for this rider (R-222, R-223), and whether the captain is a favourite (R-226).
            'safety_check' => ($check = SafetyCheckService::current($this->resource, $request->user())) === null ? null : SafetyCheckService::present($check),
            'favorite_driver' => $withDriver ? SafetyCenterService::isFavorite($this->user_id, $this->driver_id) : null,
            // A new destination / stop asked during the ride, and its answer (R-91..95).
            'change_request' => ($change = RideChangeService::current($this->resource)) === null ? null : RideChangeService::present($change, 'rider'),
            'rated' => $this->status === RideStatus::Completed
                ? Rating::query()->where('unique_key', Rating::uniqueKeyFor($request->user(), $this->resource))->exists()
                : null,
        ];
    }

    /**
     * What both apps show the same way.
     *
     * @return array<string, mixed>
     */
    public static function common(Ride $ride): array
    {
        $ride->loadMissing(['category.translations', 'currency', 'stops']);

        return [
            'category' => ['slug' => $ride->category?->slug, 'name' => $ride->category?->translated('name')],
            'pricing_mode' => $ride->pricing_mode->value,
            'open_destination' => $ride->open_destination,
            'payment_method' => $ride->payment_method->value,
            'payment_status' => $ride->payment_status->value,
            'currency' => ['code' => $ride->currency?->code, 'symbol' => $ride->currency?->symbol, 'decimal_places' => (int) ($ride->currency?->decimal_places ?? 2)],
            'origin' => ['lat' => $ride->origin_lat, 'lng' => $ride->origin_lng, 'address' => $ride->origin_address],
            'destination' => $ride->destination_lat === null ? null : ['lat' => $ride->destination_lat, 'lng' => $ride->destination_lng, 'address' => $ride->destination_address],
            'stops' => $ride->stops->map(fn (RideStop $s) => [
                'seq' => $s->seq, 'lat' => (float) $s->lat, 'lng' => (float) $s->lng, 'address' => $s->address,
                'status' => $s->status, 'arrived_at' => $s->arrived_at?->toIso8601String(), 'departed_at' => $s->departed_at?->toIso8601String(),
            ])->values(),
            'route' => ['distance_m' => $ride->planned_distance_m, 'duration_s' => $ride->planned_duration_s, 'polyline' => $ride->planned_polyline],
            'metered' => $ride->completed_at === null ? null : ['distance_m' => $ride->metered_distance_m, 'duration_s' => $ride->metered_duration_s],
            'scheduled' => $ride->scheduled_at === null ? null : [
                'at' => $ride->scheduled_at->toIso8601String(),
                'free_cancel_until' => $ride->freeCancelUntil()?->toIso8601String(),
                'starts_search_at' => $ride->scheduled_at->copy()->subSeconds((int) $ride->policy('scheduled.lead_time_s', 1800))->toIso8601String(),
                'reserved' => $ride->reserved_driver_id !== null,
            ],
            'times' => [
                'requested_at' => $ride->requested_at?->toIso8601String(),
                'accepted_at' => $ride->accepted_at?->toIso8601String(),
                'arrived_at' => $ride->arrived_at?->toIso8601String(),
                // The free waiting counts from here: a scheduled ride's time when the captain came early.
                'waiting_from' => $ride->waitingFrom()?->toIso8601String(),
                'started_at' => $ride->started_at?->toIso8601String(),
                'completed_at' => $ride->completed_at?->toIso8601String(),
                'canceled_at' => $ride->canceled_at?->toIso8601String(),
            ],
            'waiting' => [
                'free_s' => (int) $ride->policy('waiting.free_s', 180),
                'per_min_minor' => (int) $ride->policy('price.waiting_per_min_minor', 0),
                'no_show_after_s' => (int) $ride->policy('no_show.wait_s', 300),
            ],
            'cancel' => $ride->cancel_actor === null ? null : ['actor' => $ride->cancel_actor->value, 'stage' => $ride->cancel_stage?->value],
            // The SOS button dials this (R-110, D-141), fixed with the ride.
            'safety' => ['emergency_number' => (string) $ride->policy('safety.emergency_number', config('rides.policy_defaults')['safety.emergency_number'] ?? '911')],
        ];
    }

    /** @return array<string, mixed> */
    private static function reservedDriver(Ride $ride): array
    {
        $driver = $ride->reservedDriver;
        $profile = DriverRideProfile::query()->find($ride->reserved_driver_id);

        return [
            'name' => $driver?->name,
            'avatar_url' => $driver?->getFirstMediaUrl('avatar') ?: null,
            'rating' => $profile !== null && $profile->rating_count > 0 ? (float) $profile->rating_avg : null,
            'trips_count' => (int) ($profile?->trips_count ?? 0),
            'reserved_at' => $ride->reserved_at?->toIso8601String(),
        ];
    }

    /**
     * The final lines once the ride ended, else the confirmed ones — only those `$side` may see.
     *
     * @return list<array{code: string, amount_minor: int, meta: mixed}>
     */
    public static function lines(Ride $ride, string $side): array
    {
        // The final lines; else the latest revision after a change during the ride (rev.N); else the confirmed ones.
        $phases = RidePriceLine::query()->where('ride_id', $ride->id)->distinct()->pluck('phase');
        $phase = $phases->contains('final') ? 'final'
            : ($phases->filter(fn (string $p) => str_starts_with($p, 'rev.'))->sortBy(fn (string $p) => (int) substr($p, 4))->last() ?? 'confirmed');

        return RidePriceLine::query()->where('ride_id', $ride->id)->where('phase', $phase)
            ->whereIn('visible_to', ['both', $side])->orderBy('sort_order')->get()
            ->map(fn (RidePriceLine $l) => ['code' => $l->code, 'label' => __('rides.lines.'.$l->code), 'amount_minor' => (int) $l->amount_minor, 'meta' => $l->meta])
            ->all();
    }
}
