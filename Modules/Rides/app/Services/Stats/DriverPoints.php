<?php

namespace Modules\Rides\Services\Stats;

use Illuminate\Support\Facades\DB;
use Modules\Rides\Models\DriverPointsEntry;
use Modules\Rides\Models\DriverPointsRule;
use Modules\Rides\Models\DriverRideProfile;
use Modules\Rides\Models\Ride;

/**
 * Points from the admin's rules (docs/rides-plan.md §17.7): a completed trip, an on-time pickup
 * (LeeTaxi's distance_points idea — minutes late past the promised arrival, D-NEW-17), five stars.
 * Every change is a ledger row with the balance after it.
 */
class DriverPoints
{
    public function __construct(private readonly DriverStats $stats) {}

    public function forCompletedRide(Ride $ride, int $promisedArrivalS): void
    {
        $this->award($ride->driver_id, 'trip_completed', $ride);

        if ($ride->accepted_at !== null && $ride->arrived_at !== null) {
            $minutesLate = max(0, intdiv($ride->accepted_at->diffInSeconds($ride->arrived_at) - $promisedArrivalS, 60));
            $this->award($ride->driver_id, 'on_time_pickup', $ride, $minutesLate);
        }
    }

    public function award(int $driverId, string $event, ?Ride $ride = null, ?int $minutesLate = null): int
    {
        $countryId = $ride?->country_id;
        $rule = DriverPointsRule::query()->where('event', $event)->where('status', true)
            ->where(fn ($q) => $q->whereNull('country_id')->when($countryId, fn ($q) => $q->orWhere('country_id', $countryId)))
            ->orderByRaw('country_id is null')   // a country's own rule first
            ->first();
        if ($rule === null) {
            return 0;
        }

        $points = $rule->points;
        if ($minutesLate !== null && is_array($rule->conditions)) {
            $points = 0;
            foreach (collect($rule->conditions)->sortBy('max_minutes_late') as $step) {
                if ($minutesLate <= (int) ($step['max_minutes_late'] ?? -1)) {
                    $points = (int) ($step['points'] ?? 0);
                    break;
                }
            }
        }
        if ($points === 0) {
            return 0;
        }

        DB::transaction(function () use ($driverId, $points, $event, $rule, $ride) {
            DriverRideProfile::query()->firstOrCreate(['driver_id' => $driverId]);
            $profile = DriverRideProfile::query()->whereKey($driverId)->lockForUpdate()->firstOrFail();
            $profile->points_balance += $points;
            $profile->save();

            DriverPointsEntry::query()->create([
                'driver_id' => $driverId,
                'points' => $points,
                'balance_after' => $profile->points_balance,
                'event' => $event,
                'rule_id' => $rule->id,
                'ride_id' => $ride?->id,
                'created_at' => now(),
            ]);
        });
        $this->stats->bump($driverId, ['points' => $points]);

        return $points;
    }
}
