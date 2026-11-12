<?php

namespace Modules\Marketing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Marketing\Models\MarketingJob;
use Modules\Marketing\Services\Plans\PlanGenerator;
use Throwable;

class GeneratePlanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $jobId) {}

    public function handle(PlanGenerator $generator): void
    {
        $job = MarketingJob::query()->find($this->jobId);
        if ($job === null || $job->isFinished()) {
            return;
        }

        try {
            $generator->run($job);
        } catch (Throwable $e) {
            report($e);
            $generator->fail($job->fresh(), 'internal_error');
        }
    }

    public function failed(?Throwable $e): void
    {
        $job = MarketingJob::query()->find($this->jobId);
        if ($job !== null) {
            app(PlanGenerator::class)->fail($job, 'internal_error');
        }
    }
}
