<?php

namespace Modules\Marketing\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Modules\Marketing\Events\MarketingJobChanged;
use Modules\Marketing\Models\MarketingJob;
use Modules\Marketing\Models\MarketingProject;
use Throwable;

/** Opens jobs and moves them, telling the team each time. A failed push never fails the work. */
class MarketingJobs
{
    /** @param  array<string, mixed>  $payload */
    public function open(MarketingProject $project, Model $actor, string $type, Model $subject, array $payload = []): MarketingJob
    {
        return MarketingJob::query()->create([
            'project_id' => $project->id,
            'job_type' => $type,
            'subject_type' => class_basename($subject),
            'subject_id' => $subject->getKey(),
            'status' => MarketingJob::QUEUED,
            'payload' => $payload,
            'requested_by_type' => $actor->getMorphClass(),
            'requested_by_id' => $actor->getKey(),
        ]);
    }

    public function move(MarketingJob $job, string $status, int $progress, ?string $error = null): void
    {
        $job->fill([
            'status' => $status,
            'progress_pct' => $progress,
            'error_code' => $error,
            'started_at' => $job->started_at ?? ($status === MarketingJob::QUEUED ? null : now()),
            'finished_at' => in_array($status, MarketingJob::FINISHED, true) ? now() : null,
        ])->save();

        try {
            event(new MarketingJobChanged($job));
        } catch (Throwable $e) {
            Log::warning('[Marketing] job push failed: '.$e->getMessage());
        }
    }
}
