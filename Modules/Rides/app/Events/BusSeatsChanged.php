<?php

namespace Modules\Rides\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Seats were booked or freed on a bus trip (O-23): every seat page open on it reloads its map once —
 * a public channel (no personal data in it, only that the map changed); no app asks on a timer.
 */
class BusSeatsChanged implements ShouldBroadcastNow
{
    public function __construct(public readonly string $tripUuid) {}

    public function broadcastOn(): Channel
    {
        return new Channel('bus-trips.'.$this->tripUuid);
    }

    public function broadcastAs(): string
    {
        return 'bus.seats';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['trip_uuid' => $this->tripUuid];
    }
}
