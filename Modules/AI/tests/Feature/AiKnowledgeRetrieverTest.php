<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Models\AiKnowledgeChunk;
use Modules\AI\Models\AiKnowledgeSource;
use Modules\AI\Services\AiKnowledgeRetriever;
use Modules\Admin\Models\Admin;
use Tests\TestCase;

/**
 * v2.0 requirements doc S5.3/5.4: hybrid retrieval, metadata-filtered to
 * approved+active+visible sources, reranked with a bounded freshness
 * boost. No AiProvider is seeded in these tests, so
 * AiProviderRepository::resolveActiveForChat() returns null and
 * AiKnowledgeRetriever::embedQuery() short-circuits to null - retrieval
 * runs on its real lexical-only path, which is exactly what is under
 * test here (the vector/embedding path needs a real embeddings API call
 * and is not something a unit test can fake without misrepresenting
 * what was actually verified).
 */
class AiKnowledgeRetrieverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    protected function makeOwner(): Admin
    {
        return Admin::query()->create([
            'name' => 'Test Admin',
            'email' => 'kb-owner-'.uniqid().'@example.test',
            'password' => 'password',
            'status' => true,
        ]);
    }

    protected function makeSource(array $overrides = []): AiKnowledgeSource
    {
        return AiKnowledgeSource::query()->create(array_merge([
            'owner_type' => 'system',
            'owner_id' => null,
            'name' => 'Test source',
            'domain' => null,
            'data_classification' => AiKnowledgeSource::CLASSIFICATION_PUBLIC,
            'approval_status' => AiKnowledgeSource::APPROVAL_APPROVED,
            'is_active' => true,
            // Retrieval now also requires indexing to have actually
            // finished (processing_status) - these tests build their
            // chunks directly via makeChunk() rather than going through
            // real ingestion, so they must mark the source ready
            // themselves, same as real ingestion does once indexing
            // completes.
            'processing_status' => AiKnowledgeSource::PROCESSING_READY,
        ], $overrides));
    }

    protected function makeChunk(AiKnowledgeSource $source, string $content, bool $searchable = true): AiKnowledgeChunk
    {
        $path = 'ai-knowledge/test-'.uniqid().'.json';
        Storage::disk('local')->put($path, json_encode(['content' => $content]));

        return AiKnowledgeChunk::query()->create([
            'knowledge_source_id' => $source->id,
            'chunk_index' => 0,
            'content_ref' => $path,
            'token_count' => str_word_count($content),
            'searchable' => $searchable,
        ]);
    }

    public function test_returns_empty_when_knowledge_retrieval_is_disabled(): void
    {
        config(['ai.knowledge.enabled' => false]);

        $source = $this->makeSource();
        $this->makeChunk($source, 'Refunds are processed within 5 business days.');

        $result = app(AiKnowledgeRetriever::class)->retrieve($this->makeOwner(), 'refund policy');

        $this->assertSame([], $result);
    }

    public function test_returns_empty_for_a_blank_query(): void
    {
        $source = $this->makeSource();
        $this->makeChunk($source, 'Refunds are processed within 5 business days.');

        $result = app(AiKnowledgeRetriever::class)->retrieve($this->makeOwner(), '   ');

        $this->assertSame([], $result);
    }

    public function test_a_pending_unapproved_source_is_excluded(): void
    {
        $source = $this->makeSource(['approval_status' => AiKnowledgeSource::APPROVAL_PENDING]);
        $this->makeChunk($source, 'Refund policy: refunds within 5 business days of cancellation.');

        $result = app(AiKnowledgeRetriever::class)->retrieve($this->makeOwner(), 'refund policy cancellation');

        $this->assertSame([], $result);
    }

    public function test_an_inactive_source_is_excluded(): void
    {
        $source = $this->makeSource(['is_active' => false]);
        $this->makeChunk($source, 'Refund policy: refunds within 5 business days of cancellation.');

        $result = app(AiKnowledgeRetriever::class)->retrieve($this->makeOwner(), 'refund policy cancellation');

        $this->assertSame([], $result);
    }

    public function test_a_non_searchable_chunk_is_excluded_even_from_an_approved_source(): void
    {
        $source = $this->makeSource();
        $this->makeChunk($source, 'Refund policy: refunds within 5 business days of cancellation.', searchable: false);

        $result = app(AiKnowledgeRetriever::class)->retrieve($this->makeOwner(), 'refund policy cancellation');

        $this->assertSame([], $result);
    }

    public function test_domain_filter_only_matches_sources_in_that_domain(): void
    {
        $legalSource = $this->makeSource(['domain' => 'legal']);
        $this->makeChunk($legalSource, 'Contract termination requires thirty days written notice.');

        $codeSource = $this->makeSource(['domain' => 'code']);
        $this->makeChunk($codeSource, 'Contract termination requires thirty days written notice.');

        $result = app(AiKnowledgeRetriever::class)->retrieve($this->makeOwner(), 'contract termination notice', 'legal');

        $this->assertCount(1, $result);
        $this->assertSame($legalSource->id, $result[0]['source']->id);
    }

    public function test_a_confidential_source_owned_by_someone_else_is_not_visible(): void
    {
        $owner = $this->makeOwner();
        $otherOwner = $this->makeOwner();

        $source = $this->makeSource([
            'data_classification' => AiKnowledgeSource::CLASSIFICATION_CONFIDENTIAL,
            'owner_type' => $otherOwner->getMorphClass(),
            'owner_id' => $otherOwner->getAuthIdentifier(),
        ]);
        $this->makeChunk($source, 'Internal salary bands for the engineering team are confidential.');

        $result = app(AiKnowledgeRetriever::class)->retrieve($owner, 'internal salary bands engineering');

        $this->assertSame([], $result);
    }

    public function test_a_confidential_source_owned_by_the_requester_is_visible_to_them(): void
    {
        $owner = $this->makeOwner();

        $source = $this->makeSource([
            'data_classification' => AiKnowledgeSource::CLASSIFICATION_CONFIDENTIAL,
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getAuthIdentifier(),
        ]);
        $this->makeChunk($source, 'Internal salary bands for the engineering team are confidential.');

        $result = app(AiKnowledgeRetriever::class)->retrieve($owner, 'internal salary bands engineering');

        $this->assertCount(1, $result);
    }

    public function test_chunks_are_ranked_by_lexical_overlap_with_the_query(): void
    {
        $source = $this->makeSource();

        $weakMatch = $this->makeChunk($source, 'Our platform supports many payment methods including cards and wallets.');
        $strongMatch = $this->makeChunk($source, 'Refund policy: refunds are processed within five business days of a cancellation request.');

        $result = app(AiKnowledgeRetriever::class)->retrieve($this->makeOwner(), 'refund policy cancellation business days');

        $this->assertNotEmpty($result);
        $this->assertSame($strongMatch->id, $result[0]['chunk']->id, 'The chunk with more keyword overlap must rank first.');
    }

    public function test_a_chunk_scoring_below_the_minimum_relevance_threshold_is_dropped(): void
    {
        config(['ai.knowledge.min_relevance_score' => 0.95]);

        $source = $this->makeSource();
        $this->makeChunk($source, 'Refund policy: refunds within five business days.');

        $result = app(AiKnowledgeRetriever::class)->retrieve($this->makeOwner(), 'refund policy business days');

        $this->assertSame([], $result, 'A very high relevance floor must filter out an imperfect lexical match.');
    }

    public function test_the_retrieval_limit_config_caps_the_number_of_results(): void
    {
        config(['ai.knowledge.retrieval_limit' => 1]);

        $source = $this->makeSource();
        $this->makeChunk($source, 'Refund policy: refunds within five business days of cancellation.');
        $this->makeChunk($source, 'Refund policy: cancellations processed within five business days.');

        $result = app(AiKnowledgeRetriever::class)->retrieve($this->makeOwner(), 'refund policy cancellation business days');

        $this->assertCount(1, $result);
    }

    public function test_a_chunk_whose_content_file_is_missing_is_skipped_without_erroring(): void
    {
        $source = $this->makeSource();

        AiKnowledgeChunk::query()->create([
            'knowledge_source_id' => $source->id,
            'chunk_index' => 0,
            'content_ref' => 'ai-knowledge/does-not-exist.json',
            'token_count' => 10,
            'searchable' => true,
        ]);

        $result = app(AiKnowledgeRetriever::class)->retrieve($this->makeOwner(), 'anything');

        $this->assertSame([], $result);
    }

    /**
     * v2.0 requirements doc S20.3 (failure/chaos for critical paths). A
     * real gap this caught: embedQuery() called AiGateway::embed() with
     * no try/catch. Unlike the other tests in this file, a provider IS
     * seeded here specifically so the embedding call is actually
     * reached - proving a thrown exception degrades to null (falling
     * back to lexical-only retrieval, exactly like "no provider
     * configured" already does) instead of crashing retrieve() outright.
     */
    public function test_a_throwing_embed_call_degrades_to_null_instead_of_crashing(): void
    {
        \Modules\AI\Models\AiProvider::query()->create([
            'key' => 'openai',
            'name' => 'OpenAI (test)',
            'is_enabled' => true,
            'is_default' => true,
            'api_key' => 'test-key-not-real',
            'model' => 'gpt-4o-mini',
        ]);

        $this->mock(\Modules\AI\Services\AiGateway::class, function ($mock) {
            $mock->shouldReceive('embed')->once()->andThrow(new \RuntimeException('boom'));
        });

        $method = new \ReflectionMethod(AiKnowledgeRetriever::class, 'embedQuery');
        $method->setAccessible(true);

        $result = $method->invoke(app(AiKnowledgeRetriever::class), 'anything');

        $this->assertNull($result);
    }
}
