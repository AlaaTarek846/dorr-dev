<?php

namespace Modules\Marketing\Services\Research;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Marketing\Jobs\RunResearchJob;
use Modules\Marketing\Models\MarketingJob;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Models\MarketingResearchRun;
use Modules\Marketing\Services\MarketingAccess;
use Modules\Marketing\Services\MarketingAudit;
use Modules\Marketing\Services\MarketingJobs;
use Modules\Marketing\Services\Packages\MarketingEntitlement;

/** Starts research runs (U03). The run itself happens in RunResearchJob; the app follows `marketing.job.changed`. */
class ResearchService
{
    public function __construct(
        private readonly MarketingAccess $access,
        private readonly MarketingAudit $audit,
        private readonly MarketingEntitlement $entitlement,
        private readonly MarketingJobs $jobs,
    ) {}

    /**
     * @param  array<string, mixed>  $scope
     * @return array{run: MarketingResearchRun, job: MarketingJob}
     */
    public function start(MarketingProject $project, Model $actor, string $kind, array $scope, int $maxSources): array
    {
        $this->access->assert($project, $actor, 'research');
        $this->entitlement->assertRoom($project->owner, 'research_runs');

        [$run, $job] = DB::transaction(function () use ($project, $actor, $kind, $scope, $maxSources) {
            $run = MarketingResearchRun::query()->create([
                'project_id' => $project->id,
                'kind' => $kind,
                'status' => MarketingJob::QUEUED,
                'query_scope' => array_filter([
                    'cities' => $scope['cities'] ?? $project->city_names,
                    'segments' => $scope['segments'] ?? null,
                    'keywords' => $scope['keywords'] ?? null,
                    'locale' => app()->getLocale(),
                ], fn ($v) => $v !== null && $v !== []),
                'max_sources' => $maxSources,
            ]);
            $job = $this->jobs->open($project, $actor, 'research', $run, ['locale' => app()->getLocale()]);
            $this->audit->record($project, $actor, 'research.started', $run, meta: ['kind' => $kind]);

            return [$run, $job];
        });

        RunResearchJob::dispatch($job->id)->afterCommit();

        return ['run' => $run->fresh(), 'job' => $job->fresh()];
    }
}
