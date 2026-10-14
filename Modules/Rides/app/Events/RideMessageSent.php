<?php

namespace Modules\Rides\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** A chat message on a ride, live to the other side (the same private channel as the ride). */
class RideMessageSent implements ShouldBroadcastNow
{
    /** @param array<string, mixed> $message */
    public function __construct(
        public readonly string $uuid,
        public readonly array $message,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('rides.'.$this->uuid);
    }

    public function broadcastAs(): string
    {
        return 'ride.message';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->message;
    }
}
