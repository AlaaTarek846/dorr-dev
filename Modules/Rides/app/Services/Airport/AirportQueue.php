<?php

namespace Modules\Rides\Services\Airport;

use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Driver\Enums\PresenceStatus;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverPresence;
use Modules\Rides\Enums\ZoneType;
use Modules\Rides\Events\DriverChanged;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideActiveLock;
use Modules\Rides\Models\RideQueueEntry;
use Modules\Rides\Models\RideZone;
use Modules\Rides\Services\Drivers\DriverEligibility;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\PickupPoints;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\Rides\Services\Policy\RidePolicyData;
use Throwable;

/**
 * The airport queue (D-276..D-280), where the market switched it on (`airport_queue`) and the zone has
 * `queue_enabled`: captains wait their turn inside the zone and an airport ride is offered to the first
 * of them first (DispatchEngine). Entry is automatic or by hand (`queue.entry`, D-277); the place in
 * line and a wait estimate learned from finished waits (D-278, D-279); and clear ways out (D-280):
 * leaving by hand, going offline, losing the connection (`queue.stale_after_s`), staying outside the
 * zone (`queue.leave_grace_s`), waiting too long (`queue.max_wait_s`), or getting a trip. No flight
 * tracking — the owner's rule. Whoever's place changes hears it on their own channel (no polling).
 */
class AirportQueue
{
    public function __construct(
        private readonly RideFeatures $features,
        private readonly RidePolicies $policies,
        private readonly PickupPoints $points,
        private readonly DriverEligibility $eligibility,
    ) {}

    /** The airport zone around a point whose queue is on, if any. */
    public function zoneAt(int $countryId, float $lat, float $lng): ?RideZone
    {
        return RideZone::query()
            ->where('country_id', $countryId)->where('type', ZoneType::Airport->value)->where('status', true)
            ->where('min_lat', '<=', $lat)->where('max_lat', '>=', $lat)
            ->where('min_lng', '<=', $lng)->where('max_lng', '>=', $lng)
            ->with('translations')->orderByDesc('priority')->orderBy('id')
            ->get()
            ->first(fn (RideZone $zone) => $this->enabled($zone) && $zone->contains($lat, $lng));
    }

    public function enabled(RideZone $zone): bool
    {
        return $zone->type === ZoneType::Airport && $zone->status && (bool) ($zone->config['queue_enabled'] ?? false)
            && $this->features->allows($zone->country_id, $zone->city_id, 'airport_queue');
    }

    public function policy(RideZone $zone): RidePolicyData
    {
        return $this->policies->for($zone->country_id, $zone->city_id, $zone->id);
    }

    public function waiting(Driver $driver): ?RideQueueEntry
    {
        return RideQueueEntry::query()->where('driver_id', $driver->id)->where('status', 'waiting')->with('zone.translations')->first();
    }

    /** 1 = next. */
    public function position(RideQueueEntry $entry): int
    {
        return 1 + RideQueueEntry::query()->where('zone_id', $entry->zone_id)->where('status', 'waiting')
            ->where(fn ($q) => $q->where('joined_at', '<', $entry->joined_at)->orWhere(fn ($q) => $q->where('joined_at', $entry->joined_at)->where('id', '<', $entry->id)))
            ->count();
    }

    public function size(RideZone $zone): int
    {
        return RideQueueEntry::query()->where('zone_id', $zone->id)->where('status', 'waiting')->count();
    }

    /**
     * Seconds until the entry's turn, from how long finished waits took per place in line (D-279) —
     * null until enough of them happened (`queue.min_samples` in the last `queue.stats_days`).
     */
    public function estimateWaitS(RideQueueEntry $entry, ?int $position = null): ?int
    {
        $policy = $this->policy($entry->zone);
        $done = RideQueueEntry::query()->where('zone_id', $entry->zone_id)->where('left_reason', 'got_trip')
            ->whereNotNull('wait_s')->where('left_at', '>=', now()->subDays(max(1, $policy->int('queue.stats_days'))))
            ->get(['wait_s', 'position_at_join']);
        if ($done->count() < max(1, $policy->int('queue.min_samples'))) {
            return null;
        }
        $perPlace = $done->avg(fn (RideQueueEntry $e) => $e->wait_s / max(1, $e->position_at_join));

        return (int) round($perPlace * ($position ?? $this->position($entry)));
    }

    /**
     * What the captain's Airport screen shows: where they are, their place, the estimate, the official
     * points, the way out. `zone` is null when there is no airport queue around them.
     *
     * @return array<string, mixed>
     */
    public function status(Driver $driver): array
    {
        $entry = $this->waiting($driver);
        $presence = DriverPresence::query()->find($driver->id);
        $zone = $entry?->zone;
        $inside = $entry !== null ? ($presence?->lat !== null && $zone->contains((float) $presence->lat, (float) $presence->lng)) : false;
        if ($zone === null && $presence?->lat !== null && $driver->country_id !== null) {
            $zone = $this->zoneAt((int) $driver->country_id, (float) $presence->lat, (float) $presence->lng);
            $inside = $zone !== null;
        }
        if ($zone === null) {
            return ['zone' => null, 'inside' => false, 'queued' => false, 'entry_mode' => null, 'position' => null, 'size' => 0, 'estimated_wait_s' => null, 'joined_at' => null, 'can_join' => false, 'cannot_join_reason' => null, 'pickup_points' => [], 'rules' => null];
        }

        $policy = $this->policy($zone);
        $position = $entry !== null ? $this->position($entry) : null;
        $problem = $entry === null ? $this->joinProblem($driver, $zone, $presence) : 'queued';

        return [
            'zone' => ['id' => $zone->id, 'name' => $zone->translated('name')],
            'inside' => $inside,
            'queued' => $entry !== null,
            'entry' => $entry?->entry,
            'entry_mode' => (string) $policy->get('queue.entry'),
            'position' => $position,
            'size' => $this->size($zone),
            'estimated_wait_s' => $entry === null ? null : $this->estimateWaitS($entry, $position),
            'joined_at' => $entry?->joined_at->toIso8601String(),
            'can_join' => $problem === null,
            'cannot_join_reason' => $problem === 'queued' ? null : $problem,
            // D-281: the airport's official pickup points, where a captain waits for and meets the rider.
            'pickup_points' => collect($this->points->pointsOf($zone, PickupPoints::PICKUP))
                ->map(fn ($p) => PickupPoints::present(PickupPoints::snapshot($p)))->values()->all(),
            'rules' => ['leave_grace_s' => $policy->int('queue.leave_grace_s'), 'max_wait_s' => $policy->int('queue.max_wait_s'), 'stale_after_s' => $policy->int('queue.stale_after_s')],
        ];
    }

    /**
     * Gets the captain in line, by hand (the Join button) or automatically (a location inside the zone).
     * Joining again changes nothing.
     */
    public function join(Driver $driver, string $entry = 'manual'): RideQueueEntry
    {
        if (($existing = $this->waiting($driver)) !== null) {
            return $existing;
        }
        $presence = DriverPresence::query()->find($driver->id);
        $zone = $presence?->lat !== null && $driver->country_id !== null ? $this->zoneAt((int) $driver->country_id, (float) $presence->lat, (float) $presence->lng) : null;
        if ($zone === null) {
            throw new RidesException('airport_queue_not_inside', 422);
        }
        if (($problem = $this->joinProblem($driver, $zone, $presence)) !== null) {
            throw new RidesException('airport_queue_'.$problem, 422);
        }

        try {
            $row = DB::transaction(fn () => RideQueueEntry::query()->create([
                'zone_id' => $zone->id,
                'driver_id' => $driver->id,
                'status' => 'waiting',
                'entry' => $entry,
                'position_at_join' => $this->size($zone) + 1,
                'joined_at' => now(),
                'active_driver_id' => $driver->id,
            ]));
        } catch (QueryException) {
            // Two taps at once: the first one is the place in line.
            return $this->waiting($driver) ?? throw new RidesException('airport_queue_busy', 409);
        }
        $this->tell([$driver->id]);

        return $row->load('zone.translations');
    }

    /** Out of the line, whatever the reason (D-280); everyone behind moves up and hears it. */
    public function leave(Driver $driver, string $reason = 'manual'): bool
    {
        $entry = RideQueueEntry::query()->where('driver_id', $driver->id)->where('status', 'waiting')->first();
        if ($entry === null) {
            return false;
        }
        $this->close($entry, $reason);

        return true;
    }

    /** A ride was assigned to the captain: whatever it is, they are no longer waiting. */
    public function onAssigned(Driver $driver): void
    {
        $this->leave($driver, 'got_trip');
    }

    /**
     * The captain's latest point: in line and outside the zone → the grace clock starts (and stops when
     * they are back); not in line, inside a zone whose entry is automatic → in line.
     */
    public function onLocation(Driver $driver, float $lat, float $lng): void
    {
        if (($entry = $this->waiting($driver)) !== null) {
            $inside = $entry->zone->contains($lat, $lng);
            if ($inside && $entry->outside_since !== null) {
                $entry->forceFill(['outside_since' => null])->save();
            } elseif (! $inside && $entry->outside_since === null) {
                $entry->forceFill(['outside_since' => now()])->save();
            }

            return;
        }
        if ($driver->country_id === null || ($zone = $this->zoneAt((int) $driver->country_id, $lat, $lng)) === null) {
            return;
        }
        if ($this->policy($zone)->get('queue.entry') !== 'auto') {
            return;
        }
        $presence = DriverPresence::query()->find($driver->id);
        if ($this->joinProblem($driver, $zone, $presence) === null) {
            $this->join($driver, 'auto');
        }
    }

    /** Every few seconds: captains who went offline, lost the connection, stayed out of the zone, waited too long, or whose queue was switched off. */
    public function sweep(): int
    {
        $out = 0;
        RideQueueEntry::query()->where('status', 'waiting')->with('zone.translations')->get()->each(function (RideQueueEntry $entry) use (&$out) {
            $zone = $entry->zone;
            $reason = null;
            if ($zone === null || ! $this->enabled($zone)) {
                $reason = 'removed';
            } else {
                $policy = $this->policy($zone);
                $presence = DriverPresence::query()->find($entry->driver_id);
                $reason = match (true) {
                    $presence === null || $presence->status !== PresenceStatus::Online => 'offline',
                    $presence->last_seen_at === null || $presence->last_seen_at->lt(now()->subSeconds($policy->int('queue.stale_after_s'))) => 'stale',
                    $entry->outside_since !== null && $entry->outside_since->lte(now()->subSeconds($policy->int('queue.leave_grace_s'))) => 'left_zone',
                    $policy->int('queue.max_wait_s') > 0 && $entry->joined_at->lte(now()->subSeconds($policy->int('queue.max_wait_s'))) => 'timeout',
                    default => null,
                };
            }
            if ($reason !== null) {
                $this->close($entry, $reason);
                $out++;
            }
        });

        return $out;
    }

    /**
     * The captains in line for a ride's airport, first to last: driver id → place (1 = next) — what the
     * dispatch goes through before looking at the rest. Empty when the ride's pickup isn't in a zone
     * whose queue is on.
     *
     * @return Collection<int, int>
     */
    public function orderFor(Ride $ride): Collection
    {
        $zoneId = (int) $ride->snapshot('zone_id');
        $zone = $zoneId > 0 ? RideZone::query()->find($zoneId) : null;

        return $zone !== null && $this->enabled($zone) ? $this->orderOf($zoneId) : collect();
    }

    /**
     * The captains in line at a zone, first to last: driver id → place (1 = next).
     *
     * @return Collection<int, int>
     */
    public function orderOf(int $zoneId): Collection
    {
        return RideQueueEntry::query()->where('zone_id', $zoneId)->where('status', 'waiting')->orderBy('joined_at')->orderBy('id')
            ->pluck('driver_id')->values()->mapWithKeys(fn (int $id, int $i) => [$id => $i + 1]);
    }

    /** Why the captain can't join now (a code the app shows), or null. */
    private function joinProblem(Driver $driver, RideZone $zone, ?DriverPresence $presence): ?string
    {
        $policy = $this->policy($zone);
        if ($presence === null || $presence->status !== PresenceStatus::Online) {
            return 'offline';
        }
        if (! $presence->gps_enabled || $presence->last_seen_at === null || $presence->last_seen_at->lt(now()->subSeconds($policy->int('queue.stale_after_s')))) {
            return 'stale';
        }
        if (RideActiveLock::query()->where('lock_key', RideActiveLock::forDriver($driver->id))->exists()) {
            return 'busy';
        }
        if ($this->eligibility->reasonNotToWork($driver) !== null) {
            return 'not_eligible';
        }
        // O-89: the airport queue is a service of its own (open to this vehicle here, and switched on).
        if (! app(\Modules\Rides\Services\Drivers\DriverServices::class)->offers($driver, 'airport')) {
            return 'not_eligible';
        }

        return null;
    }

    private function close(RideQueueEntry $entry, string $reason): void
    {
        $behind = RideQueueEntry::query()->where('zone_id', $entry->zone_id)->where('status', 'waiting')->where('id', '!=', $entry->id)
            ->where('joined_at', '>=', $entry->joined_at)->pluck('driver_id')->all();
        $entry->forceFill([
            'status' => 'left',
            'left_at' => now(),
            'left_reason' => $reason,
            'wait_s' => (int) $entry->joined_at->diffInSeconds(now()),
            'active_driver_id' => null,
            'outside_since' => null,
        ])->save();
        $this->tell([$entry->driver_id, ...$behind]);
    }

    /**
     * Their place changed: each captain's app reloads once on its own channel (best effort — a broadcast
     * hiccup never fails the call that moved the line).
     *
     * @param  list<int>  $driverIds
     */
    private function tell(array $driverIds): void
    {
        foreach (array_unique($driverIds) as $id) {
            try {
                broadcast(new DriverChanged((int) $id, '', 'queue'));
            } catch (Throwable $e) {
                Log::warning('[Rides] airport queue broadcast failed: '.$e->getMessage());
            }
        }
    }
}
