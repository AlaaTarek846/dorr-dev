<?php

namespace Modules\Rides\Services\Scheduling;

use Modules\Driver\Enums\PresenceStatus;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverPresence;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Models\Ride;
use Modules\Rides\Services\Geo\LearnedEta;
use Modules\Rides\Services\Geo\RideProgress;
use Modules\Rides\Services\Lifecycle\RideStateMachine;
use Modules\Rides\Services\Notifications\RideNotifier;
use Modules\Rides\Support\Geo;

/**
 * «ضمان موعد السيارة» (Block 06 phase 3): a ride booked for a time is watched from
 * `scheduled.watch_before_s` before it. When the captain looks likely to come more than
 * `scheduled.late_alert_s` after the time — far from the pickup, not online yet, or nobody took it — the
 * rider is warned early, once, with roughly how late and why (and the captain is told too). The estimate
 * keeps updating on the ride (`late_risk`) and goes back to "on time" when it is. Never a promise of
 * arriving: the wording says "may", and nothing is charged or refunded here.
 */
class LateRisk
{
    public function __construct(private readonly RideNotifier $notifier, private readonly RideStateMachine $machine) {}

    public function tick(): int
    {
        $defaults = (array) config('rides.policy_defaults');
        $horizon = (int) ($defaults['scheduled.watch_before_s'] ?? 2700);
        $alerted = 0;
        $rides = Ride::query()->whereNotNull('scheduled_at')
            ->whereIn('status', [RideStatus::Scheduled->value, RideStatus::Searching->value, RideStatus::Accepted->value])
            ->whereBetween('scheduled_at', [now()->subMinutes(30), now()->addSeconds($horizon)])
            ->limit(200)->get();
        foreach ($rides as $ride) {
            $alerted += $this->check($ride) ? 1 : 0;
        }

        return $alerted;
    }

    /** True when the rider was warned just now. */
    public function check(Ride $ride): bool
    {
        $watch = (int) $ride->policy('scheduled.watch_before_s', 2700);
        $threshold = (int) $ride->policy('scheduled.late_alert_s', 300);
        if ($watch <= 0 || $ride->scheduled_at === null || now()->lt($ride->scheduled_at->copy()->subSeconds($watch))) {
            return false;
        }
        [$etaS, $reason] = $this->estimate($ride);
        if ($etaS === null) {
            return false;
        }
        $arrival = now()->addSeconds($etaS);
        $late = (int) max(0, $ride->scheduled_at->diffInSeconds($arrival, false));
        $previous = (array) ($ride->late_risk ?? []);
        $risk = $late > $threshold;
        $state = [
            'late_s' => $late,
            'reason' => $risk ? $reason : null,
            'eta_s' => $etaS,
            'at' => now()->toIso8601String(),
            'alerted_at' => $previous['alerted_at'] ?? null,
        ];

        $warn = $risk && empty($previous['alerted_at']);
        if ($warn) {
            $state['alerted_at'] = now()->toIso8601String();
        }
        $ride->forceFill(['late_risk' => $state])->save();

        if ($warn) {
            $minutes = (int) ceil($late / 60);
            $this->machine->record($ride, 'late_risk', 'system', null, [], ['late_s' => $late, 'reason' => $reason, 'eta_s' => $etaS]);
            $this->notifier->rider($ride, 'late_risk', ['minutes' => $minutes, 'reason' => __('rides.late_reasons.'.$reason)]);
            $driverId = $ride->driver_id ?? $ride->reserved_driver_id;
            if ($driverId !== null && ($driver = Driver::query()->find($driverId)) !== null) {
                $this->notifier->driver($driver, $ride, 'late_risk_driver', ['minutes' => $minutes]);
            }
            $this->notifier->changed($ride, 'late_risk');
        } elseif (($previous['late_s'] ?? 0) > $threshold && ! $risk) {
            $this->notifier->changed($ride, 'late_risk'); // back on time: the banner goes
        }

        return $warn;
    }

    /**
     * How long until a captain can be at the pickup, and what holds them up.
     *
     * @return array{0: ?int, 1: string}
     */
    private function estimate(Ride $ride): array
    {
        if ($ride->status === RideStatus::Accepted) {
            $progress = RideProgress::of($ride);

            return [$progress['eta_s'] ?? null, 'driver_far'];
        }
        $driverId = $ride->reserved_driver_id;
        if ($ride->status === RideStatus::Scheduled && $driverId !== null) {
            $presence = DriverPresence::query()->find($driverId);
            if ($presence === null || $presence->lat === null || ! in_array($presence->status, [PresenceStatus::Online, PresenceStatus::OnTrip], true)) {
                // Not online yet: a risk only once the time is close enough that they should be on the way —
                // then counted as late past the warning line.
                $left = (int) now()->diffInSeconds($ride->scheduled_at, false);
                $threshold = (int) $ride->policy('scheduled.late_alert_s', 300);
                if ($left > $threshold * 2) {
                    return [null, 'driver_offline'];
                }

                return [max(0, $left) + $threshold + 60, 'driver_offline'];
            }
            $speed = LearnedEta::speedKmh($ride) / 3.6;
            $metres = Geo::metres((float) $presence->lat, (float) $presence->lng, $ride->origin_lat, $ride->origin_lng) * (int) $ride->policy('gps_gap.straight_factor_bp', 13000) / 10000;

            return [(int) ceil($metres / $speed / 60) * 60, 'driver_far'];
        }
        if ($ride->status === RideStatus::Searching || ($ride->status === RideStatus::Scheduled && $driverId === null)) {
            // Nobody has it yet: a captain needs time to come once found.
            $left = (int) now()->diffInSeconds($ride->scheduled_at, false);
            $typical = (int) $ride->policy('scheduled.typical_pickup_s', 600);

            return [$left <= $typical ? $typical : null, 'no_captain'];
        }

        return [null, 'driver_far'];
    }

    /**
     * For the rider: may be late by about this much, and why (null while on time / not watched).
     *
     * @return array{late_s: int, minutes: int, reason: ?string, reason_label: ?string, at: ?string}|null
     */
    public static function present(Ride $ride): ?array
    {
        $risk = $ride->late_risk;
        if (! is_array($risk) || empty($risk['reason']) || (int) ($risk['late_s'] ?? 0) <= 0
            || ! in_array($ride->status, [RideStatus::Scheduled, RideStatus::Searching, RideStatus::Accepted], true)) {
            return null;
        }

        return [
            'late_s' => (int) $risk['late_s'],
            'minutes' => (int) ceil(((int) $risk['late_s']) / 60),
            'reason' => $risk['reason'],
            'reason_label' => __('rides.late_reasons.'.$risk['reason']),
            'at' => $risk['at'] ?? null,
        ];
    }
}
