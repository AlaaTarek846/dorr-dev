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

        $source = app(AiKnowledgeIngestionService::class)->ingestText([
            'owner_type' => 'system',
            'owner_id' => null,
            'file_id' => null,
            'name' => 'Resilience test source',
            'domain' => null,
            'country_code' => null,
            'publisher' => null,
            'authority' => null,
            'published_at' => null,
            'effective_at' => null,
            'data_classification' => AiKnowledgeSource::CLASSIFICATION_PUBLIC,
            'access_scope' => null,
        ], 'This is a short piece of source content to chunk and index.');

        $this->assertNotNull($source->id, 'ingestText() must complete and return a persisted source, not throw.');

        $chunk = $source->fresh()->chunks()->first();
        $this->assertNotNull($chunk, 'the chunk must still be indexed even though its embedding call failed.');

        $content = json_decode(Storage::disk('local')->get($chunk->content_ref), true);
        $this->assertNull($content['embedding'] ?? null, 'no embedding should be stored when the embed call failed.');
        $this->assertNotEmpty($content['content'] ?? null, 'the chunk text itself must still be stored.');
    }
}
