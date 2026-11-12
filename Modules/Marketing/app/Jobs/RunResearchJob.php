<?php

namespace Modules\Marketing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Marketing\Models\MarketingJob;
use Modules\Marketing\Services\Research\ResearchRunner;
use Throwable;

class RunResearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $jobId) {}

    public function handle(ResearchRunner $runner): void
    {
        $job = MarketingJob::query()->find($this->jobId);
        if ($job === null || $job->isFinished()) {
            return;
        }

        try {
            $runner->run($job);
        } catch (Throwable $e) {
            report($e);
            $runner->fail($job->fresh(), 'internal_error');
        }
    }

    /** Never left "running" (spec 05). */
    public function failed(?Throwable $e): void
    {
        $job = MarketingJob::query()->find($this->jobId);
        if ($job !== null && ! $job->isFinished()) {
            app(ResearchRunner::class)->fail($job, 'internal_error');
        }
    }
}
