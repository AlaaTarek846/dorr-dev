<?php

namespace Modules\Freelance\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** A message in a freelance room, live to both sides (private channel `freelance-chat.{uuid}`). */
class FreelanceMessageSent implements ShouldBroadcastNow
{
    /** @param array<string, mixed> $message */
    public function __construct(
        public readonly string $uuid,
        public readonly array $message,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('freelance-chat.'.$this->uuid);
    }

    public function broadcastAs(): string
    {
        return 'freelance.message';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->message;
    }
}
