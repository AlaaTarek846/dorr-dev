<?php

namespace Modules\Rides\Services\Lifecycle;

use Illuminate\Support\Facades\DB;
use Modules\Driver\Models\Driver;
use Modules\Rides\Enums\CancelActor;
use Modules\Rides\Enums\CancelStage;
use Modules\Rides\Enums\RidePaymentStatus;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideAdjustment;
use Modules\Rides\Models\RiderRideProfile;
use Modules\Rides\Services\Dispatch\DispatchEngine;
use Modules\Rides\Services\Notifications\RideNotifier;
use Modules\Rides\Services\Payment\RidePaymentService;
use Modules\Rides\Services\Settlement\RideSettlementService;
use Modules\Rides\Services\Stats\DriverStats;
use Modules\User\Models\User;

/**
 * Cancelling (docs/rides-plan.md §6, R-100..109, D-161..175) — the fee is shown before confirming,
 * by stage:
 *  - before a driver accepted: free;
 *  - after: free for `cancel.free_after_accept_s`, then `price.cancel_fee_minor`; also when the rider
 *    already cancelled `cancel.rider_daily_free` times today (Jawad's 3 a day);
 *  - after the driver arrived: the fee. A fee paid after a driver came is the driver's (D-IDEA-07).
 * A driver may step back only before arriving — the ride searches again; after arriving it's a
 * no-show, once `no_show.wait_s` has passed.
 *
 * Booked ahead (R-105..107): free until `scheduled.free_cancel_before_s` before the time, then the
 * fee — whatever the stage.
 */
class RideCancellationService
{
    public function __construct(
        private readonly RideStateMachine $machine,
        private readonly RideNotifier $notifier,
        private readonly RidePaymentService $payments,
        private readonly RideSettlementService $settlement,
        private readonly RideCloser $closer,
        private readonly DispatchEngine $dispatch,
        private readonly DriverStats $stats,
    ) {}

    /** @return array{stage: string, fee_minor: int, free_until: ?string} */
    public function preview(User $rider, Ride $ride): array
    {
        $this->assertRider($rider, $ride);
        [$stage, $fee] = $this->riderFee($ride);
        $freeFor = (int) $ride->policy('cancel.free_after_accept_s', 120);

        $freeUntil = match (true) {
            $fee > 0 => null,
            $ride->scheduled_at !== null && $ride->freeCancelUntil()->isFuture() => $ride->freeCancelUntil(),
            $stage === CancelStage::AfterAccept => $ride->accepted_at?->copy()->addSeconds($freeFor),
            default => null,
        };

        return [
            'stage' => $stage->value,
            'fee_minor' => $fee,
            'free_until' => $freeUntil?->toIso8601String(),
            'scheduled_at' => $ride->scheduled_at?->toIso8601String(),
        ];
    }

    public function byRider(User $rider, Ride $ride, ?int $reasonId, ?string $note, ?string $key = null, bool $waiveFee = false): Ride
    {
        $this->assertRider($rider, $ride);
        if (($done = $this->machine->replayed($ride, $key, 'canceled')) !== null) {
            return $done;
        }
        [$stage, $fee] = $this->riderFee($ride);
        if ($waiveFee) {
            $fee = 0;
        }

        $ride = DB::transaction(function () use ($rider, $ride, $stage, $fee, $reasonId, $note, $key) {
            $ride = $this->machine->move($ride, RideStatus::Canceled, 'canceled', 'user', $rider->id, [
                'canceled_at' => now(),
                'cancel_actor' => CancelActor::Rider,
                'cancel_stage' => $stage,
                'cancel_reason_id' => $reasonId,
                'cancel_note' => $note,
                'next_dispatch_at' => null,
            ], [], ['fee_minor' => $fee], $key);

            $this->settleFee($ride, $fee, 'ride_cancel_fee', 'cancel_fee');
            RiderRideProfile::query()->firstOrCreate(['user_id' => $rider->id]);
            RiderRideProfile::query()->whereKey($rider->id)->increment('cancels_count');
            $this->closer->close($ride);

            return $ride;
        });

        $this->notifier->changed($ride, 'canceled');
        // R-155: a cancellation after a captain accepted fails the «no cancellations» challenges.
        if ($ride->accepted_at !== null) {
            app(\Modules\Rides\Services\Loyalty\LoyaltyService::class)->riderCancelled($rider);
        }
        if ($ride->driver_id !== null) {
            $this->notifier->driver($ride->driver, $ride, 'rider_canceled');
        } elseif ($ride->reserved_driver_id !== null && ($reserved = Driver::query()->find($ride->reserved_driver_id)) !== null) {
            $this->notifier->driver($reserved, $ride, 'rider_canceled');
        }

        return $ride;
    }

    /** The driver steps back before arriving (an allowed reason): the ride searches again, without them. */
    public function byDriver(Driver $driver, Ride $ride, ?int $reasonId, ?string $note, ?string $key = null): Ride
    {
        if ($ride->driver_id !== $driver->id) {
            throw new RidesException('not_your_ride', 403);
        }
        if (($done = $this->machine->replayed($ride, $key, 'driver_released')) !== null) {
            return $done;
        }
        if ($ride->status === RideStatus::Arrived) {
            throw new RidesException('use_no_show', 409);
        }

        $ride = DB::transaction(function () use ($driver, $ride, $reasonId, $note, $key) {
            $ride = $this->machine->move($ride, RideStatus::Searching, 'driver_released', 'driver', $driver->id, [
                'driver_id' => null,
                'vehicle_id' => null,
                'vehicle_snapshot' => null,
                'pin' => null,
                'pin_attempts' => 0,
                'accepted_at' => null,
                'next_dispatch_at' => now(),
            ], [], ['reason_id' => $reasonId, 'note' => $note], $key);
            $this->closer->releaseDriver($ride, $driver);

            return $ride;
        });

        $this->stats->bump($driver->id, ['cancels' => 1]);
        $this->notifier->changed($ride, 'searching_again');
        $this->notifier->rider($ride, 'searching_again');
        $this->dispatch->runRound($ride);

        return $ride->refresh();
    }

    /** The rider never came (D-165): only after waiting `no_show.wait_s` at the pickup. */
    public function noShow(Driver $driver, Ride $ride, ?string $key = null): Ride
    {
        if ($ride->driver_id !== $driver->id) {
            throw new RidesException('not_your_ride', 403);
        }
        if (($done = $this->machine->replayed($ride, $key, 'no_show')) !== null) {
            return $done;
        }
        $wait = (int) $ride->policy('no_show.wait_s', 300);
        // A scheduled ride's waiting starts at its time, not at an early arrival (R-108).
        $waited = $ride->waitedSeconds();
        if ($ride->status !== RideStatus::Arrived || $waited < $wait) {
            throw new RidesException('no_show_too_early', 409, [], ['seconds_left' => max(0, $wait - $waited)]);
        }
        $fee = (int) $ride->policy('price.cancel_fee_minor', 0);

        $ride = DB::transaction(function () use ($driver, $ride, $fee, $key) {
            $ride = $this->machine->move($ride, RideStatus::NoShow, 'no_show', 'driver', $driver->id, [
                'canceled_at' => now(),
                'cancel_actor' => CancelActor::Driver,
                'cancel_stage' => CancelStage::AfterArrival,
            ], [], ['fee_minor' => $fee, 'waited_s' => $ride->waitedSeconds()], $key);
            $this->settleFee($ride, $fee, 'ride_cancel_fee', 'no_show_fee');
            RiderRideProfile::query()->firstOrCreate(['user_id' => $ride->user_id]);
            RiderRideProfile::query()->whereKey($ride->user_id)->increment('no_show_count');
            $this->closer->close($ride);

            return $ride;
        });

        $this->notifier->changed($ride, 'no_show');
        $this->notifier->rider($ride, 'canceled');

        return $ride;
    }

    /** @return array{0: CancelStage, 1: int} */
    private function riderFee(Ride $ride): array
    {
        $stage = match ($ride->status) {
            RideStatus::Searching, RideStatus::Bidding, RideStatus::AwaitingPayment, RideStatus::Scheduled => CancelStage::BeforeAccept,
            RideStatus::Accepted => CancelStage::AfterAccept,
            RideStatus::Arrived => CancelStage::AfterArrival,
            default => throw new RidesException('cannot_cancel', 409, ['status' => $ride->status->value], ['status' => $ride->status->value]),
        };
        $fee = (int) $ride->policy('price.cancel_fee_minor', 0);
        // A booking ahead: past its free window the fee applies, captain or not (R-107).
        if ($ride->scheduled_at !== null && $stage !== CancelStage::AfterArrival) {
            return [$stage, $ride->freeCancelUntil()->isFuture() ? 0 : $fee];
        }
        if ($stage === CancelStage::BeforeAccept) {
            return [$stage, 0];
        }

        if ($stage === CancelStage::AfterArrival) {
            return [$stage, $fee];
        }

        $withinFree = $ride->accepted_at !== null && $ride->accepted_at->diffInSeconds(now()) <= (int) $ride->policy('cancel.free_after_accept_s', 120);
        $todayCancels = Ride::query()->where('user_id', $ride->user_id)->where('cancel_actor', CancelActor::Rider->value)
            ->where('canceled_at', '>=', now()->startOfDay())->count();
        $overDailyFree = $todayCancels >= (int) $ride->policy('cancel.rider_daily_free', 3);

        return [$stage, ($withinFree && ! $overDailyFree) ? 0 : $fee];
    }

    private function settleFee(Ride $ride, int $fee, string $noteKey, string $adjustment): void
    {
        // A cash ride paid partly from the wallet (O-37) holds money too: the fee comes from it, the rest goes back.
        if ($ride->payment_method->holdsInWallet() || $this->payments->held($ride) > 0) {
            $fee > 0 ? $this->payments->capture($ride, $fee, $noteKey) : $this->payments->releaseAll($ride, 'ride_canceled');
            $ride->forceFill(['payment_status' => $fee > 0 ? RidePaymentStatus::Captured : RidePaymentStatus::Released])->save();
        } else {
            $this->payments->chargeDebt($ride, $fee, $noteKey);
        }

        if ($fee > 0) {
            RideAdjustment::query()->create([
                'ride_id' => $ride->id, 'type' => $adjustment, 'amount_minor' => $fee, 'affects' => 'rider',
                'reason_key' => 'rides.adjustments.'.$adjustment, 'actor_type' => 'system', 'created_at' => now(),
            ]);
            $this->settlement->cancelCompensation($ride, $fee);
        }
    }

    private function assertRider(User $rider, Ride $ride): void
    {
        if ($ride->user_id !== $rider->id) {
            throw new RidesException('ride_not_found', 404);
        }
    }
}
