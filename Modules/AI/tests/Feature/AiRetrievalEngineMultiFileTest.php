<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Enums\AiRetrievalMode;
use Modules\AI\Jobs\ProcessAiFileJob;
use Modules\AI\Models\AiFile;
use Modules\AI\Services\Chunking\AiChunkingEngine;
use Modules\AI\Services\FileProcessors\AiFileProcessorManager;
use Modules\AI\Services\Indexing\AiIndexingEngine;
use Modules\AI\Services\Retrieval\AiRetrievalEngine;
use Modules\AI\Services\Retrieval\AiRetrievalQuery;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Phase 10 (doc S6/S7/S23/S39): multi-file retrieval - two or more
 * authorized files searched in one call, file diversity capping one
 * file's dominance of the final top_k, and file coverage reporting -
 * against the REAL chunking/indexing pipeline, same convention as
 * Phase 9's own AiRetrievalEngineTest.
 */
class AiRetrievalEngineMultiFileTest extends TestCase
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
            'name' => "Multi-file Test Owner {$label}",
            'email' => 'multi-file-test-'.$label.'-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);
    }

    /** Storage::fake() wipes the disk, so it must run once per test, never once per file. */
    protected bool $storageFaked = false;

    protected function makeIndexedFile(User $owner, string $name, string $content): AiFile
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

    public function test_searches_across_two_authorized_files_at_once(): void
    {
        $owner = $this->makeOwner('A');
        $contract = $this->makeIndexedFile($owner, 'contract', "# Contract\n\nPayment terms require 30 days net on all invoices.");
        $proposal = $this->makeIndexedFile($owner, 'proposal', "# Proposal\n\nOur pricing proposal includes a 10% discount on payment terms.");

        $result = app(AiRetrievalEngine::class)->retrieve(new AiRetrievalQuery(
            owner: $owner,
            queryText: 'What do these files say about payment terms?',
            mode: AiRetrievalMode::Keyword,
            fileIds: [$contract->id, $proposal->id],
        ));

        $this->assertFalse($result->isEmpty());

        $matchedFileIds = array_unique(array_map(fn ($r) => $r->chunk->file_id, $result->results));
        $this->assertContains($contract->id, $matchedFileIds);
        $this->assertContains($proposal->id, $matchedFileIds);
    }

    public function test_file_coverage_reports_searched_matched_and_unmatched_files(): void
    {
        $owner = $this->makeOwner('B');
        $relevant = $this->makeIndexedFile($owner, 'relevant', "# Notes\n\nThe quarterly revenue figures are detailed here.");
        $irrelevant = $this->makeIndexedFile($owner, 'irrelevant', "# Unrelated\n\nThis file is about office furniture and has nothing to do with money.");

        $result = app(AiRetrievalEngine::class)->retrieve(new AiRetrievalQuery(
            owner: $owner,
            queryText: 'quarterly revenue figures',
            mode: AiRetrievalMode::Keyword,
            fileIds: [$relevant->id, $irrelevant->id],
        ));

        $searched = $result->fileCoverage['searched_file_ids'];
        sort($searched);
        $expectedSearched = [$relevant->id, $irrelevant->id];
        sort($expectedSearched);
        $this->assertSame($expectedSearched, $searched);

        $this->assertContains($relevant->id, $result->fileCoverage['matched_file_ids']);
        $this->assertNotContains($relevant->id, $result->fileCoverage['unmatched_file_ids']);
    }

    public function test_diversity_cap_limits_a_single_dominant_files_share_of_top_k(): void
    {
        config(['ai.retrieval.multi_file.diversity.enabled' => true]);
        config(['ai.retrieval.multi_file.diversity.max_chunks_per_file' => 1]);

        // Small chunks so each paragraph becomes its own chunk (the default 2000 would merge all six).
        config(['ai.chunking.document.max_characters' => 100]);

        $owner = $this->makeOwner('C');

        $paragraphs = [];
        for ($i = 1; $i <= 6; $i++) {
            $paragraphs[] = "Paragraph {$i} repeatedly discusses the quarterly budget review in detail.";
        }
        $dominant = $this->makeIndexedFile($owner, 'dominant', "# Budget\n\n".implode("\n\n", $paragraphs));
        $other = $this->makeIndexedFile($owner, 'other', "# Other\n\nA brief note that also touches on the quarterly budget review.");

        $result = app(AiRetrievalEngine::class)->retrieve(new AiRetrievalQuery(
            owner: $owner,
            queryText: 'quarterly budget review',
            mode: AiRetrievalMode::Keyword,
            fileIds: [$dominant->id, $other->id],
            topK: 2,
        ));

        $countsByFile = [];
        foreach ($result->results as $r) {
            $countsByFile[$r->chunk->file_id] = ($countsByFile[$r->chunk->file_id] ?? 0) + 1;
        }

        $this->assertLessThanOrEqual(1, $countsByFile[$dominant->id] ?? 0, 'the dominant file must not occupy more than max_chunks_per_file slots.');
        $this->assertArrayHasKey($other->id, $countsByFile, 'diversity must let the other relevant file through instead of being crowded out entirely.');
    }

    public function test_diversity_disabled_falls_back_to_plain_global_ranking(): void
    {
        config(['ai.retrieval.multi_file.diversity.enabled' => false]);

        // Small chunks so each paragraph becomes its own chunk (the default 2000 would merge all six).
        config(['ai.chunking.document.max_characters' => 100]);

        $owner = $this->makeOwner('D');

        $paragraphs = [];
        for ($i = 1; $i <= 6; $i++) {
            $paragraphs[] = "Paragraph {$i} repeatedly discusses the quarterly budget review in detail.";
        }
        $dominant = $this->makeIndexedFile($owner, 'dominant', "# Budget\n\n".implode("\n\n", $paragraphs));
        $other = $this->makeIndexedFile($owner, 'other', "# Other\n\nA brief note that also touches on the quarterly budget review.");

        $result = app(AiRetrievalEngine::class)->retrieve(new AiRetrievalQuery(
            owner: $owner,
            queryText: 'quarterly budget review',
            mode: AiRetrievalMode::Keyword,
            fileIds: [$dominant->id, $other->id],
            topK: 4,
        ));

        $this->assertSame(4, $result->returnedCount);
    }

    public function test_results_remain_deduplicated_across_files(): void
    {
        $owner = $this->makeOwner('E');
        $fileA = $this->makeIndexedFile($owner, 'a', "# A\n\nThe payment terms are net 30 days.");
        $fileB = $this->makeIndexedFile($owner, 'b', "# B\n\nA second, different document about payment terms and scheduling.");

        $result = app(AiRetrievalEngine::class)->retrieve(new AiRetrievalQuery(
            owner: $owner,
            queryText: 'payment terms',
            mode: AiRetrievalMode::Keyword,
            fileIds: [$fileA->id, $fileB->id],
        ));

        $checksums = array_map(fn ($r) => $r->chunk->checksum, $result->results);
        $this->assertSame(count($checksums), count(array_unique($checksums)));
    }

    public function test_an_empty_explicit_file_ids_array_never_falls_back_to_a_wider_scope(): void
    {
        $owner = $this->makeOwner('F');
        $this->makeIndexedFile($owner, 'solo', "# Solo\n\nThis file discusses payment terms extensively.");

        // Hardening regression: an explicit, empty fileIds array must
        // mean "search nothing", never silently widen to conversationId
        // or global scope.
        $result = app(AiRetrievalEngine::class)->retrieve(new AiRetrievalQuery(
            owner: $owner,
            queryText: 'payment terms',
            mode: AiRetrievalMode::Keyword,
            fileIds: [],
        ));

        $this->assertTrue($result->isEmpty());
    }
}
