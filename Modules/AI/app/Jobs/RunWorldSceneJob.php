<?php

namespace Modules\AI\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\AI\Models\World\AiWorldScene;
use Modules\AI\Services\World\AiWorldProjectService;

/** Starts one generation attempt of a scene, then hands over to AdvanceWorldSceneJob for polling. */
class RunWorldSceneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $sceneId) {}

    public function handle(AiWorldProjectService $service): void
    {
        $scene = AiWorldScene::query()->find($this->sceneId);
        $version = $scene === null ? null : $service->runScene($scene);

        if ($version !== null && $version->status === 'generating') {
            AdvanceWorldSceneJob::dispatch($version->id)->delay(now()->addSeconds((int) config('ai.video.poll_interval', 15)));
        }
    }
}
