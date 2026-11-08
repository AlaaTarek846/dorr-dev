<?php

namespace Modules\Freelance\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Something in a project's Workroom changed (FS-150a): live to everyone of the project (private channel `freelance-workroom.{project uuid}`).
 * It carries no text, only what changed, so the app reloads the list once instead of asking on a timer.
 */
class FreelanceWorkroomChanged implements ShouldBroadcastNow
{
    public function __construct(
        public readonly string $projectUuid,
        public readonly string $kind,
        public readonly string $action,
        public readonly string $uuid,
        public readonly string $by,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('freelance-workroom.'.$this->projectUuid);
    }

    public function broadcastAs(): string
    {
        return 'freelance.workroom';
    }

    /** @return array{kind: string, action: string, uuid: string, by: string} */
    public function broadcastWith(): array
    {
        return ['kind' => $this->kind, 'action' => $this->action, 'uuid' => $this->uuid, 'by' => $this->by];
    }
}
