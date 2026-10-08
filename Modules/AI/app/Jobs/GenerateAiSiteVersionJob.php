<?php

namespace Modules\AI\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\AI\Models\AiSiteVersion;
use Modules\AI\Services\Sites\AiSiteGenerationService;

/** Builds one site version off the request cycle (a whole site takes minutes). */
class GenerateAiSiteVersionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $versionId) {}

    public function handle(AiSiteGenerationService $generation): void
    {
        $version = AiSiteVersion::query()->find($this->versionId);

        if ($version !== null) {
            $generation->run($version);
        }
    }

    /** Worker killed or timed out: never leave a version stuck in "processing". */
    public function failed(?\Throwable $exception): void
    {
        $version = AiSiteVersion::query()->find($this->versionId);

        if ($version !== null && in_array($version->status, [AiSiteVersion::STATUS_PENDING, AiSiteVersion::STATUS_PROCESSING], true)) {
            app(AiSiteGenerationService::class)->fail($version, $version->project()->withTrashed()->first(), 'generation_failed');
        }
    }
}
