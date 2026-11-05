<?php

namespace Modules\Moving\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** A move changed (status, an offer, the driver…): the open screens reload it once — no app asks on a timer. */
class MovingStatusBroadcast implements ShouldBroadcastNow
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $status,
        public readonly string $action,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('moving.'.$this->uuid);
    }

    public function broadcastAs(): string
    {
        return 'moving.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['uuid' => $this->uuid, 'status' => $this->status, 'action' => $this->action];
    }
}
