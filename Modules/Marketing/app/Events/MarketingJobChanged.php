<?php

namespace Modules\Marketing\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Modules\Marketing\Models\MarketingJob;
use Modules\Marketing\Models\MarketingProjectMember;

/**
 * A research run or a plan job moved (queued, running, done, failed) — pushed to every team member's own account
 * channel, so the apps reload once instead of asking on a timer (AGENTS.md, no polling).
 */
class MarketingJobChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public readonly MarketingJob $job) {}

    public function broadcastOn(): array
    {
        return MarketingProjectMember::query()->where('project_id', $this->job->project_id)->get()
            ->map(fn (MarketingProjectMember $m) => new PrivateChannel(match ($m->member_type) {
                'provider' => 'Modules.Provider.Models.Provider.'.$m->member_id,
                default => 'Modules.User.Models.User.'.$m->member_id,
            }))->all();
    }

    public function broadcastAs(): string
    {
        return 'marketing.job.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'job_id' => $this->job->uuid,
            'project_id' => $this->job->project?->uuid,
            'job_type' => $this->job->job_type,
            'status' => $this->job->status,
            'progress_pct' => $this->job->progress_pct,
            'error_code' => $this->job->error_code,
        ];
    }
}
