<?php

namespace Modules\AI\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\AI\Models\AiFile;
use Modules\AI\Services\Chunking\AiChunkingEngine;
use Modules\AI\Services\Chunking\AiChunkingException;
use Modules\AI\Services\Indexing\AiIndexingEngine;

/**
 * Phase 8 (doc S33's own "do not create unnecessary jobs if a generic
 * file-processing job already exists" instruction): ONE job covering
 * both chunk() and index(), dispatched right after ProcessAiFileJob
 * finishes extracting a file's normalized content - not the master
 * prompt's full four-job suggestion, since chunking and indexing are
 * always run back-to-back for a freshly processed file and splitting
 * them into separate queued steps would only add latency with no real
 * retry-semantics benefit (chunk() and index() are each already safe
 * to retry on their own - see AiChunkingEngine's chunk_key upsert and
 * AiIndexingEngine's per-chunk status transitions).
 *
 * Safe to retry as a whole (doc S35): AiChunkingEngine::chunk() upserts
 * by the deterministic chunk_key, so re-running this job after a crash
 * never duplicates active chunks - it just re-derives the same rows.
 */
class ChunkAndIndexAiFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public AiFile $file) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(AiChunkingEngine $chunkingEngine, AiIndexingEngine $indexingEngine): void
    {
        try {
            $chunks = $chunkingEngine->chunk($this->file);
        } catch (AiChunkingException $e) {
            Log::warning('ai_file.chunking.failed', [
                'file_id' => $this->file->id,
                'error_code' => $e->errorCode,
            ]);

            return;
        }

        if ($chunks === []) {
            return;
        }

        $indexingEngine->index($chunks);
    }
}
