<?php

namespace Modules\Rides\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Modules\Rides\Models\Ride;

/**
 * Something changed on a ride: both apps reload it (the server is the truth — R-197). Sent on
 * the ride's private channel, which only its rider and its driver may join.
 */
class RideChanged implements ShouldBroadcastNow
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $status,
        public readonly int $version,
        public readonly string $event,
    ) {}

    public static function of(Ride $ride, string $event): self
    {
        return new self($ride->uuid, $ride->status->value, $ride->version, $event);
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('rides.'.$this->uuid);
    }

    public function broadcastAs(): string
    {
        return 'ride.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['uuid' => $this->uuid, 'status' => $this->status, 'version' => $this->version, 'event' => $this->event];
    }
}
