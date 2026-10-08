<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Enums\AiRetrievalMode;
use Modules\AI\Jobs\ProcessAiFileJob;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiFile;
use Modules\AI\Models\AiFileChunk;
use Modules\AI\Services\Chunking\AiChunkingEngine;
use Modules\AI\Services\FileProcessors\AiFileProcessorManager;
use Modules\AI\Services\Indexing\AiIndexingEngine;
use Modules\AI\Services\Retrieval\AiRetrievalEngine;
use Modules\AI\Services\Retrieval\AiRetrievalQuery;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * End-to-end: a real file runs through the real Phase 1/8 pipeline
 * (ProcessAiFileJob -> AiChunkingEngine -> AiIndexingEngine, with
 * auto_embed left at its default false so this never attempts a real
 * network call), then AiRetrievalEngine retrieves against the REAL
 * persisted AiFileChunk rows - not a hand-built fixture shape.
 */
class AiRetrievalEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Global file scope is closed by default (Phase 10 hardening); these tests search without an explicit scope.
        config(['ai.retrieval.allow_global_file_scope' => true]);
    }

    protected function makeOwner(string $label): User
    {
        return User::query()->create([
            'name' => "Retrieval Test Owner {$label}",
            'email' => 'retrieval-test-'.$label.'-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);
    }

    /** Storage::fake() wipes the disk, so it must run once per test, never once per file. */
    protected bool $storageFaked = false;

    protected function makeIndexedFile(User $owner, string $name, string $content, ?int $conversationId = null): AiFile
    {
        if (! $this->storageFaked) {
            Storage::fake('public');
            Storage::fake('local');
            $this->storageFaked = true;
        }

        $path = "ai-chat/test/{$name}-".uniqid().'.md';
        Storage::disk('public')->put($path, $content);

        $file = AiFile::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'source_type' => AiFile::SOURCE_UPLOAD,
            'conversation_id' => $conversationId,
            'file_name' => $name.'.md',
            'file_path' => $path,
            'disk' => 'public',
            'mime_type' => 'text/markdown',
            'file_size' => strlen($content),
            'processing_status' => AiFile::STATUS_UPLOADED,
        ]);

        (new ProcessAiFileJob($file))->handle(app(AiFileProcessorManager::class));
        $file->refresh();

        $chunks = app(AiChunkingEngine::class)->chunk($file);
        app(AiIndexingEngine::class)->index($chunks);

        return $file;
    }

    public function test_keyword_retrieval_finds_relevant_chunk(): void
    {
        $owner = $this->makeOwner('A');
        $this->makeIndexedFile($owner, 'policy', "# Leave Policy\n\nEmployees get 21 days of annual leave per year.");

        $result = app(AiRetrievalEngine::class)->retrieve(new AiRetrievalQuery(
            owner: $owner,
            queryText: 'How many days of annual leave do employees get?',
            mode: AiRetrievalMode::Keyword,
        ));

        $this->assertFalse($result->isEmpty());
        $this->assertStringContainsString('21 days', $result->results[0]->content);
    }

    public function test_unrelated_query_returns_no_results(): void
    {
        $owner = $this->makeOwner('B');
        $this->makeIndexedFile($owner, 'policy', "# Leave Policy\n\nEmployees get 21 days of annual leave per year.");

        $result = app(AiRetrievalEngine::class)->retrieve(new AiRetrievalQuery(
            owner: $owner,
            queryText: 'What is the capital of France?',
            mode: AiRetrievalMode::Keyword,
        ));

        $this->assertTrue($result->isEmpty());
    }

    public function test_never_retrieves_another_owners_chunks(): void
    {
        $ownerA = $this->makeOwner('C1');
        $ownerB = $this->makeOwner('C2');

        $this->makeIndexedFile($ownerA, 'secret', "# Confidential\n\nOwner A's secret annual leave policy details.");

        $result = app(AiRetrievalEngine::class)->retrieve(new AiRetrievalQuery(
            owner: $ownerB,
            queryText: 'secret annual leave policy details',
            mode: AiRetrievalMode::Keyword,
        ));

        $this->assertTrue($result->isEmpty(), 'Owner B must never retrieve chunks belonging to Owner A.');
    }

    public function test_explicit_file_id_scope_is_still_owner_checked(): void
    {
        $ownerA = $this->makeOwner('D1');
        $ownerB = $this->makeOwner('D2');

        $fileA = $this->makeIndexedFile($ownerA, 'report', "# Report\n\nRevenue figures and annual leave totals for this year.");

        // Owner B explicitly asks for Owner A's file id - must resolve
        // to nothing, never leak that the file exists.
        $result = app(AiRetrievalEngine::class)->retrieve(new AiRetrievalQuery(
            owner: $ownerB,
            queryText: 'revenue figures',
            mode: AiRetrievalMode::Keyword,
            fileIds: [$fileA->id],
        ));

        $this->assertTrue($result->isEmpty());
    }

    public function test_file_scope_filters_to_only_the_requested_file(): void
    {
        $owner = $this->makeOwner('E');
        $fileA = $this->makeIndexedFile($owner, 'alpha', "# Alpha\n\nThis file discusses the annual leave policy in depth.");
        $fileB = $this->makeIndexedFile($owner, 'beta', "# Beta\n\nThis file discusses the annual leave policy as well but is a different file.");

        $result = app(AiRetrievalEngine::class)->retrieve(new AiRetrievalQuery(
            owner: $owner,
            queryText: 'annual leave policy',
            mode: AiRetrievalMode::Keyword,
            fileIds: [$fileA->id],
        ));

        $this->assertFalse($result->isEmpty());

        foreach ($result->results as $retrieved) {
            $this->assertSame($fileA->id, $retrieved->chunk->file_id);
        }
    }

    public function test_conversation_scope_resolves_files_attached_to_that_conversation(): void
    {
        $owner = $this->makeOwner('F');
        $convoA = AiConversation::query()->create(['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->id, 'title' => 'A']);
        $convoB = AiConversation::query()->create(['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->id, 'title' => 'B']);
        $fileInConvo = $this->makeIndexedFile($owner, 'convo-file', "# Notes\n\nThis conversation's own annual leave discussion notes.", conversationId: $convoA->id);
        $fileElsewhere = $this->makeIndexedFile($owner, 'other-file', "# Other\n\nA totally different conversation's annual leave discussion notes.", conversationId: $convoB->id);

        $result = app(AiRetrievalEngine::class)->retrieve(new AiRetrievalQuery(
            owner: $owner,
            queryText: 'annual leave discussion notes',
            mode: AiRetrievalMode::Keyword,
            conversationId: $convoA->id,
        ));

        foreach ($result->results as $retrieved) {
            $this->assertSame($fileInConvo->id, $retrieved->chunk->file_id);
        }
    }

    public function test_top_k_bounds_the_returned_count(): void
    {
        $owner = $this->makeOwner('G');

        $paragraphs = [];
        for ($i = 1; $i <= 10; $i++) {
            $paragraphs[] = "Paragraph {$i} discusses the annual leave policy from a slightly different angle each time.";
        }

        $this->makeIndexedFile($owner, 'big', "# Policy\n\n".implode("\n\n", $paragraphs));

        $result = app(AiRetrievalEngine::class)->retrieve(new AiRetrievalQuery(
            owner: $owner,
            queryText: 'annual leave policy',
            mode: AiRetrievalMode::Keyword,
            topK: 3,
        ));

        $this->assertLessThanOrEqual(3, $result->returnedCount);
    }

    public function test_results_are_deterministic_across_repeated_identical_queries(): void
    {
        $owner = $this->makeOwner('H');
        $this->makeIndexedFile($owner, 'policy', "# Leave Policy\n\nEmployees get 21 days of annual leave per year.\n\n## Sick Leave\n\nEmployees get 10 days of sick leave per year.");

        $query = new AiRetrievalQuery(owner: $owner, queryText: 'How much annual leave and sick leave do employees get?', mode: AiRetrievalMode::Keyword);

        $firstIds = array_map(fn ($r) => $r->chunk->id, app(AiRetrievalEngine::class)->retrieve($query)->results);
        $secondIds = array_map(fn ($r) => $r->chunk->id, app(AiRetrievalEngine::class)->retrieve($query)->results);

        $this->assertSame($firstIds, $secondIds);
    }

    public function test_content_type_filter_restricts_results(): void
    {
        $owner = $this->makeOwner('I');
        $this->makeIndexedFile($owner, 'policy', "# Leave Policy\n\n| Type | Days |\n| --- | --- |\n| Annual | 21 |\n| Sick | 10 |\n\nSome prose about annual leave policy as well.");

        $result = app(AiRetrievalEngine::class)->retrieve(new AiRetrievalQuery(
            owner: $owner,
            queryText: 'annual leave',
            mode: AiRetrievalMode::Keyword,
            filters: ['content_type' => 'table'],
        ));

        foreach ($result->results as $retrieved) {
            $this->assertSame('table', $retrieved->chunk->content_type);
        }
    }

    public function test_only_active_indexed_chunks_are_ever_returned(): void
    {
        $owner = $this->makeOwner('J');
        $file = $this->makeIndexedFile($owner, 'policy', "# Leave Policy\n\nEmployees get 21 days of annual leave per year.");

        // Simulate a stale/inactive chunk the same way rechunk() would
        // leave one - it must never be retrievable even though its
        // content would otherwise match.
        AiFileChunk::query()->where('file_id', $file->id)->update(['is_active' => false, 'status' => AiFileChunk::STATUS_STALE]);

        $result = app(AiRetrievalEngine::class)->retrieve(new AiRetrievalQuery(
            owner: $owner,
            queryText: 'annual leave',
            mode: AiRetrievalMode::Keyword,
        ));

        $this->assertTrue($result->isEmpty());
    }

    public function test_no_files_in_scope_returns_empty_result_cheaply(): void
    {
        $owner = $this->makeOwner('K');

        $result = app(AiRetrievalEngine::class)->retrieve(new AiRetrievalQuery(
            owner: $owner,
            queryText: 'anything at all',
            mode: AiRetrievalMode::Keyword,
        ));

        $this->assertTrue($result->isEmpty());
    }

    public function test_exact_mode_only_returns_literal_substring_hits(): void
    {
        $owner = $this->makeOwner('L');
        $this->makeIndexedFile($owner, 'policy', "# Leave Policy\n\nThe exact phrase SPECIAL-CODE-19283 appears here once.\n\n## Other\n\nThis paragraph talks about leave policy generally.");

        $exactResult = app(AiRetrievalEngine::class)->retrieve(new AiRetrievalQuery(
            owner: $owner,
            queryText: 'SPECIAL-CODE-19283',
            mode: AiRetrievalMode::Exact,
        ));

        $this->assertFalse($exactResult->isEmpty());
        $this->assertStringContainsString('SPECIAL-CODE-19283', $exactResult->results[0]->content);

        $nonMatchResult = app(AiRetrievalEngine::class)->retrieve(new AiRetrievalQuery(
            owner: $owner,
            queryText: 'leave policy generally but worded completely differently',
            mode: AiRetrievalMode::Exact,
        ));

        $this->assertTrue($nonMatchResult->isEmpty());
    }

    public function test_hybrid_mode_falls_back_to_keyword_only_without_embeddings(): void
    {
        $owner = $this->makeOwner('M');
        $this->makeIndexedFile($owner, 'policy', "# Leave Policy\n\nEmployees get 21 days of annual leave per year.");

        // auto_embed defaults false, so no chunk has a real embedding -
        // hybrid mode must still return real keyword-scored results,
        // never silently return nothing just because semantic scoring
        // had nothing to contribute.
        $result = app(AiRetrievalEngine::class)->retrieve(new AiRetrievalQuery(
            owner: $owner,
            queryText: 'How many days of annual leave?',
            mode: AiRetrievalMode::Hybrid,
        ));

        $this->assertFalse($result->isEmpty());
        $this->assertSame('keyword', $result->results[0]->retrievalMethod);
    }
}
