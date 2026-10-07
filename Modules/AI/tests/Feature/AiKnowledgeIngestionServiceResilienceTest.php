<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Models\AiKnowledgeSource;
use Modules\AI\Models\AiProvider;
use Modules\AI\Services\AiGateway;
use Modules\AI\Services\AiKnowledgeIngestionService;
use Tests\TestCase;

/**
 * v2.0 requirements doc S20.3 (failure/chaos for critical paths). A real
 * gap this caught: indexChunks() called AiGateway::embed() per chunk
 * with no try/catch, so one chunk's embedding call throwing (the same
 * unsupported-provider-key RuntimeException fixed elsewhere this
 * session) would have aborted the whole ingestion batch instead of
 * indexing that chunk without a vector, the same graceful degradation
 * that already applies when no embedding provider is configured at all.
 *
 * Indexing itself now runs via runIndexing() (queued by
 * IndexAiKnowledgeSourceJob, see AiKnowledgeIngestionServiceAsyncTest for
 * the dispatch/status-transition side of that), so this test exercises
 * runIndexing() directly rather than ingestText() - the resilience
 * behaviour under test lives in indexChunks(), which runIndexing() calls
 * the same way ingestText() used to before it started queuing the work.
 */
class AiKnowledgeIngestionServiceResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    protected function makeEmbeddingProvider(): AiProvider
    {
        return AiProvider::query()->create([
            'key' => 'openai',
            'name' => 'OpenAI (test)',
            'is_enabled' => true,
            'is_default' => true,
            'api_key' => 'test-key-not-real',
            'model' => 'gpt-4o-mini',
        ]);
    }

    public function test_a_throwing_embed_call_still_indexes_the_chunk_without_a_vector(): void
    {
        $this->makeEmbeddingProvider();

        $this->mock(AiGateway::class, function ($mock) {
            $mock->shouldReceive('embed')->once()->andThrow(new \RuntimeException('Unsupported AI provider [openai].'));
        });

        $source = AiKnowledgeSource::query()->create([
            'owner_type' => 'system',
            'owner_id' => null,
            'file_id' => null,
            'name' => 'Resilience test source',
            'data_classification' => AiKnowledgeSource::CLASSIFICATION_PUBLIC,
            'approval_status' => AiKnowledgeSource::APPROVAL_PENDING,
            'processing_status' => AiKnowledgeSource::PROCESSING_PENDING,
            'is_active' => true,
            'current_version' => 1,
        ]);

        app(AiKnowledgeIngestionService::class)->runIndexing(
            $source,
            'This is a short piece of source content to chunk and index.',
        );

        $source->refresh();
        $this->assertSame(
            AiKnowledgeSource::PROCESSING_READY,
            $source->processing_status,
            'runIndexing() must still mark the source ready when a chunk\'s embedding call failed - lexical-only indexing is still indexing.',
        );
        $this->assertNotNull($source->indexed_at);

        $chunk = $source->chunks()->first();
        $this->assertNotNull($chunk, 'the chunk must still be indexed even though its embedding call failed.');

        $content = json_decode(Storage::disk('local')->get($chunk->content_ref), true);
        $this->assertNull($content['embedding'] ?? null, 'no embedding should be stored when the embed call failed.');
        $this->assertNotEmpty($content['content'] ?? null, 'the chunk text itself must still be stored.');
    }
}
