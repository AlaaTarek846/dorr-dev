<?php

namespace Modules\AI\Tests\Unit\Chunking;

use Modules\AI\Services\Chunking\Strategies\SpreadsheetChunker;
use Tests\TestCase;

class SpreadsheetChunkerTest extends TestCase
{
    public function test_supports_normalized_sheets_metadata(): void
    {
        $chunker = new SpreadsheetChunker;

        $this->assertTrue($chunker->supports(['metadata' => ['sheets' => [['name' => 'Sales']]]]));
        $this->assertFalse($chunker->supports(['metadata' => []]));
    }

    public function test_chunks_preserve_sheet_name_and_row_range(): void
    {
        config(['ai.chunking.spreadsheet.rows_per_chunk' => 2]);

        $chunker = new SpreadsheetChunker;

        $drafts = $chunker->chunk([
            'metadata' => [
                'sheets' => [[
                    'name' => 'Sales',
                    'headers' => ['Date', 'Product', 'Amount'],
                    'rows' => [
                        ['_row_number' => 2, 'Date' => '2026-01-01', 'Product' => 'A', 'Amount' => '100'],
                        ['_row_number' => 3, 'Date' => '2026-01-02', 'Product' => 'B', 'Amount' => '200'],
                        ['_row_number' => 4, 'Date' => '2026-01-03', 'Product' => 'C', 'Amount' => '300'],
                    ],
                ]],
            ],
        ]);

        $this->assertCount(2, $drafts);
        $this->assertSame('Sales', $drafts[0]->metadata['sheet_name']);
        $this->assertSame(2, $drafts[0]->metadata['row_start']);
        $this->assertSame(3, $drafts[0]->metadata['row_end']);
        $this->assertStringContainsString('Sheet: Sales', $drafts[0]->content);
        $this->assertStringContainsString('Date | Product | Amount', $drafts[0]->content);
        $this->assertSame(4, $drafts[1]->metadata['row_start']);
    }

    public function test_empty_sheet_still_produces_a_placeholder_chunk(): void
    {
        $chunker = new SpreadsheetChunker;

        $drafts = $chunker->chunk([
            'metadata' => ['sheets' => [['name' => 'Empty', 'headers' => [], 'rows' => []]]],
        ]);

        $this->assertCount(1, $drafts);
        $this->assertStringContainsString('Empty', $drafts[0]->content);
    }
}
