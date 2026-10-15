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
    public function drivers(User $rider): array
    {
        return $this->safety->favorites($rider);
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
