<?php

namespace Modules\Rides\Services\Ai;

use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RiderRideProfile;
use Modules\Rides\Services\Geo\RideProgress;
use Modules\Rides\Services\Lifecycle\RideStateMachine;
use Modules\Rides\Services\Notifications\RideNotifier;

/**
 * AI-10 — how likely a ride still waiting for its captain is to be cancelled (0–100, `rides.cancel_risk`),
 * from what the system already knows, and one fitting step before it happens:
 *
 *  - searching: how long the search has gone on against its limit, and how often this rider cancels;
 *  - accepted: how far the captain still is, how long since they took it, and the rider's habit.
 *
 * At `cancel_risk.nudge_at` (70) on an accepted ride the rider gets one honest update — where the captain
 * is and about when they arrive — instead of being left wondering (event `cancel_risk_nudged`). The score
 * is shown to the team on the ride page. Explainable on purpose: no model decides anything here.
 */
class CancelRisk
{
    public function __construct(private readonly RideNotifier $notifier, private readonly RideStateMachine $machine) {}

    public function tick(): int
    {
        $nudged = 0;
        $rides = Ride::query()->whereIn('status', [RideStatus::Searching->value, RideStatus::Accepted->value])->limit(300)->get();
        foreach ($rides as $ride) {
            $score = $this->score($ride);
            if ($score !== $ride->cancel_risk) {
                $ride->forceFill(['cancel_risk' => $score])->save();
            }
            $nudged += $this->nudge($ride, $score) ? 1 : 0;
        }

        return $nudged;
    }

    public function score(Ride $ride): int
    {
        $profile = RiderRideProfile::query()->find($ride->user_id);
        $done = (int) ($profile?->trips_count ?? 0) + (int) ($profile?->cancels_count ?? 0);
        // The rider's own habit, only once there is enough of it to mean something.
        $habit = $done >= 5 ? (int) ($profile->cancels_count ?? 0) / $done : 0.0;

        if ($ride->status === RideStatus::Searching) {
            $searching = (int) ($ride->searchingSince()?->diffInSeconds(now()) ?? 0);
            $limit = max(60, (int) $ride->policy('search.max_search_s', 600));

            return (int) min(100, round(min(1, $searching / $limit) * 70 + $habit * 30));
        }

        $progress = RideProgress::of($ride);
        $eta = (int) ($progress['eta_s'] ?? 0);
        $since = (int) ($ride->accepted_at?->diffInSeconds(now()) ?? 0);

        return (int) min(100, round(min(1, $eta / 900) * 45 + min(1, $since / 900) * 25 + $habit * 30));
    }

    private function nudge(Ride $ride, int $score): bool
    {
        $at = (int) $ride->policy('cancel_risk.nudge_at', 70);
        if ($at <= 0 || $score < $at || $ride->status !== RideStatus::Accepted || $ride->events()->where('type', 'cancel_risk_nudged')->exists()) {
            return false;
        }
        $progress = RideProgress::of($ride);
        if ($progress === null) {
            return false;
        }
        $this->machine->record($ride, 'cancel_risk_nudged', 'system', null, [], ['score' => $score, 'eta_s' => $progress['eta_s']]);
        $this->notifier->rider($ride, 'eta_update', ['minutes' => max(1, (int) ceil($progress['eta_s'] / 60)), 'km' => number_format($progress['distance_m'] / 1000, 1)]);

        return true;
    }
}
