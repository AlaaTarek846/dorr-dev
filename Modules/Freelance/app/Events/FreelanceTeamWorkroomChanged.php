<?php

namespace Modules\Freelance\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * FS-209: an inside item of a team's Workroom (a task, a file or a note with `scope = team`) changed. The project's own channel is shared with the
 * client, so nothing about a team item may travel there; this goes to the team's active people only (private channel `freelance-team.{team uuid}`,
 * event `freelance.workroom`) and carries the `project` so the open Workroom of that project reloads once. No text, only what changed.
 */
class FreelanceTeamWorkroomChanged implements ShouldBroadcastNow
{
    public function __construct(
        public readonly string $teamUuid,
        public readonly string $projectUuid,
        public readonly string $kind,
        public readonly string $action,
        public readonly string $uuid,
        public readonly string $by,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('freelance-team.'.$this->teamUuid);
    }

    public function broadcastAs(): string
    {
        return 'freelance.workroom';
    }

    /** @return array{project: string, kind: string, action: string, uuid: string, by: string} */
    public function broadcastWith(): array
    {
        return ['project' => $this->projectUuid, 'kind' => $this->kind, 'action' => $this->action, 'uuid' => $this->uuid, 'by' => $this->by];
    }
}
