<?php

namespace Modules\Rides\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * O-68: a bus ticket's chat or call moved — a message, a ring answered, a hang-up. Both people hear it on their
 * own channel at once (the passenger's and the captain's), so the two apps follow without asking on a timer.
 */
class BusTalkChanged implements ShouldBroadcastNow
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly int $userId,
        private readonly int $driverId,
        private readonly string $event,
        private readonly array $payload,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('Modules.User.Models.User.'.$this->userId),
            new PrivateChannel('Modules.Driver.Models.Driver.'.$this->driverId),
        ];
    }

    public function broadcastAs(): string
    {
        return $this->event;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
