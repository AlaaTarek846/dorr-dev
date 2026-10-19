<?php

namespace Modules\Rides\Services\Dispatch;

use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Driver\Enums\PresenceStatus;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverPresence;
use Modules\Driver\Services\PresenceService;
use Modules\Rides\Enums\OfferStatus;
use Modules\Rides\Enums\RidePaymentMethod;
use Modules\Rides\Enums\RidePaymentStatus;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\DriverPreference;
use Modules\Rides\Models\FavoriteDriver;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideActiveLock;
use Modules\Rides\Models\RideBid;
use Modules\Rides\Models\RideBlock;
use Modules\Rides\Models\RideHold;
use Modules\Rides\Models\RideOffer;
use Modules\Rides\Services\Airport\AirportQueue;
use Modules\Rides\Services\Bidding\BidBoard;
use Modules\Rides\Services\Bidding\BidPricing;
use Modules\Rides\Services\Circle\FavoriteCircle;
use Modules\Rides\Services\Drivers\DriverEligibility;
use Modules\Rides\Services\Drivers\DriverPreferences;
use Modules\Rides\Services\Lifecycle\RideCloser;
use Modules\Rides\Services\Lifecycle\RideStateMachine;
use Modules\Rides\Services\Notifications\RideNotifier;
use Modules\Rides\Services\Payment\RidePaymentService;
use Modules\Rides\Services\Safety\SafetyContacts;
use Modules\Rides\Services\Stats\DriverStats;
use Modules\Rides\Support\Geo;
use Modules\Wallet\Models\Wallet;

/**
 * Finds a driver for a ride (docs/rides-plan.md §8) — Jawad's widening radius, one ride at a time:
 * every round the radius grows from `search.min_radius_m` by `radius_step_m` up to `max_radius_m`,
 * and the ride goes to the nearest eligible driver for `offer_ttl_s`. A decline or a timeout starts
 * the next round. The rider is asked once (`ask_rider_after_s`), and after `max_search_s` the ride
 * expires and the hold is released. Accepting is atomic: the driver's lock row is inserted in the
 * same transaction as the conditional update, so two drivers can never get the same ride and a
 * driver can never hold two.
 */
class DispatchEngine
{
    /** Average city speed for the "arriving in N min" estimate before a route is asked for. */
    private const PICKUP_SPEED_MPS = 8.3;

    public function __construct(
        private readonly RideStateMachine $machine,
        private readonly DriverEligibility $eligibility,
        private readonly RideNotifier $notifier,
        private readonly RidePaymentService $payments,
        private readonly PresenceService $presence,
        private readonly DriverStats $stats,
        private readonly RideCloser $closer,
        private readonly AirportQueue $queue,
        private readonly FavoriteCircle $circle,
        private readonly DriverPreferences $preferences,
    ) {}

    /** Every few seconds (rides:dispatch-tick): offers that ran out, card rides never paid, then rounds that are due. */
    public function tick(): int
    {
        Ride::query()->where('status', RideStatus::AwaitingPayment->value)->get()
            ->filter(fn (Ride $ride) => $ride->requested_at?->copy()->addSeconds((int) $ride->policy('payment.await_s', 900))->isPast())
            ->each(fn (Ride $ride) => $this->expire($ride));

        RideOffer::query()->where('status', OfferStatus::Sent->value)->where('expires_at', '<=', now())->get()
            ->each(fn (RideOffer $offer) => $this->expireOffer($offer));

        // Bidding: offers whose timer ran out are declined by themselves; the next one in line shows.
        BidBoard::expireDue();

        $due = Ride::query()->whereIn('status', [RideStatus::Searching->value, RideStatus::Bidding->value])->where('next_dispatch_at', '<=', now())->pluck('id');
        foreach ($due as $id) {
            $this->runRound(Ride::query()->findOrFail($id));
        }

        return $due->count();
    }

    public function runRound(Ride $ride): void
    {
        $ride->refresh();
        if ($ride->status === RideStatus::Bidding) {
            $this->runBidRound($ride);

            return;
        }
        if ($ride->status !== RideStatus::Searching || $this->openOffer($ride) !== null) {
            return;
        }

        $policy = (array) $ride->snapshot('policy', []);
        $searchingFor = $ride->searchingSince()?->diffInSeconds(now()) ?? 0;

        if ($searchingFor >= (int) ($policy['search.max_search_s'] ?? 600)) {
            $this->expire($ride);

            return;
        }
        if ($ride->rider_asked_at === null && $searchingFor >= (int) ($policy['search.ask_rider_after_s'] ?? 240)) {
            $ride->forceFill(['rider_asked_at' => now()])->save();
            $this->notifier->rider($ride, 'still_searching');
        }
        // DORR Circle: the favourites had their chance — the rider decides whether everyone else may be asked.
        $this->circle->askIfDue($ride, $searchingFor, (int) ($policy['circle.ask_after_s'] ?? 90));

        $round = $ride->dispatch_round + 1;
        $radius = min(
            (int) ($policy['search.min_radius_m'] ?? 5000) + (int) ($policy['search.radius_step_m'] ?? 5000) * ($round - 1),
            (int) ($policy['search.max_radius_m'] ?? 50000),
        );
        // O-36 (owner): the request goes to several captains at once — the first to accept takes it.
        $batch = $this->candidates($ride, $radius, $policy);
        // O-30, O-31: a With Me request goes first to the captains already carrying riders its way.
        $pooled = app(\Modules\Rides\Services\Pool\RidePoolService::class)->poolsFor($ride)
            ->reject(fn (array $pair) => RideOffer::query()->where('ride_id', $ride->id)->where('driver_id', $pair[0]->driver_id)->exists());
        $batch = $pooled->merge($batch)->unique(fn (array $pair) => $pair[0]->driver_id)->values()->take(max(1, (int) ($policy['search.batch_size'] ?? 3)));

        if ($batch->isEmpty()) {
            $ride->forceFill([
                'dispatch_round' => $round,
                'search_radius_m' => $radius,
                'next_dispatch_at' => now()->addSeconds((int) ($policy['search.round_interval_s'] ?? 10)),
            ])->save();
            $this->notifier->changed($ride, 'search_progress');

            return;
        }

        $ttl = (int) ($policy['search.offer_ttl_s'] ?? 15);
        foreach ($batch as [$presence, $distance]) {
            if ($this->offerTo($ride, $presence, (float) $distance, $round, $radius, $ttl)) {
                return; // auto-accepted: the ride is given
            }
        }
        $this->notifier->changed($ride, 'search_progress');
    }

    /** One request card to one captain; true when Auto-Accept took the ride for them. */
    private function offerTo(Ride $ride, DriverPresence $presence, float $distance, int $round, int $radius, int $ttl): bool
    {
        try {
            $offer = DB::transaction(function () use ($ride, $presence, $distance, $round, $radius, $ttl) {
                $offer = RideOffer::query()->create([
                    'ride_id' => $ride->id,
                    'driver_id' => $presence->driver_id,
                    'round' => $round,
                    'distance_m' => (int) round($distance),
                    'eta_s' => (int) round($distance / self::PICKUP_SPEED_MPS),
                    'driver_net_minor' => $ride->snapshot('breakdown.driver_net'),
                    'score' => (int) round($distance),
                    'status' => OfferStatus::Sent,
                    'sent_at' => now(),
                    'expires_at' => now()->addSeconds($ttl),
                ]);
                $ride->forceFill(['dispatch_round' => $round, 'search_radius_m' => $radius, 'next_dispatch_at' => $offer->expires_at])->save();
                $this->machine->record($ride, 'offer_sent', 'system', null, [], ['driver_id' => $presence->driver_id, 'round' => $round, 'distance_m' => (int) round($distance)]);

                return $offer;
            });
        } catch (QueryException) {
            // Same driver, same round (a tick racing another): the next tick carries on.
            return false;
        }

        $this->stats->bump($offer->driver_id, ['offers_received' => 1]);
        $driver = Driver::query()->find($offer->driver_id);
        // Auto-Accept (D-34..D-37): a request that fits the captain's own rules is taken for them, not rung.
        if ($driver !== null && $this->preferences->autoAccepts($ride, $driver, (float) $offer->distance_m, (int) $offer->driver_net_minor)) {
            try {
                $accepted = $this->accept($driver, $offer->uuid);
                $this->notifier->driver($driver, $accepted, 'auto_accepted');

                return true;
            } catch (RidesException) {
                // Lost the race or can't be assigned: it rings like any request.
            }
        }
        $this->notifier->offer($offer->setRelation('driver', $driver), $ride);

        return false;
    }


    /**
     * Bidding (docs/rides-plan.md §9.2): the request goes to `bids.batch_size` more drivers every
     * `bids.batch_interval_s`, the radius growing like a normal search; each keeps it on screen for
     * `bids.request_ttl_s`. No offers for a while → the rider is told they may raise their price.
     */
    private function runBidRound(Ride $ride): void
    {
        $policy = (array) $ride->snapshot('policy', []);
        $searchingFor = $ride->searchingSince()?->diffInSeconds(now()) ?? 0;
        if ($searchingFor >= (int) ($policy['search.max_search_s'] ?? 600)) {
            $this->expire($ride);

            return;
        }
        if ($ride->rider_asked_at === null && $searchingFor >= (int) ($policy['bids.suggest_raise_after_s'] ?? 60)
            && ! RideBid::query()->where('ride_id', $ride->id)->exists()) {
            $ride->forceFill(['rider_asked_at' => now()])->save();
            $this->notifier->rider($ride, 'raise_suggest');
        }
        $this->circle->askIfDue($ride, $searchingFor, (int) ($policy['circle.ask_after_s'] ?? 90));

        $round = $ride->dispatch_round + 1;
        $radius = min(
            (int) ($policy['search.min_radius_m'] ?? 5000) + (int) ($policy['search.radius_step_m'] ?? 5000) * ($round - 1),
            (int) ($policy['search.max_radius_m'] ?? 50000),
        );
        $batch = $this->candidates($ride, $radius, $policy)->take(max(1, (int) ($policy['bids.batch_size'] ?? 3)));
        $ttl = (int) ($policy['bids.request_ttl_s'] ?? 60);
        $sent = [];
        foreach ($batch as [$presence, $distance]) {
            try {
                $sent[] = RideOffer::query()->create([
                    'ride_id' => $ride->id,
                    'driver_id' => $presence->driver_id,
                    'round' => $round,
                    'distance_m' => (int) round($distance),
                    'eta_s' => (int) round($distance / self::PICKUP_SPEED_MPS),
                    'driver_net_minor' => $ride->snapshot('breakdown.driver_net'),
                    'score' => (int) round($distance),
                    'status' => OfferStatus::Sent,
                    'sent_at' => now(),
                    'expires_at' => now()->addSeconds($ttl),
                ]);
            } catch (QueryException) {
                continue;
            }
        }
        $ride->forceFill([
            'dispatch_round' => $round,
            'search_radius_m' => $radius,
            'next_dispatch_at' => now()->addSeconds((int) ($policy['bids.batch_interval_s'] ?? 10)),
        ])->save();
        foreach ($sent as $offer) {
            $this->machine->record($ride, 'offer_sent', 'system', null, [], ['driver_id' => $offer->driver_id, 'round' => $round, 'distance_m' => $offer->distance_m]);
            $this->stats->bump($offer->driver_id, ['offers_received' => 1]);
            $this->notifier->offer($offer->setRelation('driver', Driver::query()->find($offer->driver_id)), $ride);
        }
        $this->notifier->changed($ride, 'search_progress');
    }

    /**
     * The driver takes the ride. Atomic: the driver's lock row and the conditional move to
     * `accepted` commit together or not at all.
     */
    public function accept(Driver $driver, string $offerUuid, ?string $idempotencyKey = null): Ride
    {
        $ride = DB::transaction(function () use ($driver, $offerUuid, $idempotencyKey) {
            /** @var RideOffer|null $offer */
            $offer = RideOffer::query()->where('uuid', $offerUuid)->lockForUpdate()->first();
            if ($offer === null || $offer->driver_id !== $driver->id) {
                throw new RidesException('offer_not_found', 404);
            }
            if ($offer->status === OfferStatus::Accepted) {
                // The same driver sent the same accept again (a retry): same answer.
                return $offer->ride()->firstOrFail();
            }
            $this->machine->replayed($offer->ride()->firstOrFail(), $idempotencyKey, 'accepted');
            if (! $offer->isOpen()) {
                throw new RidesException('offer_expired', 409);
            }
            $ride = Ride::query()->whereKey($offer->ride_id)->lockForUpdate()->firstOrFail();

            // Bidding: accepting the request = agreeing to the rider's price (§9.2.2).
            return $this->assign($driver, $ride, $offer, $ride->status === RideStatus::Bidding ? (int) $ride->rider_offer_minor : null, $idempotencyKey);
        });

        $this->announceAccepted($ride, $driver);

        return $ride;
    }

    /**
     * Gives the ride to the driver — inside the caller's transaction, the ride locked. The driver's
     * lock row makes it atomic: two drivers can never get it, a driver can never hold two. With a
     * `$price` (bidding) that price becomes the agreed fixed fare: a wallet ride holds any
     * difference first (no money, no ride), and every other offer and bid is withdrawn (§9.2.6).
     */
    public function assign(Driver $driver, Ride $ride, ?RideOffer $offer, ?int $price, ?string $idempotencyKey = null, ?RideBid $bid = null): Ride
    {
        $vehicle = $this->eligibility->activeVehicle($driver);
        if ($vehicle === null) {
            throw new RidesException('no_active_vehicle', 422);
        }

        // O-30: a With Me rider joining a car that already carries riders its way — a lock of its own,
        // not the captain's one-ride lock.
        $pools = app(\Modules\Rides\Services\Pool\RidePoolService::class);
        $pool = $ride->category()->value('slug') === \Modules\Rides\Services\Pool\RidePoolService::CATEGORY ? $pools->openFor($driver) : null;
        $joining = $pool !== null && $pool->activeRides()->exists();
        try {
            RideActiveLock::query()->create(['lock_key' => $joining ? 'pool:'.$driver->id.':'.$ride->id : RideActiveLock::forDriver($driver->id), 'ride_id' => $ride->id]);
        } catch (QueryException) {
            throw new RidesException('driver_busy', 409);
        }

        $policy = (array) $ride->snapshot('policy', []);
        $vehicle->loadMissing(['make.translations', 'model.translations', 'color.translations']);
        $agreed = [];
        if ($price !== null) {
            if ($ride->payment_method->holdsInWallet()) {
                $held = (int) RideHold::query()->where('ride_id', $ride->id)->sum('amount_minor');
                $this->payments->hold($ride, $price - $held, 'price_'.$price);
            }
            $agreed = [
                'agreed_price_minor' => $price + (int) $ride->previous_balance_minor,
                'confirmed_snapshot' => ['breakdown' => BidPricing::breakdown($ride, $price)] + (array) $ride->confirmed_snapshot,
            ];
        }

        $ride = $this->machine->move($ride, RideStatus::Accepted, 'accepted', 'driver', $driver->id, [
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'vehicle_snapshot' => [
                'make' => $vehicle->make?->translated('name'),
                'model' => $vehicle->model?->translated('name'),
                'color' => $vehicle->color?->translated('name'),
                'color_hex' => $vehicle->color?->hex,
                'plate_letters' => $vehicle->plate_letters,
                'plate_number' => $vehicle->plate_number,
                'year' => $vehicle->year,
            ],
            // The city's rule, or the rider's own choice — always / at night (R-229).
            'pin' => ! empty($policy['pin.required']) || SafetyContacts::wantsPin($ride) ? (string) random_int(1000, 9999) : null,
            'accepted_at' => now(),
            'next_dispatch_at' => null,
            ...$agreed,
        ], [], array_filter(['offer_uuid' => $offer?->uuid, 'bid_uuid' => $bid?->uuid, 'price_minor' => $price]), $idempotencyKey);

        $offer ??= RideOffer::query()->where('ride_id', $ride->id)->where('driver_id', $driver->id)->where('status', OfferStatus::Sent->value)->first();
        $offer?->update(['status' => OfferStatus::Accepted, 'responded_at' => now()]);
        RideOffer::query()->where('ride_id', $ride->id)->when($offer, fn ($q) => $q->where('id', '!=', $offer->id))->where('status', OfferStatus::Sent->value)
            ->update(['status' => OfferStatus::Withdrawn->value, 'responded_at' => now()]);
        // O-36: the captain's other open requests go back to the search for someone else, at once.
        $others = RideOffer::query()->where('driver_id', $driver->id)->where('ride_id', '!=', $ride->id)->where('status', OfferStatus::Sent->value)->pluck('ride_id');
        if ($others->isNotEmpty()) {
            RideOffer::query()->where('driver_id', $driver->id)->whereIn('ride_id', $others)->where('status', OfferStatus::Sent->value)
                ->update(['status' => OfferStatus::Withdrawn->value, 'responded_at' => now()]);
            Ride::query()->whereIn('id', $others)->where('status', RideStatus::Searching->value)->update(['next_dispatch_at' => now()]);
        }
        BidBoard::closeRide($ride, $bid);

        // With Me: the newcomer's money is split with the riders in the car; a first With Me ride opens a pool.
        $joining ? $pools->join($ride, $driver) : $pools->startWith($ride, $driver);

        $this->presence->rideStarted($driver);
        // Whatever the ride, the captain is no longer waiting in an airport line (D-280).
        $this->queue->onAssigned($driver);

        return $ride;
    }

    /** After the commit: the stats, the live update and the rider's "your captain is on the way". */
    public function announceAccepted(Ride $ride, Driver $driver): void
    {
        $this->stats->bump($driver->id, ['offers_accepted' => 1]);
        // Trusted contacts set to "every ride" / "at night" get the live link now (R-221).
        app(SafetyContacts::class)->autoShare($ride);
        // Booked for someone else (R-13): that person hears who is coming, with the live link.
        app(SafetyContacts::class)->beneficiary($ride);
        $ride->loadMissing(['driver', 'rider']);
        $this->notifier->changed($ride, 'accepted');
        $snapshot = (array) $ride->vehicle_snapshot;
        $this->notifier->rider($ride, 'accepted', [
            'name' => $driver->name ?? '',
            'car' => trim(($snapshot['make'] ?? '').' '.($snapshot['model'] ?? '')),
            'plate' => trim(($snapshot['plate_letters'] ?? '').' '.($snapshot['plate_number'] ?? '')),
            'minutes' => max(1, (int) ceil((int) RideOffer::query()->where('ride_id', $ride->id)->where('driver_id', $driver->id)->latest('id')->value('eta_s') / 60)),
        ]);
    }

    public function decline(Driver $driver, string $offerUuid, ?string $reason = null): void
    {
        $offer = RideOffer::query()->where('uuid', $offerUuid)->where('driver_id', $driver->id)->first();
        if ($offer === null) {
            throw new RidesException('offer_not_found', 404);
        }
        if ($offer->status !== OfferStatus::Sent) {
            return;
        }

        $offer->update(['status' => OfferStatus::Declined, 'responded_at' => now(), 'decline_reason' => $reason]);
        $this->stats->bump($driver->id, ['offers_declined' => 1]);
        // The next round goes out right away instead of waiting for the timeout (bidding keeps its batches).
        Ride::query()->whereKey($offer->ride_id)->where('status', RideStatus::Searching->value)->update(['next_dispatch_at' => now()]);
        $this->runRound($offer->ride()->firstOrFail());
    }

    public function expireOffer(RideOffer $offer): void
    {
        $updated = RideOffer::query()->whereKey($offer->id)->where('status', OfferStatus::Sent->value)
            ->update(['status' => OfferStatus::Expired->value, 'responded_at' => now()]);
        if ($updated > 0) {
            Ride::query()->whereKey($offer->ride_id)->where('status', RideStatus::Searching->value)->update(['next_dispatch_at' => now()]);
        }
    }

    /**
     * No driver in time — or a card ride never paid: the ride expires, nothing is charged, the hold
     * is released (Jawad deleted it), and an unpaid payment screen is closed.
     */
    public function expire(Ride $ride): void
    {
        $unpaid = $ride->status === RideStatus::AwaitingPayment;
        $ride = DB::transaction(function () use ($ride, $unpaid) {
            $ride = $this->machine->move($ride, RideStatus::Expired, $unpaid ? 'payment_expired' : 'expired', 'system', null, ['expired_at' => now(), 'next_dispatch_at' => null]);
            $this->payments->releaseAll($ride, 'ride_expired');
            $ride->forceFill(['payment_status' => $unpaid ? RidePaymentStatus::Failed : RidePaymentStatus::Released])->save();
            $this->closer->close($ride);

            return $ride;
        });

        $this->notifier->changed($ride, 'expired');
        $this->notifier->rider($ride, $unpaid ? 'payment_expired' : 'expired');
    }

    private function openOffer(Ride $ride): ?RideOffer
    {
        return RideOffer::query()->where('ride_id', $ride->id)->where('status', OfferStatus::Sent->value)->where('expires_at', '>', now())->first();
    }

    /**
     * Online drivers inside the radius who may take this ride, nearest first.
     *
     * @param  array<string, mixed>  $policy
     * @return Collection<int, array{0: DriverPresence, 1: float}>
     */
    private function candidates(Ride $ride, int $radius, array $policy): Collection
    {
        [$minLat, $maxLat, $minLng, $maxLng] = Geo::boxAround($ride->origin_lat, $ride->origin_lng, $radius);
        $staleAfter = (int) ($policy['driver.stale_after_s'] ?? 900);
        $cooldown = (int) ($policy['search.refuse_cooldown_s'] ?? 300);

        $refused = RideOffer::query()->where('ride_id', $ride->id)
            ->where(fn ($q) => $q->whereIn('status', [OfferStatus::Declined->value, OfferStatus::Expired->value])->where('responded_at', '>', now()->subSeconds($cooldown))
                ->orWhere('status', OfferStatus::Withdrawn->value))
            ->pluck('driver_id');
        // A driver who stepped back from this ride never gets it again.
        $released = $ride->events()->where('type', 'driver_released')->pluck('actor_id');
        // O-36: a captain may hold a few requests at once and choose — full only at `search.max_open_offers_per_driver`.
        $maxOpen = max(1, (int) ($policy['search.max_open_offers_per_driver'] ?? 3));
        $busyWithOffer = RideOffer::query()->where('status', OfferStatus::Sent->value)->where('expires_at', '>', now())
            ->selectRaw('driver_id, COUNT(*) as c')->groupBy('driver_id')->havingRaw('COUNT(*) >= ?', [$maxOpen])->pluck('driver_id')
            // One live bid per driver at a time (§9.3).
            ->merge(RideActiveLock::query()->where('lock_key', 'like', 'driver_bid:%')->pluck('lock_key')->map(fn (string $k) => (int) substr($k, 11)));
        $blocked = RideBlock::query()
            ->where(fn ($q) => $q->where('blocker_type', 'user')->where('blocker_id', $ride->user_id)->where('blocked_type', 'driver'))
            ->pluck('blocked_id')
            ->merge(RideBlock::query()->where('blocker_type', 'driver')->where('blocked_type', 'user')->where('blocked_id', $ride->user_id)->pluck('blocker_id'));
        $riderPhone = $ride->rider()->value('phone');
        $category = $ride->category()->firstOrFail();
        $favorites = FavoriteDriver::query()->where('user_id', $ride->user_id)->pluck('driver_id');
        // An airport ride goes first to the captains waiting their turn there, in line order (D-276); nobody queued and eligible → the usual search.
        $queued = $this->queue->orderFor($ride);
        // A captain whose reserved ride starts before this one could end is kept for it (D-267).
        $reservedSoon = Ride::query()->where('status', RideStatus::Scheduled->value)->whereNotNull('reserved_driver_id')
            ->where('next_dispatch_at', '<=', now()->addSeconds((int) ($ride->planned_duration_s ?? 1800) + 900))
            ->pluck('reserved_driver_id');
        $minBalance = (int) ($policy['driver.min_balance_minor'] ?? -5000);

        $presences = DriverPresence::query()
            ->where('status', PresenceStatus::Online->value)
            ->where('gps_enabled', true)
            ->where('last_seen_at', '>=', now()->subSeconds($staleAfter))
            // DORR Circle: only the favourites the rider picked, until they widen the search.
            ->when($ride->search_scope === 'circle', fn ($q) => $q->whereIn('driver_id', array_map('intval', (array) $ride->circle_driver_ids)))
            ->whereBetween('lat', [$minLat, $maxLat])
            ->whereBetween('lng', [$minLng, $maxLng])
            ->whereNotIn('driver_id', $refused->merge($released)->merge($busyWithOffer)->merge($blocked)->merge($reservedSoon)->unique()->values())
            ->whereNotIn('driver_id', RideActiveLock::query()->where('lock_key', 'like', 'driver:%')->pluck('lock_key')->map(fn (string $k) => (int) substr($k, 7)))
            ->get();
        // How far each captain said they will go for a pickup (D-33); none = as far as the search reaches.
        $reach = DriverPreference::query()->whereIn('driver_id', $presences->pluck('driver_id'))->whereNotNull('pickup_radius_m')->pluck('pickup_radius_m', 'driver_id');

        return $presences
            ->map(fn (DriverPresence $p) => [$p, Geo::metres($ride->origin_lat, $ride->origin_lng, (float) $p->lat, (float) $p->lng)])
            ->filter(fn (array $pair) => $pair[1] <= $radius && $pair[1] <= (int) ($reach[$pair[0]->driver_id] ?? PHP_INT_MAX))
            // The captains in the airport's line first, in line order; then the rider's favourites, when close enough (R-226: a preference, not a promise).
            ->sortBy([fn (array $a, array $b) => ($queued[$a[0]->driver_id] ?? PHP_INT_MAX) <=> ($queued[$b[0]->driver_id] ?? PHP_INT_MAX), fn (array $a, array $b) => ($favorites->contains($b[0]->driver_id) <=> $favorites->contains($a[0]->driver_id)), fn (array $a, array $b) => $a[1] <=> $b[1]])
            ->filter(function (array $pair) use ($ride, $category, $riderPhone, $minBalance) {
                $driver = Driver::query()->find($pair[0]->driver_id);
                if ($driver === null || $driver->phone === $riderPhone || $this->eligibility->reasonNotToWork($driver) !== null) {
                    return false;
                }
                $vehicle = $this->eligibility->activeVehicle($driver);
                if ($vehicle === null || $this->eligibility->categoryProblem($driver, $vehicle, $category) !== null || ! $this->eligibility->wants($driver, $category)) {
                    return false;
                }
                // A driver deep in debt only gets rides already paid online (Jawad: driver_min_to_get_ride).
                if ($ride->payment_method === RidePaymentMethod::Cash) {
                    $balance = (int) Wallet::query()->where('owner_type', 'driver')->where('owner_id', $driver->id)->where('country_id', $ride->country_id)->value('withdrawable_minor');
                    if ($balance < $minBalance) {
                        return false;
                    }
                }

                return true;
            })
            ->values();
    }
}
