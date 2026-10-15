<?php

namespace Modules\Rides\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Something changed for a captain — a request was withdrawn or taken, their offer was answered, their
 * ride moved: the driver app reloads what it shows (the server is the truth). Sent on the captain's own
 * private channel; this replaces the app asking the server every few seconds.
 */
class DriverChanged implements ShouldBroadcastNow
{
    public function __construct(
        public readonly int $driverId,
        public readonly string $rideUuid,
        public readonly string $event,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('Modules.Driver.Models.Driver.'.$this->driverId);
    }

    public function broadcastAs(): string
    {
        return 'driver.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['ride_uuid' => $this->rideUuid, 'event' => $this->event];
    }
}
