<?php

namespace Modules\Marketing\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Modules\Marketing\Models\MarketingProjectMember;

/**
 * Something of a project changed outside the app (a platform connected through OAuth, a campaign's state) — pushed
 * as `marketing.{kind}.changed` to every member's own channel, so open screens reload once (no polling).
 */
class MarketingChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    /** @param  array<string, mixed>  $data */
    public function __construct(public readonly int $projectId, public readonly string $kind, public readonly array $data) {}

    public function broadcastOn(): array
    {
        return MarketingProjectMember::query()->where('project_id', $this->projectId)->get()
            ->map(fn (MarketingProjectMember $m) => new PrivateChannel(match ($m->member_type) {
                'provider' => 'Modules.Provider.Models.Provider.'.$m->member_id,
                default => 'Modules.User.Models.User.'.$m->member_id,
            }))->all();
    }

    public function broadcastAs(): string
    {
        return 'marketing.'.$this->kind.'.changed';
    }

    public function broadcastWith(): array
    {
        return $this->data;
    }
}
