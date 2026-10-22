<?php

namespace Modules\Rides\Services\Lifecycle;

use Illuminate\Support\Facades\DB;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverLocationLog;
use Modules\Rides\Enums\DistanceSource;
use Modules\Rides\Enums\PricingMode;
use Modules\Rides\Enums\PricingStrategy;
use Modules\Rides\Enums\RidePaymentStatus;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Enums\WaslTripStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\DriverRideProfile;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideOffer;
use Modules\Rides\Models\RidePriceLine;
use Modules\Rides\Models\RiderRideProfile;
use Modules\Rides\Models\RideStop;
use Modules\Rides\Models\RideTrack;
use Modules\Rides\Services\Drivers\DriverEligibility;
use Modules\Rides\Services\Notifications\RideNotifier;
use Modules\Rides\Services\Safety\SafetyCheckService;
use Modules\Rides\Services\Safety\SafetyContacts;
use Modules\Rides\Services\Payment\RidePaymentService;
use Modules\Rides\Services\Pricing\FareCalculator;
use Modules\Rides\Services\Pricing\PriceExtras;
use Modules\Rides\Services\Pricing\RidePromotions;
use Modules\Rides\Services\Settlement\RideSettlementService;
use Modules\Rides\Services\Stats\DriverPoints;
use Modules\Rides\Services\Stats\DriverStats;
use Modules\Rides\Support\Geo;
use Modules\Rides\Support\Polyline;

/**
 * The driver's side of a ride (docs/rides-plan.md §6): arrived (within `arrival.radius_m` of the
 * pickup — server time, D-117), the PIN, started, each stop, and the end — where the final price is
 * worked out (upfront: the agreed fare + waiting; metered: the distance actually driven), charged,
 * and settled with the driver. Every action carries an Idempotency-Key: a retry returns the ride.
 */
class RideTripService
{
    public function __construct(
        private readonly RideStateMachine $machine,
        private readonly RideNotifier $notifier,
        private readonly RidePaymentService $payments,
        private readonly FareCalculator $calculator,
        private readonly RideSettlementService $settlement,
        private readonly RideCloser $closer,
        private readonly DriverStats $stats,
        private readonly DriverPoints $points,
        private readonly DriverEligibility $eligibility,
        private readonly SafetyContacts $contacts,
        private readonly SafetyCheckService $checks,
        private readonly RidePromotions $promotions,
    ) {}

    public function arrive(Driver $driver, Ride $ride, float $lat, float $lng, ?string $key = null): Ride
    {
        if (($done = $this->machine->replayed($ride, $key, 'arrived')) !== null) {
            return $done;
        }
        $this->assertDriver($driver, $ride);
        // Block 06: a next trip waits until the ride before it has ended.
        if (\Modules\Rides\Services\Dispatch\NextTrip::waiting($ride) !== null) {
            throw new RidesException('finish_current_first', 422);
        }

        $distance = (int) round(Geo::metres($lat, $lng, $ride->origin_lat, $ride->origin_lng));
        if ($distance > (int) $ride->policy('arrival.radius_m', 100)) {
            throw new RidesException('not_at_pickup', 422, ['distance' => $distance], ['distance_m' => $distance]);
        }

        $ride = $this->machine->move($ride, RideStatus::Arrived, 'arrived', 'driver', $driver->id, ['arrived_at' => now()], ['lat' => $lat, 'lng' => $lng], ['distance_m' => $distance], $key);
        $this->notifier->changed($ride, 'arrived');
        $this->notifier->rider($ride, 'arrived', ['name' => $driver->name ?? '']);

        return $ride;
    }

    public function start(Driver $driver, Ride $ride, ?string $pin, float $lat, float $lng, ?string $key = null): Ride
    {
        if (($done = $this->machine->replayed($ride, $key, 'started')) !== null) {
            return $done;
        }
        $this->assertDriver($driver, $ride);

        if ($ride->pin !== null) {
            if ($ride->pin_attempts >= 5) {
                throw new RidesException('pin_locked', 423);
            }
            if (! hash_equals($ride->pin, (string) $pin)) {
                $ride->increment('pin_attempts');
                $this->machine->record($ride, 'pin_failed', 'driver', $driver->id, ['lat' => $lat, 'lng' => $lng]);
                throw new RidesException('pin_invalid', 422, [], ['attempts_left' => max(0, 5 - $ride->pin_attempts)]);
            }
        }

        $waited = $ride->waitedSeconds();
        $ride = $this->machine->move($ride, RideStatus::InProgress, 'started', 'driver', $driver->id, [
            'started_at' => now(),
            'pickup_waiting_s' => (int) $waited,
        ], ['lat' => $lat, 'lng' => $lng], [], $key);

        $this->notifier->changed($ride, 'started');
        $this->notifier->rider($ride, 'started');
        // R-230: "fasten your seatbelt" as the ride starts (the rider may turn it off).
        if (SafetyContacts::preferences($ride->rider()->firstOrFail())->seatbelt_reminder ?? true) {
            $this->notifier->rider($ride, 'seatbelt');
        }

        return $ride;
    }

    public function stop(Driver $driver, Ride $ride, int $seq, bool $arrived): Ride
    {
        $this->assertDriver($driver, $ride);
        if ($ride->status !== RideStatus::InProgress) {
            throw new RidesException('invalid_state', 409, ['status' => $ride->status->value], ['status' => $ride->status->value]);
        }
        $stop = RideStop::query()->where('ride_id', $ride->id)->where('seq', $seq)->first() ?? throw new RidesException('stop_not_found', 404);

        if ($arrived && $stop->arrived_at === null) {
            $stop->update(['status' => 'arrived', 'arrived_at' => now()]);
        } elseif (! $arrived && $stop->departed_at === null) {
            $stop->update(['status' => 'done', 'departed_at' => now(), 'wait_s' => (int) ($stop->arrived_at?->diffInSeconds(now()) ?? 0)]);
        }
        $this->machine->record($ride, $arrived ? 'stop_arrived' : 'stop_departed', 'driver', $driver->id, [], ['seq' => $seq]);
        $this->notifier->changed($ride, $arrived ? 'stop_arrived' : 'stop_departed');

        return $ride->refresh();
    }

    /**
     * Ends the ride where the car is. Away from the destination (more than `arrival.destination_radius_m`)
     * it is refused unless the captain confirms ending early ($early — the rider getting off sooner);
     * then an upfront fare is worked out again on the distance actually driven, never above the
     * agreed one (Jawad's recalculation, O-40). An offers ride keeps the price both sides agreed.
     *
     * @param  int|null  $odometerM  the phone's own distance counter for the trip (§10.1)
     */
    public function end(Driver $driver, Ride $ride, float $lat, float $lng, ?int $odometerM, ?string $key = null, bool $early = false): Ride
    {
        if (($done = $this->machine->replayed($ride, $key, 'completed')) !== null) {
            return $done;
        }
        $this->assertDriver($driver, $ride);

        // By the hour (O-17) the rider bought time, not a trip to a place: it ends wherever they get off.
        $away = $ride->snapshot('hourly') !== null ? 0 : $this->awayFromDestination($ride, $lat, $lng);
        if ($away > 0 && ! $early) {
            throw new RidesException('not_at_destination', 422, ['distance' => number_format($away / 1000, 1)], ['distance_m' => $away]);
        }

        $ride = DB::transaction(function () use ($driver, $ride, $lat, $lng, $odometerM, $key, $away) {
            $policy = (array) $ride->snapshot('policy', []);
            $price = FareCalculator::priceOf($policy);
            $track = $this->trackPoints($ride, $lat, $lng);
            [$distance, $source] = $this->distance($ride, $track, $odometerM, $lat, $lng, $away === 0);
            $duration = $ride->started_at !== null ? (int) $ride->started_at->diffInSeconds(now()) : 0;

            $chargeableWait = max(0, $ride->pickup_waiting_s - (int) ($policy['waiting.free_s'] ?? 180));
            $stopsWaited = (int) RideStop::query()->where('ride_id', $ride->id)->sum('wait_s');
            $chargeableStops = (int) RideStop::query()->where('ride_id', $ride->id)->get()
                ->sum(fn (RideStop $s) => max(0, $s->wait_s - (int) ($policy['stops.free_wait_s'] ?? 120)));

            $fixed = $ride->pricing_mode === PricingMode::Upfront ? (int) $ride->snapshot('breakdown.fare') : null;
            if ($fixed !== null && $away > 0 && ! $this->isOffersRide($ride)) {
                // Ended before the destination: what was actually driven, never more than agreed.
                $fixed = min($fixed, $this->calculator->compute($price, $distance, $duration, null)->fare);
            }

            // O-17: by the hour — each minute past the hours booked is added (whole minutes, started ones count).
            $hourly = (array) $ride->snapshot('hourly', []);
            $overtimeMin = 0;
            if ($fixed !== null && $hourly !== []) {
                $overtimeMin = (int) ceil(max(0, $duration - (int) $hourly['hours'] * 3600) / 60);
                $fixed += $overtimeMin * (int) ($hourly['overtime_per_min_minor'] ?? 0);
            }

            // With Me (O-32): what sharing the car took off this fare.
            if ($fixed !== null && (int) $ride->pool_discount_minor > 0) {
                $fixed = max(0, $fixed - (int) $ride->pool_discount_minor);
            }

            $breakdown = $this->calculator->compute(
                $price, $distance, $duration, $ride->snapshot('tax'),
                $chargeableWait, $chargeableStops, (int) $ride->previous_balance_minor, $fixed,
            );
            // The service fee, the coupon and the payment fee fixed at confirmation (R-45, R-47, R-48).
            if (($extras = (array) $ride->snapshot('extras', [])) !== []) {
                $breakdown = PriceExtras::apply($breakdown, $extras);
                if (isset($extras['coupon'])) {
                    $this->promotions->settle($ride, $breakdown->discount);
                }
            }

            foreach ($breakdown->lines as $i => $line) {
                RidePriceLine::query()->create([
                    'ride_id' => $ride->id, 'phase' => 'final', 'code' => $line['code'], 'amount_minor' => $line['amount_minor'],
                    'visible_to' => $line['visible_to'], 'meta' => $line['meta'] ?? null, 'sort_order' => $i, 'created_at' => now(),
                ]);
            }

            $wallet = $ride->payment_method->holdsInWallet();
            // O-37: a cash ride paid partly from the wallet — that part is taken now, the rest is cash.
            $walletPart = $wallet ? 0 : min($breakdown->total, $this->payments->held($ride));
            $ride = $this->machine->move($ride, RideStatus::Completed, 'completed', 'driver', $driver->id, [
                'completed_at' => now(),
                'final_total_minor' => $breakdown->total,
                'commission_minor' => $breakdown->commission,
                'tax_minor' => $breakdown->tax,
                'driver_net_minor' => $breakdown->driverNet,
                'metered_distance_m' => $distance,
                'metered_duration_s' => $duration,
                'distance_source' => $source,
                'stops_waiting_s' => $stopsWaited,
                'destination_lat' => $ride->open_destination ? $lat : $ride->destination_lat,
                'destination_lng' => $ride->open_destination ? $lng : $ride->destination_lng,
                'payment_status' => $wallet || $walletPart >= $breakdown->total ? RidePaymentStatus::Captured : RidePaymentStatus::CashDue,
                'wasl_status' => $this->eligibility->regulatorFor($driver) !== null ? WaslTripStatus::Pending : WaslTripStatus::NotRequired,
            ], ['lat' => $lat, 'lng' => $lng], ['odometer_m' => $odometerM, 'distance_m' => $distance, 'source' => $source->value] + ($away > 0 ? ['ended_early' => true, 'away_from_destination_m' => $away] : []), $key);

            if ($wallet) {
                $this->payments->capture($ride, $breakdown->total);
            } elseif ($walletPart > 0) {
                $this->payments->capture($ride, $walletPart);
                $ride->forceFill(['confirmed_snapshot' => ['wallet_paid_minor' => $walletPart] + (array) $ride->confirmed_snapshot])->save();
            }

            RideTrack::query()->create([
                'ride_id' => $ride->id,
                'polyline' => Polyline::encode($track),
                'points_count' => count($track),
                'track_distance_m' => $this->trackDistance($track),
                'device_distance_m' => $odometerM,
                'created_at' => now(),
            ]);

            $this->settlement->completed($ride);
            $this->profiles($ride);
            $this->closer->close($ride);

            return $ride;
        });

        $extraLines = RidePriceLine::query()->where('ride_id', $ride->id)->where('phase', 'final')->whereIn('code', PriceExtras::CODES)->pluck('amount_minor', 'code');
        $fees = (int) ($extraLines['customer_fee'] ?? 0) + (int) ($extraLines['payment_method_fee'] ?? 0) + (int) ($extraLines['discount'] ?? 0) + (int) ($extraLines['wait_save'] ?? 0);
        $this->stats->bump($driver->id, [
            'trips' => 1,
            // What the ride earned: not the old debt, nor DORR's fees; a coupon is DORR's, not a lower fare.
            'gross_minor' => (int) $ride->final_total_minor - (int) $ride->previous_balance_minor - $fees,
            'net_minor' => (int) $ride->driver_net_minor,
            'commission_minor' => (int) $ride->commission_minor,
            'tax_minor' => (int) $ride->tax_minor,
            'distance_with_rider_m' => (int) $ride->metered_distance_m,
        ]);
        $promised = (int) RideOffer::query()->where('ride_id', $ride->id)->where('driver_id', $driver->id)->where('status', 'accepted')->value('eta_s');
        $this->points->forCompletedRide($ride, $promised);
        // D-239..D-253: the captain's rank follows their numbers.
        app(\Modules\Rides\Services\Drivers\DriverRanks::class)->evaluate($driver);
        // R-146: the rider's DORR points for what they paid.
        app(\Modules\Rides\Services\Loyalty\LoyaltyService::class)->earnForRide($ride);
        // AI-06: signs this ride may not be what it looks like — for the team, nothing automatic.
        app(\Modules\Rides\Services\Safety\FraudSignals::class)->check($ride);

        $this->notifier->changed($ride, 'completed');
        $amount = $this->notifier->money((int) $ride->final_total_minor, $ride);
        if ($away > 0) {
            // Ended before the destination: the rider is told where and what it came to, and can report it.
            $this->notifier->rider($ride, 'ended_early', ['amount' => $amount, 'name' => $driver->name ?? '', 'distance' => number_format($away / 1000, 1)]);
        } else {
            $this->notifier->rider($ride, 'completed', ['amount' => $amount, 'name' => $driver->name ?? '']);
        }
        // Safety: "arrived safely" to whoever got the ride's link (R-113), and the check-in if asked for (R-223).
        $this->contacts->arrived($ride);
        $this->checks->scheduleCheckIn($ride);

        return $ride;
    }

    /** The driver confirms the cash, and may give the change as wallet credit (D-106). */
    public function collectCash(Driver $driver, Ride $ride, int $receivedMinor): Ride
    {
        if ($ride->driver_id !== $driver->id) {
            throw new RidesException('not_your_ride', 403);
        }
        if ($ride->payment_status !== RidePaymentStatus::CashDue) {
            throw new RidesException('nothing_to_collect', 409);
        }

        DB::transaction(function () use ($driver, $ride, $receivedMinor) {
            $change = $this->settlement->cashCollected($ride, $receivedMinor);
            $ride->forceFill(['payment_status' => RidePaymentStatus::CashCollected, 'version' => $ride->version + 1])->save();
            $this->machine->record($ride, 'cash_collected', 'driver', $driver->id, [], ['received_minor' => $receivedMinor, 'change_to_wallet_minor' => $change]);
        });
        $this->notifier->changed($ride, 'cash_collected');

        return $ride->refresh();
    }

    private function profiles(Ride $ride): void
    {
        DriverRideProfile::query()->firstOrCreate(['driver_id' => $ride->driver_id]);
        DriverRideProfile::query()->whereKey($ride->driver_id)->update(['trips_count' => DB::raw('trips_count + 1'), 'last_trip_at' => now(), 'updated_at' => now()]);
        RiderRideProfile::query()->firstOrCreate(['user_id' => $ride->user_id]);
        RiderRideProfile::query()->whereKey($ride->user_id)->update(['trips_count' => DB::raw('trips_count + 1'), 'last_trip_at' => now(), 'updated_at' => now()]);
    }

    /**
     * The phone's odometer when it agrees with the logged points; else the logged points; else the
     * planned route (or the straight line) — and where the number came from (§10.5).
     *
     * @param  list<array{0: float, 1: float}>  $track
     * @return array{0: int, 1: DistanceSource}
     */
    private function distance(Ride $ride, array $track, ?int $odometerM, float $lat, float $lng, bool $atDestination = true): array
    {
        $tracked = $this->trackDistance($track);
        $logged = count($track) - 2;
        // No trip can be shorter than the straight line from the pickup to where it ended.
        $straight = (int) round(Geo::metres($ride->origin_lat, $ride->origin_lng, $lat, $lng));

        if ($odometerM !== null && $odometerM > 0) {
            // With enough logged points the phone's counter must roughly agree with them; with
            // too few (GPS was off, D-IDEA) it only has to beat the straight line.
            $plausible = $logged >= 3
                ? $odometerM >= $tracked * 0.5 && $odometerM <= $tracked * 1.5 + 500
                : $odometerM >= $straight * 0.9;
            if ($plausible) {
                return [$odometerM, DistanceSource::DeviceOdometer];
            }
        }
        if ($logged >= 1 && $tracked > 0) {
            return [$tracked, DistanceSource::ServerTrack];
        }

        // No points at all: the planned route only when the car really is at the destination —
        // a car that never left the pickup must not be paid the whole route (O-40).
        $estimate = (int) round($straight * (int) $ride->policy('gps_gap.straight_factor_bp', 13000) / 10000);

        return [$atDestination ? max((int) $ride->planned_distance_m, $estimate) : $estimate, DistanceSource::RouteEstimate];
    }

    /**
     * How far (metres) the car is from the destination beyond `arrival.destination_radius_m`; 0 when it
     * is there, or when the ride has no fixed destination (open destination).
     */
    private function awayFromDestination(Ride $ride, float $lat, float $lng): int
    {
        if ($ride->open_destination || $ride->destination_lat === null || $ride->destination_lng === null) {
            return 0;
        }
        $distance = (int) round(Geo::metres($lat, $lng, (float) $ride->destination_lat, (float) $ride->destination_lng));

        return $distance > (int) $ride->policy('arrival.destination_radius_m', 500) ? $distance : 0;
    }

    /** An offers (bidding) ride: its price was agreed by both sides, so it is never worked out again. */
    private function isOffersRide(Ride $ride): bool
    {
        return $ride->category?->pricing_strategy === PricingStrategy::Bid;
    }

    /** @return list<array{0: float, 1: float}> the trip's logged points, pickup first, the end point last */
    private function trackPoints(Ride $ride, float $lat, float $lng): array
    {
        $points = DriverLocationLog::query()->where('ride_id', $ride->id)
            ->when($ride->started_at, fn ($q) => $q->where('recorded_at', '>=', $ride->started_at))
            ->orderBy('recorded_at')->get(['lat', 'lng'])
            ->map(fn (DriverLocationLog $p) => [(float) $p->lat, (float) $p->lng])->all();

        return [[$ride->origin_lat, $ride->origin_lng], ...$points, [$lat, $lng]];
    }

    /** @param list<array{0: float, 1: float}> $track */
    private function trackDistance(array $track): int
    {
        $sum = 0.0;
        for ($i = 1; $i < count($track); $i++) {
            $sum += Geo::metres($track[$i - 1][0], $track[$i - 1][1], $track[$i][0], $track[$i][1]);
        }

        return (int) round($sum);
    }

    private function assertDriver(Driver $driver, Ride $ride): void
    {
        if ($ride->driver_id !== $driver->id) {
            throw new RidesException('not_your_ride', 403);
        }
    }
}
