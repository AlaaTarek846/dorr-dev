<?php

namespace Modules\Rides\Services\Bidding;

use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Driver\Models\Driver;
use Modules\Rides\Enums\BidStatus;
use Modules\Rides\Enums\OfferStatus;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\DriverRideProfile;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideActiveLock;
use Modules\Rides\Models\RideBid;
use Modules\Rides\Models\RideHold;
use Modules\Rides\Models\RideOffer;
use Modules\Rides\Models\RidePriceOffer;
use Modules\Rides\Services\Dispatch\DispatchEngine;
use Modules\Rides\Services\Lifecycle\RideStateMachine;
use Modules\Rides\Services\Notifications\RideNotifier;
use Modules\Rides\Services\Payment\RidePaymentService;
use Modules\User\Models\User;

/**
 * Bidding, exactly like inDrive (owner, 2026-10-13 — docs/rides-plan.md §9):
 *  - a driver accepts the rider's price → the ride is theirs at once (DispatchEngine::accept);
 *  - or sends a higher price (up to `bids.counter_cap_bp` over) → the rider sees every offer with a
 *    `bids.ttl_s` timer, takes one or declines it; a timer that runs out declines it;
 *  - a declined / expired driver may offer again — even lower than before, still above the rider;
 *  - the rider may raise their price at any time (a wallet ride holds the difference first).
 * The first agreement locks the ride atomically; every other offer is withdrawn.
 */
class BiddingService
{
    public function __construct(
        private readonly DispatchEngine $dispatch,
        private readonly RideStateMachine $machine,
        private readonly RideNotifier $notifier,
        private readonly RidePaymentService $payments,
    ) {}

    /** The driver answers the request with their own price (equal to the rider's = accepting it). */
    public function counter(Driver $driver, string $offerUuid, int $price, ?string $key = null): RideBid|Ride
    {
        if ($key !== null && ($done = RideBid::query()->where('idempotency_key', $key)->first()) !== null) {
            return $done;
        }

        $offer = RideOffer::query()->where('uuid', $offerUuid)->where('driver_id', $driver->id)->first() ?? throw new RidesException('offer_not_found', 404);
        $ride = $offer->ride()->firstOrFail();
        if ($ride->status !== RideStatus::Bidding) {
            throw new RidesException('not_bidding', 409);
        }
        if ($price === (int) $ride->rider_offer_minor) {
            return $this->dispatch->accept($driver, $offerUuid, $key);
        }

        $bid = DB::transaction(function () use ($driver, $offer, $price, $key) {
            $offer = RideOffer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            $ride = Ride::query()->whereKey($offer->ride_id)->lockForUpdate()->firstOrFail();
            if (! $offer->isOpen()) {
                throw new RidesException('offer_expired', 409);
            }
            $riderPrice = (int) $ride->rider_offer_minor;
            $policy = (array) $ride->snapshot('policy', []);
            if ($price < $riderPrice) {
                throw new RidesException('bid_below_rider_price', 422, [], ['rider_price_minor' => $riderPrice]);
            }
            $cap = BidPricing::cap($policy, $riderPrice);
            if ($price > $cap) {
                throw new RidesException('bid_above_cap', 422, [], ['cap_minor' => $cap]);
            }
            $attempt = RideBid::query()->where('ride_id', $ride->id)->where('driver_id', $driver->id)->count() + 1;
            $maxAttempts = (int) ($policy['bids.max_attempts_per_driver'] ?? 0);
            if ($maxAttempts > 0 && $attempt > $maxAttempts) {
                throw new RidesException('bid_attempts_used', 409);
            }
            try {
                RideActiveLock::query()->create(['lock_key' => RideActiveLock::forDriverBid($driver->id), 'ride_id' => $ride->id]);
            } catch (QueryException) {
                throw new RidesException('bid_already_open', 409);
            }

            $max = (int) ($policy['bids.max_visible'] ?? 0);
            $show = $max === 0 || RideBid::query()->where('ride_id', $ride->id)->where('status', BidStatus::Pending->value)->count() < $max;
            $bid = RideBid::query()->create([
                'ride_id' => $ride->id,
                'driver_id' => $driver->id,
                'attempt' => $attempt,
                'type' => 'counter',
                'rider_price_minor' => $riderPrice,
                'bid_price_minor' => $price,
                'driver_net_minor' => BidPricing::driverNet($ride, $price),
                'distance_m' => $offer->distance_m,
                'eta_s' => $offer->eta_s,
                'status' => $show ? BidStatus::Pending : BidStatus::Queued,
                'shown_at' => $show ? now() : null,
                'expires_at' => $show ? now()->addSeconds((int) ($policy['bids.ttl_s'] ?? 20)) : null,
                'idempotency_key' => $key,
            ]);
            $this->machine->record($ride, 'bid_submitted', 'driver', $driver->id, [], ['bid_uuid' => $bid->uuid, 'price_minor' => $price, 'attempt' => $attempt]);

            return $bid;
        });

        $ride->refresh();
        $this->notifier->changed($ride, 'bid_new');
        $this->notifier->rider($ride, 'bid_new', ['name' => $driver->name ?? '', 'amount' => $this->notifier->money($price, $ride)]);

        return $bid;
    }

    /** The rider takes a driver's offer: the ride is agreed at that price (the wallet holds the difference first). */
    public function acceptBid(User $rider, Ride $ride, string $bidUuid, ?string $key = null): Ride
    {
        [$ride, $driver] = DB::transaction(function () use ($rider, $ride, $bidUuid, $key) {
            $ride = Ride::query()->whereKey($ride->id)->where('user_id', $rider->id)->lockForUpdate()->firstOrFail();
            $bid = RideBid::query()->where('uuid', $bidUuid)->where('ride_id', $ride->id)->lockForUpdate()->first() ?? throw new RidesException('bid_not_found', 404);
            if ($bid->status === BidStatus::Accepted) {
                return [$ride, $bid->driver];
            }
            if ($ride->status !== RideStatus::Bidding) {
                throw new RidesException('not_bidding', 409);
            }
            if ($bid->status !== BidStatus::Pending || $bid->expires_at?->isPast()) {
                throw new RidesException('bid_expired', 409);
            }
            $driver = $bid->driver()->firstOrFail();

            return [$this->dispatch->assign($driver, $ride, null, (int) $bid->bid_price_minor, $key, $bid), $driver];
        });

        $this->dispatch->announceAccepted($ride, $driver);

        return $ride;
    }

    public function declineBid(User $rider, Ride $ride, string $bidUuid): void
    {
        if ($ride->user_id !== $rider->id) {
            throw new RidesException('ride_not_found', 404);
        }
        $bid = RideBid::query()->where('uuid', $bidUuid)->where('ride_id', $ride->id)->first() ?? throw new RidesException('bid_not_found', 404);
        BidBoard::finish($bid, BidStatus::Declined);
        BidBoard::promote($ride);
        $this->machine->record($ride, 'bid_declined', 'user', $rider->id, [], ['bid_uuid' => $bid->uuid]);
        $this->notifier->changed($ride, 'bid_declined');
        if ($bid->driver !== null) {
            $this->notifier->driver($bid->driver, $ride, 'bid_declined');
        }
    }

    /** The rider raises their price (§9.2.5): drivers see it at once and a new batch goes out. */
    public function raise(User $rider, Ride $ride, int $price): Ride
    {
        $ride = DB::transaction(function () use ($rider, $ride, $price) {
            $ride = Ride::query()->whereKey($ride->id)->where('user_id', $rider->id)->lockForUpdate()->firstOrFail();
            if ($ride->status !== RideStatus::Bidding) {
                throw new RidesException('not_bidding', 409);
            }
            if ($price <= (int) $ride->rider_offer_minor) {
                throw new RidesException('raise_not_higher', 422, [], ['rider_price_minor' => (int) $ride->rider_offer_minor]);
            }
            if ($price > (int) $ride->bid_suggested_minor * 5) {
                throw new RidesException('bid_too_high', 422);
            }
            if ($ride->payment_method->holdsInWallet()) {
                $held = (int) RideHold::query()->where('ride_id', $ride->id)->sum('amount_minor');
                $this->payments->hold($ride, $price - $held, 'price_'.$price);
            }
            $ride->forceFill([
                'rider_offer_minor' => $price,
                'confirmed_snapshot' => ['breakdown' => BidPricing::breakdown($ride, $price)] + (array) $ride->confirmed_snapshot,
                'next_dispatch_at' => now(),
                'version' => $ride->version + 1,
            ])->save();
            RidePriceOffer::query()->create(['ride_id' => $ride->id, 'price_minor' => $price, 'floor_minor' => (int) $ride->bid_floor_minor, 'reason' => 'raise', 'created_at' => now()]);
            // The drivers looking at it see the new price and what they'd earn.
            RideOffer::query()->where('ride_id', $ride->id)->where('status', OfferStatus::Sent->value)->update(['driver_net_minor' => $ride->snapshot('breakdown.driver_net')]);
            $this->machine->record($ride, 'price_raised', 'user', $rider->id, [], ['price_minor' => $price]);

            return $ride;
        });

        $this->notifier->changed($ride, 'price_raised');
        $this->dispatch->runRound($ride);

        return $ride->refresh();
    }

    /**
     * The offers on the rider's screen: cheapest first, "cheapest" and "nearest" marked, each with its
     * seconds left; how many wait in line.
     *
     * @return array{bids: Collection<int, array<string, mixed>>, queued: int}
     */
    public function board(Ride $ride): array
    {
        $bids = RideBid::query()->where('ride_id', $ride->id)->where('status', BidStatus::Pending->value)->where('expires_at', '>', now())
            ->with(['driver.activeVehicle.make.translations', 'driver.activeVehicle.model.translations', 'driver.activeVehicle.color.translations'])
            ->orderBy('bid_price_minor')->orderBy('eta_s')->get();
        $profiles = DriverRideProfile::query()->whereIn('driver_id', $bids->pluck('driver_id'))->get()->keyBy('driver_id');
        $cheapest = $bids->min('bid_price_minor');
        $nearest = $bids->min('eta_s');

        return [
            'bids' => $bids->map(function (RideBid $b) use ($profiles, $cheapest, $nearest) {
                $profile = $profiles[$b->driver_id] ?? null;
                $vehicle = $b->driver?->activeVehicle;

                return [
                    'uuid' => $b->uuid,
                    'price_minor' => $b->bid_price_minor,
                    'eta_s' => $b->eta_s,
                    'distance_m' => $b->distance_m,
                    'seconds_left' => max(0, (int) now()->diffInSeconds($b->expires_at, false)),
                    'ttl_s' => (int) $b->shown_at?->diffInSeconds($b->expires_at),
                    'cheapest' => $b->bid_price_minor === $cheapest,
                    'nearest' => $b->eta_s === $nearest,
                    'driver' => [
                        'name' => $b->driver?->name,
                        'avatar_url' => $b->driver?->getFirstMediaUrl('avatar') ?: null,
                        'rating' => $profile !== null && $profile->rating_count > 0 ? (float) $profile->rating_avg : null,
                        'trips_count' => (int) ($profile?->trips_count ?? 0),
                        'vehicle' => $vehicle === null ? null : [
                            'make' => $vehicle->make?->translated('name'), 'model' => $vehicle->model?->translated('name'),
                            'color' => $vehicle->color?->translated('name'), 'color_hex' => $vehicle->color?->hex, 'year' => $vehicle->year,
                        ],
                    ],
                ];
            })->values(),
            'queued' => RideBid::query()->where('ride_id', $ride->id)->where('status', BidStatus::Queued->value)->count(),
        ];
    }
}
