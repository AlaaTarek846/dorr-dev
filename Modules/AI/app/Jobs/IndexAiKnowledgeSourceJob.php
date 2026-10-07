<?php

namespace Modules\AI\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\AI\Models\AiKnowledgeSource;
use Modules\AI\Services\AiKnowledgeIngestionService;

/**
 * Runs the heavy part of knowledge-source ingestion (chunk -> embed each
 * chunk -> index) in the background, off the admin's HTTP request. See the
 * 2026_09_29_100000 migration's docblock for why: one OpenAI embedding
 * call per chunk, synchronously, could mean dozens of sequential API
 * calls blocking a single request for a long document.
 *
 * Dispatched by AiKnowledgeIngestionService::ingestText()/reingestText(),
 * which create the AiKnowledgeSource row with processing_status=pending
 * (and return immediately) before queuing this job to do the actual work.
 */
class IndexAiKnowledgeSourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(
        public AiKnowledgeSource $source,
        public string $cleanedContent,
    ) {}

    public function handle(AiKnowledgeIngestionService $ingestion): void
    {
        $ingestion->runIndexing($this->source, $this->cleanedContent);
    }

    /**
     * Runs when both attempts are exhausted (or a non-retryable failure)
     * - leaves the source clearly marked failed rather than stuck on
     * "processing" forever with no explanation in the admin UI.
     */
    public function failed(\Throwable $e): void
    {
        $this->source->fresh()?->update([
            'processing_status' => AiKnowledgeSource::PROCESSING_FAILED,
            'processing_error' => $e->getMessage(),
        ]);
    }
}
