<?php

namespace Modules\Freelance\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * FS-150d: something changed in a team's internal chat - a message arrived or was taken back, or somebody read a thread. Live to the team's
 * active people only (private channel `freelance-team.{team uuid}`, event `freelance.internal`), never to the client. The screen that is open
 * adds the message it carries, or reloads once; nothing asks the server on a timer.
 */
class FreelanceTeamChatChanged implements ShouldBroadcastNow
{
    /** @param  array<string, mixed>|null  $message */
    public function __construct(
        public readonly string $teamUuid,
        public readonly string $threadUuid,
        public readonly string $action,
        public readonly ?array $message = null,
        public readonly ?int $readerId = null,
        public readonly ?int $upTo = null,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('freelance-team.'.$this->teamUuid);
    }

    public function broadcastAs(): string
    {
        return 'freelance.internal';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return array_filter([
            'thread' => $this->threadUuid,
            'action' => $this->action,
            'message' => $this->message,
            'reader' => $this->readerId,
            'up_to' => $this->upTo,
        ], fn ($v) => $v !== null);
    }
}
