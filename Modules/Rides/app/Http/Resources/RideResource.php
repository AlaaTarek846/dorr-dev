<?php

namespace Modules\Rides\Http\Resources;

use App\Models\Rating;
use Modules\Rides\Enums\RatingTarget;
use Modules\Rides\Services\Lifecycle\RideRatingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use Modules\Driver\Models\DriverPresence;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Models\DriverRideProfile;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RidePriceLine;
use Modules\Rides\Models\RideStop;
use Modules\Rides\Services\Geo\PickupPoints;
use Modules\Rides\Services\Geo\RideProgress;
use Modules\Rides\Services\Lifecycle\RideChangeService;
use Modules\Rides\Services\Payment\RidePaymentChangeService;
use Modules\Rides\Services\Payment\RideTipService;
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
            // DORR Circle (R-INN-07): booked for favourite captains — still only them, how many, and whether the rider was asked.
            'circle' => $this->circle_driver_ids === null ? null : [
                'active' => $this->search_scope === 'circle',
                'count' => count((array) $this->circle_driver_ids),
                'asked' => $this->circle_asked_at !== null,
            ],
            // R-13: the rider sees whom they booked for, as they typed it.
            'beneficiary' => empty($this->beneficiary['name']) ? null : ['name' => $this->beneficiary['name'], 'phone' => $this->beneficiary['phone'] ?? null],
            'estimate' => $this->snapshot('estimate'),
            'agreed_price_minor' => $this->agreed_price_minor,
            'bidding' => $this->booking_mode?->value !== 'bid' ? null : [
                'rider_price_minor' => $this->rider_offer_minor,
                'floor_minor' => $this->bid_floor_minor,
                'suggested_minor' => $this->bid_suggested_minor,
            ],
            'final_total_minor' => $this->final_total_minor,
            'lines' => self::lines($this->resource, 'rider'),
            // R-61: the ways of paying the rider may switch to now (none once the trip started).
            'payment_change' => RidePaymentChangeService::options($this->resource),
            // D-129: how far the captain still is (to the pickup, then to the next stop / the destination).
            'progress' => RideProgress::of($this->resource),
            // A card ride waiting for payment: the app opens its payment screen on this checkout.
            'checkout_id' => $this->status === RideStatus::AwaitingPayment ? $this->snapshot('checkout_uuid') : null,
            'pin' => $this->status->hasDriver() && $this->status !== RideStatus::InProgress ? $this->pin : null,
            'driver' => ! $withDriver ? null : [
                'name' => $this->driver?->name,
                'avatar_url' => $this->driver?->getFirstMediaUrl('avatar') ?: null,
                'rating' => $profile !== null && $profile->rating_count > 0 ? (float) $profile->rating_avg : null,
                'trips_count' => (int) ($profile?->trips_count ?? 0),
                // R-81: the captain's tier badge (new / bronze / silver / gold / diamond).
                'tier' => $tier = $profile?->tier ?? 'new',
                'tier_label' => __('rides.tiers.'.$tier),
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
            // O-37: a cash ride paid partly from the wallet — that part (held, then paid), and the cash left.
            'wallet_part_minor' => $this->payment_method === \Modules\Rides\Enums\RidePaymentMethod::Cash
                ? ($this->status === RideStatus::Completed ? (int) $this->snapshot('wallet_paid_minor', 0) : app(\Modules\Rides\Services\Payment\RidePaymentService::class)->held($this->resource))
                : 0,
            'cash_to_collect_minor' => $this->status === RideStatus::Completed && $this->payment_method === \Modules\Rides\Enums\RidePaymentMethod::Cash
                ? max(0, (int) $this->final_total_minor - (int) $this->snapshot('wallet_paid_minor', 0))
                : null,
            // O-10: unread chat messages (the badge); O-09: the number to call from outside the app while the ride is on.
            'unread_messages' => $this->driver_id !== null ? \Modules\Rides\Services\Chat\RideChatService::unread($this->resource, 'user') : 0,
            'call_phone' => $this->driver_id !== null ? \Modules\Rides\Services\Chat\RideChatService::callPhone($this->resource, 'user') : null,
            // O-30..O-33: a With Me ride sharing the car — what sharing took off, and with how many.
            // O-17: booked by the hour — the hours and what each minute past them costs (counted from the start).
            'hourly' => ($h = $this->snapshot('hourly')) === null ? null : [
                'hours' => (int) $h['hours'], 'hour_price_minor' => (int) $h['hour_price_minor'],
                'overtime_per_min_minor' => (int) ($h['overtime_per_min_minor'] ?? 0),
                'ends_at' => $this->started_at?->copy()->addHours((int) $h['hours'])->toIso8601String(),
            ],
            // O-09: the in-app call ringing or going on now (null when none) — the app shows it on any reload.
            'call' => \Modules\Rides\Services\Chat\RideCallService::present($this->resource),
            'pool' => $this->pool_id === null ? null : [
                'discount_minor' => (int) $this->pool_discount_minor,
                'riders' => \Modules\Rides\Models\Ride::query()->where('pool_id', $this->pool_id)
                    ->whereIn('status', ['accepted', 'arrived', 'in_progress'])->count(),
                'share_with' => $this->share_with,
            ],
            'rated' => $this->status === RideStatus::Completed
                ? Rating::query()->where('unique_key', Rating::uniqueKeyFor($request->user(), $this->resource))->exists()
                : null,
            // O-41: what the rider scores the captain on, each with its stars on the rating sheet.
            'rating_criteria' => $this->status === RideStatus::Completed ? RideRatingService::criteria($this->resource, RatingTarget::Driver) : [],
            // R-146: the DORR points this ride earned (null when the program is off / nothing yet).
            'loyalty_points' => $this->status === RideStatus::Completed
                ? (\Modules\Rides\Models\LoyaltyEntry::query()->where('reference_type', 'ride')->where('reference_id', $this->id)->value('points')) : null,
            // R-144: the tip offered after the ride (presets, highest, until when) — or the one given.
            'tip' => $this->status === RideStatus::Completed ? app(RideTipService::class)->offer($this->resource) : null,
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
            // D-60, D-61: how many ride and the pickup note — on the request card and the ride.
            'passengers' => $ride->passengers,
            'pickup_note' => $ride->pickup_note,
            // R-233..R-237, R-NEW-06, D-59, D-390: Wait & Save / priority, child seats, a pet, a service animal, bags.
            'options' => \Modules\Rides\Services\Pricing\TripAddons::present($ride->snapshot('options')),
            // R-13: booked for someone else — who to pick up, by first name only (never their number).
            'beneficiary' => empty($ride->beneficiary['name']) ? null : ['name' => Str::before($ride->beneficiary['name'], ' ')],
            // R-16..R-18, D-111: the approved points the trip starts / ends at — name, kind, how to find it.
            'pickup_point' => PickupPoints::present($ride->pickup_point),
            'dropoff_point' => PickupPoints::present($ride->dropoff_point),
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
                // D-122: the countdown turns to a warning this long before the free waiting ends.
                'warn_before_s' => (int) $ride->policy('waiting.warn_before_s', config('rides.policy_defaults')['waiting.warn_before_s'] ?? 60),
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
