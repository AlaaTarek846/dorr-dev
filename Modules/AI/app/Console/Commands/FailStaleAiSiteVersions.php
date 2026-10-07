<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Command;
use Modules\AI\Models\AiSiteVersion;
use Modules\AI\Services\Sites\AiSiteGenerationService;

/**
 * A build or edit whose worker died (or never ran) would leave the site "generating" forever and block
 * the customer from trying again. Anything pending/processing for longer than a job may legitimately
 * run is failed here: the last good version stays live and a purchased allowance is given back.
 */
class FailStaleAiSiteVersions extends Command
{
    protected $signature = 'ai:fail-stale-sites';

    protected $description = 'Fail site builds/edits stuck in pending or processing for too long';

    public function handle(AiSiteGenerationService $generation): int
    {
        $minutes = max(5, (int) config('ai.sites.stale_after_minutes', 30));

        $stale = AiSiteVersion::query()
            ->whereIn('status', [AiSiteVersion::STATUS_PENDING, AiSiteVersion::STATUS_PROCESSING])
            ->where('updated_at', '<', now()->subMinutes($minutes))
            ->get();

        foreach ($stale as $version) {
            $generation->fail($version, $version->project()->withTrashed()->first(), 'generation_failed');
        }

        $this->info('failed='.$stale->count());

        return self::SUCCESS;
    }
}
