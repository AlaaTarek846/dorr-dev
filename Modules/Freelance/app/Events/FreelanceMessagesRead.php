<?php

namespace Modules\Freelance\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** One side read the room up to a message: the other side's ticks turn to "read". */
class FreelanceMessagesRead implements ShouldBroadcastNow
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $reader,
        public readonly int $upToId,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('freelance-chat.'.$this->uuid);
    }

    public function broadcastAs(): string
    {
        return 'freelance.read';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['reader' => $this->reader, 'up_to_id' => $this->upToId];
    }
}
