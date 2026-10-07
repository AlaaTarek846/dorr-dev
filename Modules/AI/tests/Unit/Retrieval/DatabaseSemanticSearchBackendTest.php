<?php

namespace Modules\AI\Tests\Unit\Retrieval;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Enums\AiRetrievalMode;
use Modules\AI\Models\AiFile;
use Modules\AI\Models\AiFileChunk;
use Modules\AI\Models\AiProvider;
use Modules\AI\Services\AiGateway;
use Modules\AI\Services\Retrieval\AiRetrievalQuery;
use Modules\AI\Services\Retrieval\Backends\DatabaseSemanticSearchBackend;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Phase 9 (doc S8/S36): a real vector-similarity backend, tested
 * WITHOUT ever calling the real OpenAI embeddings API. Where a test
 * needs AiGateway::embed() to "succeed," it is mocked via Laravel's
 * own $this->mock() helper (the exact pattern already used by
 * AiKnowledgeIngestionServiceResilienceTest) and the fixture embedding
 * vectors are hand-written, never fetched - these are explicitly unit
 * tests of the scoring/availability logic, not integration tests of
 * the OpenAI API, which this environment cannot reach.
 */
class DatabaseSemanticSearchBackendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    protected function makeOwner(): User
    {
        return User::query()->create([
            'name' => 'Semantic Backend Test Owner',
            'email' => 'semantic-test-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);
    }

    protected function makeFile(User $owner): AiFile
    {
        return AiFile::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'source_type' => AiFile::SOURCE_UPLOAD,
            'file_name' => 'doc.md',
            'file_path' => 'ai-chat/test/doc.md',
            'disk' => 'public',
            'mime_type' => 'text/markdown',
            'file_size' => 10,
            'processing_status' => AiFile::STATUS_READY,
        ]);
    }

    protected function makeChunk(AiFile $file, ?array $embedding, string $embeddingStatus, ?string $embeddingProvider, ?string $embeddingModel): AiFileChunk
    {
        $path = 'ai-chat/test/chunk-'.uniqid().'.json';
        $payload = ['content' => 'Employees get 21 days of annual leave.'];

        if ($embedding !== null) {
            $payload['embedding'] = $embedding;
            $payload['embedding_provider'] = $embeddingProvider;
            $payload['embedding_model'] = $embeddingModel;
        }

        Storage::disk('public')->put($path, json_encode($payload));

        return AiFileChunk::query()->create([
            'file_id' => $file->id,
            'chunk_index' => 0,
            'chunk_key' => $path,
            'content_ref' => $path,
            'content_type' => 'document',
            'checksum' => md5($path),
            'status' => AiFileChunk::STATUS_INDEXED,
            'is_active' => true,
            'embedding_status' => $embeddingStatus,
            'embedding_provider' => $embeddingProvider,
            'embedding_model' => $embeddingModel,
        ]);
    }

    public function test_is_unavailable_when_no_embeddings_capable_provider_is_configured(): void
    {
        // No AiProvider seeded at all - resolveActiveForChat() returns
        // null, exactly the same "no provider" state
        // AiKnowledgeRetrieverTest already relies on for its own
        // lexical-only assertions.
        $backend = app(DatabaseSemanticSearchBackend::class);

        $query = new AiRetrievalQuery(owner: $this->makeOwner(), queryText: 'annual leave', mode: AiRetrievalMode::Semantic);

        $this->assertFalse($backend->isAvailable($query));
    }

    public function test_is_available_when_an_openai_provider_is_configured(): void
    {
        AiProvider::query()->create([
            'key' => 'openai',
            'name' => 'OpenAI (test)',
            'is_enabled' => true,
            'is_default' => true,
            'api_key' => 'test-key-not-real',
            'model' => 'gpt-4o-mini',
        ]);

        $backend = app(DatabaseSemanticSearchBackend::class);
        $query = new AiRetrievalQuery(owner: $this->makeOwner(), queryText: 'annual leave', mode: AiRetrievalMode::Semantic);

        $this->assertTrue($backend->isAvailable($query));
    }

    public function test_score_is_null_when_the_chunk_has_no_stored_embedding(): void
    {
        AiProvider::query()->create([
            'key' => 'openai',
            'name' => 'OpenAI (test)',
            'is_enabled' => true,
            'is_default' => true,
            'api_key' => 'test-key-not-real',
            'model' => 'gpt-4o-mini',
        ]);

        $owner = $this->makeOwner();
        $file = $this->makeFile($owner);
        $chunk = $this->makeChunk($file, embedding: null, embeddingStatus: AiFileChunk::EMBEDDING_PENDING, embeddingProvider: null, embeddingModel: null);

        $backend = app(DatabaseSemanticSearchBackend::class);
        $query = new AiRetrievalQuery(owner: $owner, queryText: 'annual leave', mode: AiRetrievalMode::Semantic);

        $this->assertNull($backend->score($query, $chunk, $chunk->readContent()));
    }

    public function test_score_is_null_when_the_query_and_chunk_embedding_providers_do_not_match(): void
    {
        AiProvider::query()->create([
            'key' => 'openai',
            'name' => 'OpenAI (test)',
            'is_enabled' => true,
            'is_default' => true,
            'api_key' => 'test-key-not-real',
            'model' => 'gpt-4o-mini',
        ]);

        $this->mock(AiGateway::class, function ($mock) {
            $mock->shouldReceive('embed')->once()->andReturn(['success' => true, 'message' => 'ok', 'vector' => [1.0, 0.0, 0.0]]);
        });

        $owner = $this->makeOwner();
        $file = $this->makeFile($owner);
        // Stored under a DIFFERENT provider key than the one that will
        // resolve for the query (resolveEmbeddingProvider() only ever
        // returns the configured 'openai' provider here) - simulates a
        // chunk embedded before a provider switch.
        $chunk = $this->makeChunk($file, embedding: [1.0, 0.0, 0.0], embeddingStatus: AiFileChunk::EMBEDDING_EMBEDDED, embeddingProvider: 'a-different-provider', embeddingModel: (string) config('ai.knowledge.embedding_model'));

        $backend = app(DatabaseSemanticSearchBackend::class);
        $query = new AiRetrievalQuery(owner: $owner, queryText: 'annual leave', mode: AiRetrievalMode::Semantic);

        $this->assertNull($backend->score($query, $chunk, $chunk->readContent()));
    }

    public function test_score_returns_real_cosine_similarity_when_vectors_and_providers_match(): void
    {
        AiProvider::query()->create([
            'key' => 'openai',
            'name' => 'OpenAI (test)',
            'is_enabled' => true,
            'is_default' => true,
            'api_key' => 'test-key-not-real',
            'model' => 'gpt-4o-mini',
        ]);

        $this->mock(AiGateway::class, function ($mock) {
            $mock->shouldReceive('embed')->once()->andReturn(['success' => true, 'message' => 'ok', 'vector' => [1.0, 0.0, 0.0]]);
        });

        $owner = $this->makeOwner();
        $file = $this->makeFile($owner);
        $chunk = $this->makeChunk($file, embedding: [1.0, 0.0, 0.0], embeddingStatus: AiFileChunk::EMBEDDING_EMBEDDED, embeddingProvider: 'openai', embeddingModel: (string) config('ai.knowledge.embedding_model'));

        $backend = app(DatabaseSemanticSearchBackend::class);
        $query = new AiRetrievalQuery(owner: $owner, queryText: 'annual leave', mode: AiRetrievalMode::Semantic);

        $score = $backend->score($query, $chunk, $chunk->readContent());

        $this->assertNotNull($score);
        $this->assertEqualsWithDelta(1.0, $score, 0.0001, 'identical vectors must score cosine similarity of 1.0');
    }

    public function test_score_is_null_when_the_gateway_embed_call_throws(): void
    {
        AiProvider::query()->create([
            'key' => 'openai',
            'name' => 'OpenAI (test)',
            'is_enabled' => true,
            'is_default' => true,
            'api_key' => 'test-key-not-real',
            'model' => 'gpt-4o-mini',
        ]);

        $this->mock(AiGateway::class, function ($mock) {
            $mock->shouldReceive('embed')->once()->andThrow(new \RuntimeException('simulated network failure'));
        });

        $owner = $this->makeOwner();
        $file = $this->makeFile($owner);
        $chunk = $this->makeChunk($file, embedding: [1.0, 0.0, 0.0], embeddingStatus: AiFileChunk::EMBEDDING_EMBEDDED, embeddingProvider: 'openai', embeddingModel: (string) config('ai.knowledge.embedding_model'));

        $backend = app(DatabaseSemanticSearchBackend::class);
        $query = new AiRetrievalQuery(owner: $owner, queryText: 'annual leave', mode: AiRetrievalMode::Semantic);

        // Doc S14 failure-isolation: a throwing embed call must degrade
        // to "no semantic score," never bubble up and abort retrieval.
        $this->assertNull($backend->score($query, $chunk, $chunk->readContent()));
    }
}
