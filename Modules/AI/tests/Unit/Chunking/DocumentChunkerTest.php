<?php

namespace Modules\AI\Tests\Unit\Chunking;

use Modules\AI\Services\Chunking\Strategies\DocumentChunker;
use Tests\TestCase;

/**
 * Laravel-booted (Tests\TestCase) because DocumentChunker's shared
 * ChunksNormalizedBlocks trait reads `config('ai.chunking.document.*')`
 * and resolves AiTextChunker out of the container.
 */
class DocumentChunkerTest extends TestCase
{
    public function test_supports_known_document_types(): void
    {
        $chunker = new DocumentChunker;

        $this->assertTrue($chunker->supports(['document_type' => 'pdf', 'blocks' => []]));
        $this->assertTrue($chunker->supports(['document_type' => 'docx', 'blocks' => []]));
        $this->assertTrue($chunker->supports(['document_type' => 'doc', 'blocks' => []]));
        $this->assertFalse($chunker->supports(['document_type' => 'spreadsheet', 'blocks' => []]));
    }

    public function test_heading_starts_a_new_chunk_and_is_recorded_as_section(): void
    {
        $chunker = new DocumentChunker;

        $drafts = $chunker->chunk([
            'document_type' => 'pdf',
            'blocks' => [
                ['type' => 'heading', 'level' => 1, 'text' => 'Leave Policy', 'source' => ['page' => 4]],
                ['type' => 'paragraph', 'text' => 'Employees get 21 days of annual leave.', 'source' => ['page' => 4]],
            ],
        ]);

        $this->assertNotEmpty($drafts);
        $this->assertSame('Leave Policy', $drafts[0]->metadata['section'] ?? null);
        $this->assertSame([4], $drafts[0]->metadata['pages'] ?? null);
    }

    public function test_table_block_becomes_its_own_chunk_never_merged_with_prose(): void
    {
        $chunker = new DocumentChunker;

        $drafts = $chunker->chunk([
            'document_type' => 'docx',
            'blocks' => [
                ['type' => 'paragraph', 'text' => 'Intro paragraph.'],
                ['type' => 'table', 'headers' => ['Name', 'Age'], 'rows' => [['Ali', '30'], ['Sara', '25']]],
                ['type' => 'paragraph', 'text' => 'Closing paragraph.'],
            ],
        ]);

        $tableDrafts = array_values(array_filter($drafts, fn ($d) => $d->contentType === 'table'));

        $this->assertNotEmpty($tableDrafts);
        $this->assertStringContainsString('Name | Age', $tableDrafts[0]->content);
        $this->assertStringContainsString('Ali | 30', $tableDrafts[0]->content);
    }

    public function test_large_table_is_split_by_row_groups_with_repeated_headers(): void
    {
        $chunker = new DocumentChunker;
        config(['ai.chunking.document.table_rows_per_chunk' => 50]);

        $rows = [];
        for ($i = 1; $i <= 120; $i++) {
            $rows[] = ["Row {$i}", (string) $i];
        }

        $drafts = $chunker->chunk([
            'document_type' => 'pdf',
            'blocks' => [
                ['type' => 'table', 'headers' => ['Label', 'Value'], 'rows' => $rows],
            ],
        ]);

        // 120 rows / 50 per chunk => 3 table chunks, each starting with the header line.
        $this->assertCount(3, $drafts);

        foreach ($drafts as $draft) {
            $this->assertStringStartsWith('Label | Value', $draft->content);
        }
    }

    public function test_oversized_single_block_is_hard_split_not_one_giant_chunk(): void
    {
        $chunker = new DocumentChunker;
        config(['ai.chunking.document.max_characters' => 200]);

        $longText = trim(str_repeat('This is a long sentence about the policy details. ', 80));

        $drafts = $chunker->chunk([
            'document_type' => 'pdf',
            'blocks' => [
                ['type' => 'paragraph', 'text' => $longText],
            ],
        ]);

        $this->assertGreaterThan(1, count($drafts));

        foreach ($drafts as $draft) {
            $this->assertLessThanOrEqual(400, mb_strlen($draft->content));
        }
    }

    public function test_arabic_text_is_preserved_without_corruption(): void
    {
        $chunker = new DocumentChunker;

        $drafts = $chunker->chunk([
            'document_type' => 'docx',
            'blocks' => [
                ['type' => 'paragraph', 'text' => 'هذه فقرة تجريبية تحتوي على نص عربي كامل.'],
            ],
        ]);

        $this->assertNotEmpty($drafts);
        $this->assertStringContainsString('نص عربي', $drafts[0]->content);
    }

    public function test_image_reference_block_is_skipped_not_turned_into_text(): void
    {
        $chunker = new DocumentChunker;

        $drafts = $chunker->chunk([
            'document_type' => 'docx',
            'blocks' => [
                ['type' => 'paragraph', 'text' => 'Before image.'],
                ['type' => 'image_reference', 'index' => 0],
                ['type' => 'paragraph', 'text' => 'After image.'],
            ],
        ]);

        foreach ($drafts as $draft) {
            $this->assertStringNotContainsString('image_reference', $draft->content);
        }
    }

    public function test_empty_blocks_produce_no_drafts(): void
    {
        $chunker = new DocumentChunker;

        $this->assertSame([], $chunker->chunk(['document_type' => 'pdf', 'blocks' => []]));
    }
}
