<?php

namespace Modules\Rides\Services\Scheduling;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Driver\Enums\PresenceStatus;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverPresence;
use Modules\Rides\Enums\RidePaymentStatus;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideActiveLock;
use Modules\Rides\Models\RideBlock;
use Modules\Rides\Services\Dispatch\DispatchEngine;
use Modules\Rides\Services\Drivers\DriverEligibility;
use Modules\Rides\Services\Lifecycle\RideCloser;
use Modules\Rides\Services\Lifecycle\RideStateMachine;
use Modules\Rides\Services\Notifications\RideNotifier;
use Modules\Rides\Services\Payment\RidePaymentService;
use Modules\Rides\Services\Stats\DriverStats;
use Modules\Rides\Support\Geo;

/**
 * Rides booked ahead (R-12, R-105..108, R-170, D-260..269).
 *
 *  - A booked ride waits as `scheduled`. A captain may reserve it from the list of upcoming rides
 *    near them (D-260, D-264) — never two bookings on top of each other (D-267) — and give it back;
 *    late, that counts as a cancel (D-169).
 *  - `scheduled.reminder_before_s` before the time, the rider and the reserved captain are reminded
 *    once (R-170, D-266).
 *  - From `scheduled.driver_start_before_s` before, the reserved captain may head to the pickup
 *    (D-268); at `scheduled.lead_time_s` before, the ride starts by itself: to the reserved captain
 *    when they're online and free, else a normal search (and the reservation is dropped).
 *  - The rider still on another ride then: retried every minute, and missed after
 *    `scheduled.start_grace_s` past the time (nothing charged).
 * The waiting at the pickup never starts before the time (Ride::waitingFrom, R-108).
 */
class ScheduledRideService
{
    public function __construct(
        private readonly RideStateMachine $machine,
        private readonly DispatchEngine $dispatch,
        private readonly DriverEligibility $eligibility,
        private readonly RideNotifier $notifier,
        private readonly RidePaymentService $payments,
        private readonly RideCloser $closer,
        private readonly DriverStats $stats,
    ) {}

    /** "2026-10-26 09:30" in the ride's local time — for messages, the same in every language. */
    public static function localTime(Ride $ride): string
    {
        return $ride->scheduled_at === null ? '' : $ride->scheduled_at->copy()
            ->setTimezone((string) config('rides.default_timezone', 'Asia/Riyadh'))->format('Y-m-d H:i');
    }

    /** Every minute (with the dispatch tick): reminders, then the rides whose time has come. */
    public function tick(): int
    {
        $this->remind();

        $due = Ride::query()->where('status', RideStatus::Scheduled->value)->where('next_dispatch_at', '<=', now())->pluck('id');
        foreach ($due as $id) {
            $this->begin(Ride::query()->findOrFail($id));
        }

        return $due->count();
    }

    public function remind(): void
    {
        Ride::query()->where('status', RideStatus::Scheduled->value)->whereNull('reminded_at')->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now()->addDay())->get()
            ->filter(fn (Ride $ride) => $ride->scheduled_at->copy()->subSeconds((int) $ride->policy('scheduled.reminder_before_s', 3600))->lessThanOrEqualTo(now()))
            ->each(function (Ride $ride) {
                if (Ride::query()->whereKey($ride->id)->whereNull('reminded_at')->update(['reminded_at' => now()]) === 0) {
                    return;
                }
                $this->notifier->rider($ride, 'scheduled_reminder', ['time' => self::localTime($ride)]);
                if ($ride->reserved_driver_id !== null && ($driver = Driver::query()->find($ride->reserved_driver_id)) !== null) {
                    $this->notifier->driver($driver, $ride, 'scheduled_driver_reminder', ['time' => self::localTime($ride)]);
                }
            });
    }

    /**
     * The ride's time has come (or its captain heads out early): the rider's lock, then the reserved
     * captain — or a normal search. `$by` = the captain asked for it; they get the error instead.
     */
    public function begin(Ride $ride, ?Driver $by = null, ?string $key = null): Ride
    {
        $dropped = null;
        $assigned = null;

        try {
            $ride = DB::transaction(function () use ($ride, $by, $key, &$dropped, &$assigned) {
                $ride = Ride::query()->whereKey($ride->id)->lockForUpdate()->firstOrFail();
                if ($ride->status !== RideStatus::Scheduled) {
                    return $ride;
                }
                RideActiveLock::query()->create(['lock_key' => RideActiveLock::forRider($ride->user_id), 'ride_id' => $ride->id]);

                $reserved = $ride->reserved_driver_id === null ? null : Driver::query()->find($ride->reserved_driver_id);
                if ($reserved !== null) {
                    $problem = $this->problemFor($reserved, $ride, true);
                    if ($problem === null) {
                        try {
                            $ride = DB::transaction(fn () => $this->dispatch->assign($reserved, $ride, null, null, $key));
                            $ride->forceFill(['search_started_at' => now()])->save();
                            $assigned = $reserved;

                            return $ride;
                        } catch (RidesException $e) {
                            $problem = $e->errorCode;
                        }
                    }
                    if ($by !== null) {
                        throw new RidesException($problem === 'driver_busy' ? 'driver_busy' : 'reservation_not_eligible', 409, [], ['reason' => $problem]);
                    }
                    $dropped = $reserved;
                    $this->machine->record($ride, 'reservation_dropped', 'system', null, [], ['driver_id' => $reserved->id, 'reason' => $problem]);
                }

                return $this->machine->move($ride, RideStatus::Searching, 'search_started', 'system', null, [
                    'reserved_driver_id' => null,
                    'reserved_at' => null,
                    'search_started_at' => now(),
                    'next_dispatch_at' => now(),
                ]);
            });
        } catch (QueryException) {
            // The rider is still on another ride.
            if ($by !== null) {
                throw new RidesException('rider_busy', 409);
            }

            return $this->retryOrMiss($ride);
        }

        if ($assigned !== null) {
            $this->dispatch->announceAccepted($ride, $assigned);
            $this->notifier->driver($assigned, $ride, 'scheduled_go', ['time' => self::localTime($ride)]);

            return $ride;
        }
        if ($ride->status !== RideStatus::Searching) {
            return $ride;
        }
        if ($dropped !== null) {
            $this->stats->bump($dropped->id, ['cancels' => 1]);
            $this->notifier->driver($dropped, $ride, 'reservation_dropped');
        }
        $this->notifier->changed($ride, 'search_started');
        $this->notifier->rider($ride, 'scheduled_searching');
        $this->dispatch->runRound($ride);

        return $ride->refresh();
    }

    /**
     * Upcoming rides a captain may reserve (D-260..263): booked, nobody on them yet, within the
     * search radius of where the captain is, in a category they may and want to drive.
     *
     * @return Collection<int, Ride>
     */
    public function available(Driver $driver): Collection
    {
        $presence = DriverPresence::query()->find($driver->id);
        $vehicle = $this->eligibility->activeVehicle($driver);
        if ($presence?->lat === null || $vehicle === null || $this->eligibility->reasonNotToWork($driver) !== null
            || ! app(\Modules\Rides\Services\Drivers\DriverServices::class)->offers($driver, 'scheduled')) {
            return collect();
        }
        $radius = (int) (config('rides.policy_defaults')['search.max_radius_m'] ?? 50000);
        [$minLat, $maxLat, $minLng, $maxLng] = Geo::boxAround((float) $presence->lat, (float) $presence->lng, $radius);
        $blocked = $this->blockedRiders($driver);

        return Ride::query()->where('status', RideStatus::Scheduled->value)->whereNull('reserved_driver_id')
            ->where('scheduled_at', '>', now())
            ->whereBetween('origin_lat', [$minLat, $maxLat])->whereBetween('origin_lng', [$minLng, $maxLng])
            ->whereNotIn('user_id', $blocked)
            ->with(['category.translations', 'currency', 'stops', 'rider'])
            ->orderBy('scheduled_at')->limit(100)->get()
            ->filter(fn (Ride $ride) => $ride->rider?->phone !== $driver->phone
                && $this->eligibility->categoryProblem($driver, $vehicle, $ride->category) === null
                && $this->eligibility->wants($driver, $ride->category))
            ->each(fn (Ride $ride) => $ride->setAttribute('pickup_distance_m', (int) round(Geo::metres((float) $presence->lat, (float) $presence->lng, $ride->origin_lat, $ride->origin_lng))))
            ->take(50)->values();
    }

    /** @return Collection<int, Ride> the captain's own upcoming reservations (D-265) */
    public function mine(Driver $driver): Collection
    {
        return Ride::query()->where('status', RideStatus::Scheduled->value)->where('reserved_driver_id', $driver->id)
            ->with(['category.translations', 'currency', 'stops', 'rider'])->orderBy('scheduled_at')->get();
    }

    /** The captain takes a booked ride ahead of time (D-264). */
    public function reserve(Driver $driver, string $uuid, ?string $key = null): Ride
    {
        // O-89: scheduled rides only where they are open to the captain's vehicle and switched on.
        app(\Modules\Rides\Services\Drivers\DriverServices::class)->assertOffers($driver, 'scheduled');
        $ride = DB::transaction(function () use ($driver, $uuid, $key) {
            $ride = Ride::query()->where('uuid', $uuid)->lockForUpdate()->first() ?? throw new RidesException('ride_not_found', 404);
            if ($ride->reserved_driver_id === $driver->id && $ride->status === RideStatus::Scheduled) {
                return $ride;
            }
            if ($ride->status !== RideStatus::Scheduled || $ride->scheduled_at === null || $ride->scheduled_at->isPast()) {
                throw new RidesException('reservation_unavailable', 409);
            }
            if ($ride->reserved_driver_id !== null) {
                throw new RidesException('reservation_taken', 409);
            }
            if (($problem = $this->problemFor($driver, $ride, false)) !== null) {
                throw new RidesException('reservation_not_eligible', 422, [], ['reason' => $problem]);
            }
            $gap = (int) $ride->policy('scheduled.overlap_gap_s', 1800);
            foreach ($this->mine($driver) as $other) {
                $apart = abs($other->scheduled_at->diffInSeconds($ride->scheduled_at, false));
                if ($apart < max((int) $other->planned_duration_s, (int) $ride->planned_duration_s) + $gap) {
                    throw new RidesException('reservation_overlap', 409, ['time' => self::localTime($other)]);
                }
            }

            $ride->forceFill(['reserved_driver_id' => $driver->id, 'reserved_at' => now()])->save();
            $this->machine->record($ride, 'reserved', 'driver', $driver->id, [], [], null, null, $key);

            return $ride;
        });

        $this->notifier->changed($ride, 'reserved');
        $this->notifier->rider($ride, 'scheduled_reserved', ['name' => $driver->name ?? '', 'time' => self::localTime($ride)]);

        return $ride;
    }

    /** Would giving it back now count as a cancel (D-170 — shown before confirming)? */
    public static function releaseCounts(Ride $ride): bool
    {
        return $ride->scheduled_at !== null
            && now()->greaterThanOrEqualTo($ride->scheduled_at->copy()->subSeconds((int) $ride->policy('scheduled.driver_free_release_before_s', 7200)));
    }

    /** May the captain head to the pickup now (D-268)? */
    public static function startsAt(Ride $ride): ?Carbon
    {
        return $ride->scheduled_at?->copy()->subSeconds((int) $ride->policy('scheduled.driver_start_before_s', 3600));
    }

    /** The captain gives the reservation back (D-169): the ride waits for another captain. */
    public function release(Driver $driver, string $uuid, ?string $note = null): Ride
    {
        $late = false;
        $ride = DB::transaction(function () use ($driver, $uuid, $note, &$late) {
            $ride = Ride::query()->where('uuid', $uuid)->where('reserved_driver_id', $driver->id)->lockForUpdate()->first()
                ?? throw new RidesException('ride_not_found', 404);
            if ($ride->status !== RideStatus::Scheduled) {
                throw new RidesException('invalid_state', 409, ['status' => $ride->status->value], ['status' => $ride->status->value]);
            }
            $late = self::releaseCounts($ride);
            $ride->forceFill(['reserved_driver_id' => null, 'reserved_at' => null])->save();
            $this->machine->record($ride, 'reservation_released', 'driver', $driver->id, [], array_filter(['late' => $late, 'note' => $note]));

            return $ride;
        });

        if ($late) {
            $this->stats->bump($driver->id, ['cancels' => 1]);
        }
        $this->notifier->changed($ride, 'reservation_released');
        $this->notifier->rider($ride, 'scheduled_driver_released', ['time' => self::localTime($ride)]);

        return $ride;
    }

    /** The reserved captain heads to the pickup now (D-268): the ride becomes theirs (accepted). */
    public function startHeading(Driver $driver, string $uuid, ?string $key = null): Ride
    {
        $ride = Ride::query()->where('uuid', $uuid)->first() ?? throw new RidesException('ride_not_found', 404);
        if ($ride->driver_id === $driver->id && $ride->status->hasDriver()) {
            return $ride;
        }
        if ($ride->reserved_driver_id !== $driver->id || $ride->status !== RideStatus::Scheduled) {
            throw new RidesException('not_your_ride', 403);
        }
        $from = self::startsAt($ride);
        if ($from !== null && $from->isFuture()) {
            throw new RidesException('start_too_early', 409, ['time' => $from->copy()->setTimezone((string) config('rides.default_timezone', 'Asia/Riyadh'))->format('H:i')], ['starts_at' => $from->toIso8601String()]);
        }

        return $this->begin($ride, $driver, $key);
    }

    /**
     * Why this captain can't take this ride, or null. `$now`: also online, seen lately and free —
     * what starting it needs; a reservation days ahead only needs the account, car and category.
     */
    private function problemFor(Driver $driver, Ride $ride, bool $now): ?string
    {
        if (($reason = $this->eligibility->reasonNotToWork($driver)) !== null) {
            return $reason;
        }
        $vehicle = $this->eligibility->activeVehicle($driver);
        $ride->loadMissing('category', 'rider');
        if ($vehicle === null || ($problem = $this->eligibility->categoryProblem($driver, $vehicle, $ride->category)) !== null) {
            return $problem ?? 'no_active_vehicle';
        }
        if ($ride->rider?->phone === $driver->phone || $this->blockedRiders($driver)->contains($ride->user_id)) {
            return 'blocked';
        }
        if (! $now) {
            return null;
        }
        $presence = DriverPresence::query()->find($driver->id);
        if ($presence === null || $presence->status !== PresenceStatus::Online
            || $presence->last_seen_at === null || $presence->last_seen_at->lt(now()->subSeconds((int) $ride->policy('driver.stale_after_s', 900)))) {
            return 'offline';
        }

        return RideActiveLock::query()->whereKey(RideActiveLock::forDriver($driver->id))->exists() ? 'driver_busy' : null;
    }

    /** @return Collection<int, int> riders this captain blocked, or who blocked them */
    private function blockedRiders(Driver $driver): Collection
    {
        return RideBlock::query()->where('blocker_type', 'driver')->where('blocker_id', $driver->id)->where('blocked_type', 'user')->pluck('blocked_id')
            ->merge(RideBlock::query()->where('blocker_type', 'user')->where('blocked_type', 'driver')->where('blocked_id', $driver->id)->pluck('blocker_id'))
            ->map(fn ($id) => (int) $id)->unique()->values();
    }

    /** The rider was still on another ride at the time: try again in a minute, or give up (nothing charged). */
    private function retryOrMiss(Ride $ride): Ride
    {
        $ride->refresh();
        if ($ride->scheduled_at !== null && $ride->scheduled_at->copy()->addSeconds((int) $ride->policy('scheduled.start_grace_s', 900))->isFuture()) {
            $ride->forceFill(['next_dispatch_at' => now()->addMinute()])->save();

            return $ride;
        }

        $ride = DB::transaction(function () use ($ride) {
            $ride = $this->machine->move($ride, RideStatus::Expired, 'scheduled_missed', 'system', null, ['expired_at' => now(), 'next_dispatch_at' => null]);
            $this->payments->releaseAll($ride, 'ride_expired');
            $ride->forceFill(['payment_status' => $ride->payment_method->holdsInWallet() ? RidePaymentStatus::Released : $ride->payment_status])->save();
            $this->closer->close($ride);

            return $ride;
        });
        $this->notifier->changed($ride, 'expired');
        $this->notifier->rider($ride, 'scheduled_missed');
        if ($ride->reserved_driver_id !== null && ($driver = Driver::query()->find($ride->reserved_driver_id)) !== null) {
            $this->notifier->driver($driver, $ride, 'rider_canceled');
        }

        return $ride;
    }
}
