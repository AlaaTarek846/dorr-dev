<?php

namespace Modules\Rides\Services\Lifecycle;

use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Models\Ride;
use Modules\Rides\Services\Notifications\RideNotifier;

/**
 * "The free waiting ends soon" (rides_tasks D-122): `waiting.warn_before_s` before the free minutes at
 * the pickup run out, the rider (paid minutes are coming) and the captain hear it — once per ride.
 * A booked ride's waiting starts at its time, so the warning does too (R-108). Run by
 * `rides:dispatch-tick` every 10 seconds.
 */
class WaitingAlerts
{
    public function __construct(private readonly RideNotifier $notifier) {}

    public function tick(): int
    {
        $sent = 0;
        $rides = Ride::query()->where('status', RideStatus::Arrived->value)->whereNull('wait_warned_at')->whereNotNull('arrived_at')
            ->with(['driver', 'rider', 'currency'])->get();

        foreach ($rides as $ride) {
            $from = $ride->waitingFrom();
            if ($from === null || $from->isFuture()) {
                continue;
            }
            $free = (int) $ride->policy('waiting.free_s', 180);
            $warn = (int) $ride->policy('waiting.warn_before_s', config('rides.policy_defaults')['waiting.warn_before_s'] ?? 60);
            $endsAt = $from->copy()->addSeconds($free);
            if ($warn <= 0 || now()->lt($endsAt->copy()->subSeconds($warn)) || now()->gte($endsAt)) {
                continue;
            }
            // Claimed first, so two ticks never warn twice.
            if (Ride::query()->whereKey($ride->id)->whereNull('wait_warned_at')->update(['wait_warned_at' => now()]) === 0) {
                continue;
            }

            $left = max(1, (int) ceil(now()->diffInSeconds($endsAt) / 60));
            $perMinute = $this->notifier->money((int) $ride->policy('price.waiting_per_min_minor', 0), $ride);
            $this->notifier->changed($ride, 'free_wait_ending');
            $this->notifier->rider($ride, 'free_wait_ending', ['minutes' => $left, 'price' => $perMinute]);
            if ($ride->driver !== null) {
                $this->notifier->driver($ride->driver, $ride, 'free_wait_ending_driver', ['minutes' => $left]);
            }
            $sent++;
        }

        return $sent;
    }
}
