<?php

namespace Modules\Rides\Services\Lifecycle;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Driver\Models\Driver;
use Modules\Rides\Enums\BookingMode;
use Modules\Rides\Enums\PricingMode;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideAdjustment;
use Modules\Rides\Models\RideChangeRequest;
use Modules\Rides\Models\RidePriceLine;
use Modules\Rides\Models\RideStop;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\GeoProvider;
use Modules\Rides\Services\Notifications\RideNotifier;
use Modules\Rides\Services\Payment\RidePaymentService;
use Modules\Rides\Services\Pricing\FareCalculator;
use Modules\Rides\Services\Pricing\PriceExtras;
use Modules\Rides\Support\Geo;
use Modules\User\Models\User;

/**
 * Changing the trip while it runs (R-91..95, D-131..137) — a new destination or one more stop:
 *  1. **Preview:** the new route (origin → stops → destination), what it adds to the distance, the
 *     time and the price — nothing changes yet (R-92).
 *  2. **Send** (the rider's consent, R-94): the captain gets it with what they'd earn more and
 *     `change.driver_response_s` to answer (D-131..133). A wallet ride must be able to cover the
 *     difference.
 *  3. **Accepted:** the route, the destination (or the new stop) and the fixed fare change together;
 *     the old numbers stay in this request, `ride_events` and the new price lines (`rev.N`, R-59).
 *     Declined or no answer: nothing changes and the ride goes on to the old destination.
 * Metered rides keep paying by distance — the delta is only an estimate there. Never on an open-
 * destination ride, and one request at a time.
 */
class RideChangeService
{
    public function __construct(
        private readonly GeoProvider $geo,
        private readonly FareCalculator $calculator,
        private readonly RideFeatures $features,
        private readonly RideStateMachine $machine,
        private readonly RideNotifier $notifier,
        private readonly RidePaymentService $payments,
    ) {}

    /**
     * @param  array{lat: float, lng: float, address?: ?string}  $point
     */
    public function preview(User $rider, Ride $ride, string $type, array $point): RideChangeRequest
    {
        $this->assertCanChange($ride, $type);
        $policy = (array) $ride->snapshot('policy', []);
        if ($type === 'add_stop' && $ride->stops()->count() >= (int) ($policy['stops.max'] ?? 3)) {
            throw new RidesException('too_many_stops', 422, ['max' => (int) ($policy['stops.max'] ?? 3)]);
        }

        [$destination, $stops] = $this->newTrip($ride, $type, $point);
        $route = $this->geo->route($ride->origin_lat, $ride->origin_lng, $destination['lat'], $destination['lng'], array_map(fn (array $s) => [$s['lat'], $s['lng']], $stops));
        $distance = $route?->distanceM ?? (int) round(Geo::metres($ride->origin_lat, $ride->origin_lng, $destination['lat'], $destination['lng']) * 1.3);
        $duration = $route?->durationS ?? (int) round($distance / 8.3);

        $price = FareCalculator::priceOf($policy);
        $oldFare = (int) $ride->snapshot('breakdown.fare', 0);
        $routeFare = fn (int $m, int $s) => $this->calculator->compute($price, $m, $s, null)->fare;
        // Bidding: the agreed price moves by what the extra route costs at the city's rates.
        $newFare = match (true) {
            $ride->booking_mode === BookingMode::Bid => max(0, $oldFare + $routeFare($distance, $duration) - $routeFare((int) $ride->planned_distance_m, (int) $ride->planned_duration_s)),
            default => $routeFare($distance, $duration),
        };
        $b = $this->calculator->compute($price, $distance, $duration, $ride->snapshot('tax'), 0, 0, (int) $ride->previous_balance_minor, $newFare);
        if (($extras = (array) $ride->snapshot('extras', [])) !== []) {
            $b = PriceExtras::apply($b, $extras);
        }

        return RideChangeRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'ride_id' => $ride->id,
            'type' => $type,
            'requested_by' => 'user',
            'payload' => ['lat' => $point['lat'], 'lng' => $point['lng'], 'address' => $point['address'] ?? null],
            'route' => ['distance_m' => $distance, 'duration_s' => $duration, 'polyline' => $route?->polyline, 'estimated' => $route === null || $route->estimated],
            'distance_delta_m' => $distance - (int) $ride->planned_distance_m,
            'duration_delta_s' => $duration - (int) $ride->planned_duration_s,
            'price_delta_minor' => $b->total - (int) $ride->snapshot('breakdown.total', $ride->agreed_price_minor ?? $b->total),
            'new_total_minor' => $b->total,
            'driver_net_delta_minor' => $b->driverNet - (int) $ride->snapshot('breakdown.driver_net', $b->driverNet),
            'breakdown' => ['fare' => $b->fare, 'commission' => $b->commission, 'tax' => $b->tax, 'customer_fee' => $b->customerFee, 'discount' => $b->discount, 'payment_method_fee' => $b->paymentFee, 'driver_net' => $b->driverNet, 'total' => $b->total, 'lines' => $b->lines],
            'status' => 'preview',
            'expires_at' => now()->addMinutes(3),
        ]);
    }

    /** The rider agrees to the new price: it goes to the captain. */
    public function send(User $rider, Ride $ride, string $uuid): RideChangeRequest
    {
        $request = DB::transaction(function () use ($ride, $uuid) {
            $request = $this->find($ride, $uuid, true);
            if ($request->status === 'pending') {
                return $request;
            }
            if ($request->status !== 'preview' || ! $request->isOpen()) {
                throw new RidesException('change_expired', 409);
            }
            $this->assertCanChange($ride->refresh(), $request->type);
            if (RideChangeRequest::query()->where('ride_id', $ride->id)->where('status', 'pending')->where('expires_at', '>', now())->exists()) {
                throw new RidesException('change_pending', 409);
            }
            if ($ride->payment_method->holdsInWallet() && $request->price_delta_minor > 0
                && $this->payments->available($this->payments->riderWallet($ride)) < $request->price_delta_minor) {
                throw new RidesException('insufficient_balance', 422, [], ['shortfall_minor' => $request->price_delta_minor]);
            }

            $request->update([
                'status' => 'pending',
                'rider_consent_at' => now(),
                'expires_at' => now()->addSeconds((int) $ride->policy('change.driver_response_s', 30)),
            ]);
            $this->machine->record($ride, 'change_requested', 'user', $ride->user_id, [], ['change' => $request->uuid, 'type' => $request->type, 'price_delta_minor' => $request->price_delta_minor]);

            return $request;
        });

        $this->notifier->changed($ride, 'change_requested');
        if ($ride->driver !== null) {
            $this->notifier->driver($ride->driver, $ride, 'change_request', [
                'place' => (string) ($request->payload['address'] ?? ''),
                'amount' => $this->notifier->money(max(0, $request->driver_net_delta_minor), $ride),
            ]);
        }

        return $request;
    }

    public function cancel(User $rider, Ride $ride, string $uuid): RideChangeRequest
    {
        $request = $this->find($ride, $uuid, true);
        if (in_array($request->status, ['preview', 'pending'], true)) {
            $request->update(['status' => 'canceled']);
            $this->notifier->changed($ride, 'change_canceled');
        }

        return $request;
    }

    /** The captain's answer (D-133). Accepted = the trip and its price change now. */
    public function answer(Driver $driver, Ride $ride, string $uuid, bool $accept): RideChangeRequest
    {
        if ($ride->driver_id !== $driver->id) {
            throw new RidesException('not_your_ride', 403);
        }

        $request = DB::transaction(function () use ($driver, $ride, $uuid, $accept) {
            $ride = Ride::query()->whereKey($ride->id)->lockForUpdate()->firstOrFail();
            $request = $this->find($ride, $uuid, true);
            if (in_array($request->status, ['applied', 'rejected'], true)) {
                return $request;
            }
            if ($request->status !== 'pending' || $request->expires_at->isPast()) {
                throw new RidesException('change_expired', 409);
            }
            if (! $accept) {
                $request->update(['status' => 'rejected', 'driver_response' => 'rejected', 'driver_responded_at' => now()]);
                $this->machine->record($ride, 'change_rejected', 'driver', $driver->id, [], ['change' => $request->uuid]);

                return $request;
            }
            if (! in_array($ride->status, [RideStatus::Accepted, RideStatus::Arrived, RideStatus::InProgress], true)) {
                throw new RidesException('invalid_state', 409, ['status' => $ride->status->value], ['status' => $ride->status->value]);
            }

            $this->apply($ride, $request);
            $request->update(['status' => 'applied', 'driver_response' => 'accepted', 'driver_responded_at' => now()]);
            $this->machine->record($ride, 'change_applied', 'driver', $driver->id, [], [
                'change' => $request->uuid, 'type' => $request->type, 'price_delta_minor' => $request->price_delta_minor, 'new_total_minor' => $request->new_total_minor,
            ]);

            return $request;
        });

        $this->notifier->changed($ride, $request->status === 'applied' ? 'change_applied' : 'change_rejected');
        $this->notifier->rider($ride, $request->status === 'applied' ? 'change_applied' : 'change_rejected', [
            'place' => (string) ($request->payload['address'] ?? ''),
            'amount' => $this->notifier->money((int) $request->new_total_minor, $ride),
        ]);

        return $request;
    }

    /** Requests the captain didn't answer in time: nothing changes. */
    public function expireDue(): void
    {
        RideChangeRequest::query()->where('status', 'pending')->where('expires_at', '<=', now())->with('ride')->get()
            ->each(function (RideChangeRequest $request) {
                if (RideChangeRequest::query()->whereKey($request->id)->where('status', 'pending')->update(['status' => 'expired', 'driver_response' => 'expired']) === 0) {
                    return;
                }
                $this->notifier->changed($request->ride, 'change_expired');
                $this->notifier->rider($request->ride, 'change_expired', ['place' => (string) ($request->payload['address'] ?? '')]);
            });
        RideChangeRequest::query()->where('status', 'preview')->where('expires_at', '<=', now())->update(['status' => 'expired']);
    }

    /** The latest request the apps should show (pending, or one answered in the last 2 minutes). */
    public static function current(Ride $ride): ?RideChangeRequest
    {
        return RideChangeRequest::query()->where('ride_id', $ride->id)
            ->where(fn ($q) => $q->where('status', 'pending')->where('expires_at', '>', now())
                ->orWhere(fn ($q) => $q->whereIn('status', ['applied', 'rejected', 'expired'])->whereNotNull('rider_consent_at')->where('updated_at', '>=', now()->subMinutes(2))))
            ->latest('id')->first();
    }

    /** @return array<string, mixed> what both apps show of a request */
    public static function present(RideChangeRequest $r, string $side): array
    {
        return [
            'uuid' => $r->uuid,
            'type' => $r->type,
            'status' => $r->status,
            'place' => $r->payload,
            'distance_delta_m' => $r->distance_delta_m,
            'duration_delta_s' => $r->duration_delta_s,
            'route' => ['distance_m' => $r->route['distance_m'] ?? null, 'duration_s' => $r->route['duration_s'] ?? null],
            ...($side === 'rider'
                ? ['price_delta_minor' => $r->price_delta_minor, 'new_total_minor' => $r->new_total_minor]
                : ['earning_delta_minor' => $r->driver_net_delta_minor, 'new_earning_minor' => $r->breakdown['driver_net'] ?? null]),
            'expires_at' => $r->expires_at->toIso8601String(),
            'seconds_left' => max(0, (int) now()->diffInSeconds($r->expires_at, false)),
        ];
    }

    private function apply(Ride $ride, RideChangeRequest $request): void
    {
        $route = (array) $request->route;
        $point = (array) $request->payload;
        $changes = [
            'planned_distance_m' => $route['distance_m'] ?? $ride->planned_distance_m,
            'planned_duration_s' => $route['duration_s'] ?? $ride->planned_duration_s,
            // O-56: the new trip's line — never the old one; without a drawn route the apps join the points.
            'planned_polyline' => $route['polyline'] ?? null,
        ];
        if ($request->type === 'destination') {
            $changes += ['destination_lat' => $point['lat'], 'destination_lng' => $point['lng'], 'destination_address' => $point['address'] ?? null];
        } else {
            RideStop::query()->create([
                'ride_id' => $ride->id, 'seq' => (int) RideStop::query()->where('ride_id', $ride->id)->max('seq') + 1,
                'lat' => $point['lat'], 'lng' => $point['lng'], 'address' => $point['address'] ?? null, 'added_by_change_request_id' => $request->id,
            ]);
        }

        // The fixed fare moves with the route (a metered ride keeps paying by distance).
        $breakdown = (array) $request->breakdown;
        $snapshot = (array) $ride->confirmed_snapshot;
        if ($ride->pricing_mode === PricingMode::Upfront && isset($breakdown['fare'])) {
            $snapshot['breakdown'] = array_intersect_key($breakdown, array_flip(['fare', 'commission', 'tax', 'customer_fee', 'discount', 'payment_method_fee', 'driver_net', 'total']));
            $changes['agreed_price_minor'] = (int) $breakdown['total'];

            $revision = 'rev.'.(RideChangeRequest::query()->where('ride_id', $ride->id)->where('status', 'applied')->count() + 1);
            foreach ((array) ($breakdown['lines'] ?? []) as $i => $line) {
                RidePriceLine::query()->create([
                    'ride_id' => $ride->id, 'phase' => $revision, 'code' => $line['code'], 'amount_minor' => $line['amount_minor'],
                    'visible_to' => $line['visible_to'], 'meta' => $line['meta'] ?? null, 'sort_order' => $i, 'created_at' => now(),
                ]);
            }
            if ($request->price_delta_minor !== 0) {
                RideAdjustment::query()->create([
                    'ride_id' => $ride->id, 'type' => $request->type === 'destination' ? 'destination_change' : 'stop_added',
                    'amount_minor' => $request->price_delta_minor, 'affects' => 'rider',
                    'reason_key' => 'rides.adjustments.'.($request->type === 'destination' ? 'destination_change' : 'stop_added'),
                    'actor_type' => 'driver', 'created_at' => now(),
                ]);
            }
        }
        $snapshot['changes'] = [...(array) ($snapshot['changes'] ?? []), $request->uuid];
        $changes['confirmed_snapshot'] = $snapshot;
        $ride->forceFill($changes)->save();

        // A wallet ride holds the difference now; short, it is taken at the end (as a debt if need be).
        if ($ride->payment_method->holdsInWallet() && $request->price_delta_minor > 0) {
            try {
                $this->payments->hold($ride, $request->price_delta_minor, 'change_'.$request->id);
            } catch (RidesException) {
                // The consent stands; RidePaymentService::capture debits what the holds don't cover.
            }
        }
    }

    private function assertCanChange(Ride $ride, string $type): void
    {
        if (! in_array($ride->status, [RideStatus::Accepted, RideStatus::Arrived, RideStatus::InProgress], true)) {
            throw new RidesException('change_unavailable', 409);
        }
        if ($ride->open_destination || $ride->destination_lat === null) {
            throw new RidesException('change_unavailable', 409);
        }
        $feature = $type === 'add_stop' ? 'multi_stop' : 'change_destination';
        if (! $this->features->allows($ride->country_id, $ride->city_id, $feature)) {
            throw RidesException::featureDisabled($feature);
        }
    }

    /**
     * The trip after the change: the destination and the stops to pass through.
     *
     * @param  array{lat: float, lng: float, address?: ?string}  $point
     * @return array{0: array{lat: float, lng: float}, 1: list<array{lat: float, lng: float}>}
     */
    private function newTrip(Ride $ride, string $type, array $point): array
    {
        $stops = $ride->stops()->get()->map(fn (RideStop $s) => ['lat' => (float) $s->lat, 'lng' => (float) $s->lng])->all();
        if ($type === 'add_stop') {
            $stops[] = ['lat' => (float) $point['lat'], 'lng' => (float) $point['lng']];

            return [['lat' => (float) $ride->destination_lat, 'lng' => (float) $ride->destination_lng], $stops];
        }

        return [['lat' => (float) $point['lat'], 'lng' => (float) $point['lng']], $stops];
    }

    private function find(Ride $ride, string $uuid, bool $lock = false): RideChangeRequest
    {
        return RideChangeRequest::query()->where('ride_id', $ride->id)->where('uuid', $uuid)->when($lock, fn ($q) => $q->lockForUpdate())->first()
            ?? throw new RidesException('change_not_found', 404);
    }
}
