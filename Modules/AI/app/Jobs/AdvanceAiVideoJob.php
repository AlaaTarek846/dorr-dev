<?php

namespace Modules\AI\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\AI\Models\AiMediaGeneration;
use Modules\AI\Services\AiVideoGenerationService;

/**
 * One polling step of a chat video job. While the provider is still working
 * it re-dispatches itself with a delay instead of sleeping, so a queue worker
 * is never held for the minutes a video takes.
 */
class AdvanceAiVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $generationId) {}

    public function handle(AiVideoGenerationService $videos): void
    {
        $generation = AiMediaGeneration::query()->find($this->generationId);

        if ($generation === null) {
            return;
        }

        if ($videos->advance($generation)) {
            self::dispatch($this->generationId)->delay(now()->addSeconds((int) config('ai.video.poll_interval', 15)));
        }
    }
}
