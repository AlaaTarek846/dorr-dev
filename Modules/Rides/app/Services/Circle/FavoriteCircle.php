<?php

namespace Modules\Rides\Services\Circle;

use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\FavoriteDriver;
use Modules\Rides\Models\Ride;
use Modules\Rides\Services\Dispatch\DispatchEngine;
use Modules\Rides\Services\Lifecycle\RideStateMachine;
use Modules\Rides\Services\Notifications\RideNotifier;
use Modules\Rides\Services\Safety\SafetyCenterService;
use Modules\User\Models\User;

/**
 * DORR Circle (R-INN-07, D-INN-07): the rider books a ride for their favourite captains only — all of
 * them or the ones they pick. The search goes to those captains who are really available and eligible
 * (never their position or schedule), one at a time, and never to anyone else until the rider says so:
 * when none of them takes it for `circle.ask_after_s` the rider is asked once; "search all captains"
 * widens it, doing nothing keeps waiting for the circle (until the usual search time runs out).
 */
class FavoriteCircle
{
    public function __construct(
        private readonly SafetyCenterService $safety,
        private readonly RideStateMachine $machine,
        private readonly RideNotifier $notifier,
    ) {}

    /** The rider's favourite captains, to choose from when booking. @return list<array<string, mixed>> */
    public function drivers(User $rider, ?float $lat = null, ?float $lng = null): array
    {
        $gate = app(\Modules\Rides\Services\Drivers\DriverEligibility::class);
        $presences = \Modules\Driver\Models\DriverPresence::query()
            ->whereIn('driver_id', \Modules\Rides\Models\FavoriteDriver::query()->where('user_id', $rider->id)->pluck('driver_id'))
            ->get()->keyBy('driver_id');

        // O-81: who of them can take a ride right now, and how far (to 0.1 km) from the pickup — nearest first.
        return collect($this->safety->favorites($rider))->map(function (array $f) use ($presences, $gate, $lat, $lng) {
            $p = $presences->get($f['driver_id']);
            $driver = \Modules\Driver\Models\Driver::query()->find($f['driver_id']);
            $available = $p !== null && $driver !== null
                && $p->status === \Modules\Driver\Enums\PresenceStatus::Online && $p->gps_enabled
                && $p->last_seen_at !== null && $p->last_seen_at->gte(now()->subSeconds(900))
                && \Modules\Rides\Models\RideActiveLock::query()->find(\Modules\Rides\Models\RideActiveLock::forDriver($driver->id)) === null
                && $gate->reasonNotToWork($driver) === null;
            $km = $available && $lat !== null && $lng !== null && $p->lat !== null
                ? round(\Modules\Rides\Support\Geo::metres($lat, $lng, (float) $p->lat, (float) $p->lng) / 1000, 1)
                : null;

            return $f + ['available' => $available, 'distance_km' => $km];
        })->sortBy(fn (array $f) => [$f['available'] ? 0 : 1, $f['distance_km'] ?? PHP_INT_MAX])->values()->all();
    }

    /**
     * The captains a ride is booked for. `$requested` empty = all the rider's favourites; otherwise the
     * ones picked, each of whom must be a favourite.
     *
     * @param  list<int>  $requested
     * @return list<int>
     */
    public function resolve(User $rider, array $requested): array
    {
        $favorites = FavoriteDriver::query()->where('user_id', $rider->id)->pluck('driver_id')->map(fn ($id) => (int) $id)->all();
        if ($favorites === []) {
            throw new RidesException('circle_empty', 422);
        }
        $ids = array_values(array_unique(array_map('intval', $requested)));
        if ($ids === []) {
            return $favorites;
        }
        if (array_diff($ids, $favorites) !== []) {
            throw new RidesException('circle_invalid', 422);
        }

        return $ids;
    }

    /** The circle found no one in time: the rider is asked, once (they decide; nothing widens by itself). */
    public function askIfDue(Ride $ride, int $searchingFor, int $askAfterS): void
    {
        if ($ride->search_scope !== 'circle' || $ride->circle_asked_at !== null || $searchingFor < $askAfterS) {
            return;
        }
        $ride->forceFill(['circle_asked_at' => now()])->save();
        $this->machine->record($ride, 'circle_asked', 'system', null);
        $this->notifier->rider($ride, 'circle_ask');
        $this->notifier->changed($ride, 'circle_ask');
    }

    /** "Search all captains": the usual search takes over from this moment. Asking twice changes nothing. */
    public function widen(User $rider, Ride $ride): Ride
    {
        if ($ride->search_scope !== 'circle') {
            if ($ride->circle_driver_ids !== null && ! $ride->status->isFinal()) {
                return $ride; // already widened
            }
            throw new RidesException('circle_not_active', 409);
        }
        if (! in_array($ride->status, [RideStatus::Searching, RideStatus::Bidding], true)) {
            throw new RidesException('circle_not_active', 409);
        }

        $ride->forceFill(['search_scope' => 'all', 'next_dispatch_at' => now()])->save();
        $this->machine->record($ride, 'circle_widened', 'user', $rider->id);
        $this->notifier->changed($ride, 'circle_widened');
        app(DispatchEngine::class)->runRound($ride);

        return $ride->refresh();
    }
}
