<?php

namespace Modules\AI\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\AI\Models\World\AiWorldSceneVersion;
use Modules\AI\Services\World\AiWorldProjectService;

/** One polling step of a scene attempt; re-dispatches itself with a delay while the provider is working. */
class AdvanceWorldSceneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $versionId) {}

    public function handle(AiWorldProjectService $service): void
    {
        $version = AiWorldSceneVersion::query()->find($this->versionId);

        if ($version !== null && $service->advanceScene($version)) {
            self::dispatch($this->versionId)->delay(now()->addSeconds((int) config('ai.video.poll_interval', 15)));
        }
    }
}
