<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Jobs\IndexAiKnowledgeSourceJob;
use Modules\AI\Models\AiKnowledgeSource;
use Modules\AI\Services\AiKnowledgeIngestionService;
use Tests\TestCase;

/**
 * ingestText()/reingestText() used to embed every chunk synchronously
 * inside the caller's request (see AiKnowledgeIngestionServiceResilienceTest's
 * docblock and the 2026_09_29_100000 migration) - a long document meant
 * dozens of sequential OpenAI calls blocking that one HTTP request. This
 * covers the fix: the source is created/updated and returned immediately,
 * with the actual chunk/embed work queued via IndexAiKnowledgeSourceJob.
 */
class AiKnowledgeIngestionServiceAsyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_ingest_text_returns_immediately_and_queues_indexing_instead_of_running_it_inline(): void
    {
        Bus::fake();

        $source = app(AiKnowledgeIngestionService::class)->ingestText([
            'owner_type' => 'system',
            'owner_id' => null,
            'file_id' => null,
            'name' => 'Async ingestion test source',
            'domain' => null,
            'country_code' => null,
            'publisher' => null,
            'authority' => null,
            'published_at' => null,
            'effective_at' => null,
            'data_classification' => AiKnowledgeSource::CLASSIFICATION_PUBLIC,
            'access_scope' => null,
        ], 'Some source content that would normally be chunked and embedded.');

        $this->assertNotNull($source->id, 'ingestText() must still return a persisted source synchronously.');
        $this->assertSame(AiKnowledgeSource::PROCESSING_PENDING, $source->processing_status);
        $this->assertSame(0, $source->chunks()->count(), 'no chunk should be written before the queued job runs.');

        Bus::assertDispatched(IndexAiKnowledgeSourceJob::class, function (IndexAiKnowledgeSourceJob $job) use ($source) {
            return $job->source->is($source);
        });
    }

    public function test_reingest_text_clears_old_chunks_immediately_but_reindexes_via_the_queue(): void
    {
        $ingestion = app(AiKnowledgeIngestionService::class);

        $source = AiKnowledgeSource::query()->create([
            'owner_type' => 'system',
            'owner_id' => null,
            'name' => 'Reingest test source',
            'data_classification' => AiKnowledgeSource::CLASSIFICATION_PUBLIC,
            'approval_status' => AiKnowledgeSource::APPROVAL_PENDING,
            'processing_status' => AiKnowledgeSource::PROCESSING_READY,
            'is_active' => true,
            'current_version' => 1,
        ]);
        $ingestion->runIndexing($source, 'Original content for this source, indexed once already.');
        $originalChunkCount = $source->fresh()->chunks()->count();
        $this->assertGreaterThan(0, $originalChunkCount);

        Bus::fake();
        $updated = $ingestion->reingestText($source->fresh(), 'Brand new replacement content for this source.');

        $this->assertSame(2, $updated->current_version);
        $this->assertSame(AiKnowledgeSource::PROCESSING_PENDING, $updated->processing_status);
        $this->assertSame(0, $updated->chunks()->count(), 'old chunks must be removed immediately, not left until the queued job runs.');

        Bus::assertDispatched(IndexAiKnowledgeSourceJob::class);
    }

    public function test_a_source_is_not_retrievable_until_its_queued_indexing_job_actually_runs(): void
    {
        Bus::fake();

        $source = app(AiKnowledgeIngestionService::class)->ingestText([
            'owner_type' => 'system',
            'owner_id' => null,
            'file_id' => null,
            'name' => 'Not yet indexed source',
            'domain' => null,
            'country_code' => null,
            'publisher' => null,
            'authority' => null,
            'published_at' => null,
            'effective_at' => null,
            'data_classification' => AiKnowledgeSource::CLASSIFICATION_PUBLIC,
            'access_scope' => null,
        ], 'Content that has not been indexed yet.');
        $source->update(['approval_status' => AiKnowledgeSource::APPROVAL_APPROVED]);

        $this->assertFalse(
            $source->fresh()->isRetrievable(),
            'an approved source must still not be retrievable while processing_status is pending/processing.',
        );
    }
}
