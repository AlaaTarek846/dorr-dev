<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Jobs\ProcessAiFileJob;
use Modules\AI\Models\AiFile;
use Modules\AI\Models\AiFileChunk;
use Modules\AI\Models\AiProvider;
use Modules\AI\Services\AiGateway;
use Modules\AI\Services\Chunking\AiChunkingEngine;
use Modules\AI\Services\FileProcessors\AiFileProcessorManager;
use Modules\AI\Services\Indexing\AiIndexingEngine;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Phase 9 (doc S9/S10): AiIndexingEngine::embedChunks() now actually
 * PERSISTS the computed embedding vector - Phase 8 computed it and
 * threw it away, a documented gap this phase closes. No real OpenAI
 * call is made (this environment cannot reach the network); AiGateway
 * is mocked, same pattern as AiKnowledgeIngestionServiceResilienceTest.
 */
class AiIndexingEngineEmbeddingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    protected function makeProvider(): AiProvider
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

    protected function makeReadyFile(string $content = "# Policy\n\nEmployees get 21 days of annual leave.\n\n## Details\n\nMore text about the leave policy and how it is applied."): AiFile
    {
        $owner = User::query()->create([
            'name' => 'Embedding Test Owner',
            'email' => 'embedding-test-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);

        Storage::disk('public')->put('ai-chat/test/embed.md', $content);

        $file = AiFile::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'source_type' => AiFile::SOURCE_UPLOAD,
            'file_name' => 'embed.md',
            'file_path' => 'ai-chat/test/embed.md',
            'disk' => 'public',
            'mime_type' => 'text/markdown',
            'file_size' => strlen($content),
            'processing_status' => AiFile::STATUS_UPLOADED,
        ]);

        (new ProcessAiFileJob($file))->handle(app(AiFileProcessorManager::class));

        return $file->refresh();
    }

    public function test_auto_embed_disabled_leaves_chunks_unembedded(): void
    {
        config(['ai.indexing.auto_embed' => false]);
        $this->makeProvider();

        $this->mock(AiGateway::class, function ($mock) {
            $mock->shouldNotReceive('embed');
        });

        $file = $this->makeReadyFile();
        $chunks = app(AiChunkingEngine::class)->chunk($file);
        app(AiIndexingEngine::class)->index($chunks);

        $chunk = AiFileChunk::query()->where('file_id', $file->id)->first();
        $this->assertSame(AiFileChunk::EMBEDDING_PENDING, $chunk->embedding_status);
        $this->assertNull($chunk->readEmbedding());
    }

    public function test_auto_embed_enabled_persists_the_vector_and_marks_embedded(): void
    {
        config(['ai.indexing.auto_embed' => true]);
        $this->makeProvider();

        $this->mock(AiGateway::class, function ($mock) {
            $mock->shouldReceive('embed')->andReturn(['success' => true, 'message' => 'ok', 'vector' => [0.1, 0.2, 0.3]]);
        });

        $file = $this->makeReadyFile();
        $chunks = app(AiChunkingEngine::class)->chunk($file);
        app(AiIndexingEngine::class)->index($chunks);

        $chunk = AiFileChunk::query()->where('file_id', $file->id)->first();
        $this->assertSame(AiFileChunk::EMBEDDING_EMBEDDED, $chunk->embedding_status);
        $this->assertSame('openai', $chunk->embedding_provider);
        $this->assertNotNull($chunk->embedded_at);

        $vector = $chunk->readEmbedding();
        $this->assertNotNull($vector, 'the embedding must be readable back from the persisted content_ref JSON file.');
        $this->assertEqualsWithDelta([0.1, 0.2, 0.3], $vector, 0.0001);

        // The content_ref file must still carry the original text too -
        // persisting the vector must never clobber the chunk's content.
        $this->assertNotNull($chunk->readContent());
    }

    public function test_a_failed_embed_call_marks_the_chunk_failed_without_aborting_indexing(): void
    {
        config(['ai.indexing.auto_embed' => true]);
        $this->makeProvider();

        $this->mock(AiGateway::class, function ($mock) {
            $mock->shouldReceive('embed')->andReturn(['success' => false, 'message' => 'rate limited', 'vector' => null]);
        });

        $file = $this->makeReadyFile();
        $chunks = app(AiChunkingEngine::class)->chunk($file);
        app(AiIndexingEngine::class)->index($chunks);

        $chunk = AiFileChunk::query()->where('file_id', $file->id)->first();
        $this->assertSame(AiFileChunk::EMBEDDING_FAILED, $chunk->embedding_status);
        $this->assertNull($chunk->readEmbedding());
        // Lexical indexing must still have succeeded despite the failed embed.
        $this->assertSame(AiFileChunk::STATUS_INDEXED, $chunk->status);
        $this->assertNotNull($chunk->readContent());
    }

    public function test_a_throwing_embed_call_marks_the_chunk_failed_without_aborting_the_batch(): void
    {
        config(['ai.indexing.auto_embed' => true]);
        $this->makeProvider();

        $this->mock(AiGateway::class, function ($mock) {
            $mock->shouldReceive('embed')->andThrow(new \RuntimeException('simulated network failure'));
        });

        $file = $this->makeReadyFile();
        $chunks = app(AiChunkingEngine::class)->chunk($file);
        app(AiIndexingEngine::class)->index($chunks);

        $allChunks = AiFileChunk::query()->where('file_id', $file->id)->get();
        $this->assertNotEmpty($allChunks);

        foreach ($allChunks as $chunk) {
            $this->assertSame(AiFileChunk::EMBEDDING_FAILED, $chunk->embedding_status);
            $this->assertSame(AiFileChunk::STATUS_INDEXED, $chunk->status, 'a throwing embed call must never abort lexical indexing for the rest of the batch.');
        }
    }

    public function test_rechunking_marks_old_embeddings_stale(): void
    {
        config(['ai.indexing.auto_embed' => true]);
        $this->makeProvider();

        $this->mock(AiGateway::class, function ($mock) {
            $mock->shouldReceive('embed')->andReturn(['success' => true, 'message' => 'ok', 'vector' => [0.1, 0.2, 0.3]]);
        });

        $file = $this->makeReadyFile();
        $chunks = app(AiChunkingEngine::class)->chunk($file);
        app(AiIndexingEngine::class)->index($chunks);

        $embeddedChunk = AiFileChunk::query()->where('file_id', $file->id)->first();
        $this->assertSame(AiFileChunk::EMBEDDING_EMBEDDED, $embeddedChunk->embedding_status);

        // Re-chunk the same file (content changed) - the OLD chunk
        // version must never remain embedded/active/searchable.
        $file->update(['file_name' => 'embed.md']);
        Storage::disk('public')->put('ai-chat/test/embed.md', "# Policy\n\nEmployees get 25 days of annual leave now.\n\n## Details\n\nUpdated text about the leave policy.");
        app(AiChunkingEngine::class)->rechunk($file);

        $embeddedChunk->refresh();
        $this->assertSame(AiFileChunk::STATUS_STALE, $embeddedChunk->status);
        $this->assertSame(AiFileChunk::EMBEDDING_STALE, $embeddedChunk->embedding_status);
        $this->assertFalse((bool) $embeddedChunk->is_active);
    }
}
