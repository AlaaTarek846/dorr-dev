<?php

namespace Modules\AI\Services\Chunking\Strategies;

use Modules\AI\Services\Chunking\AiChunkDraft;
use Modules\AI\Services\Chunking\AiChunkerInterface;

/**
 * Phase 8 (doc S24): dedicated spreadsheet strategy - chunks by
 * workbook -> sheet -> header -> row groups, reusing the already
 * fully-structured `metadata['sheets']` shape ExcelFileProcessor
 * produces (confirmed by inspection: name/headers/rows[with
 * _row_number]/row_count/truncated). Never treats XLSX as ordinary
 * paragraphs, never re-parses the file.
 */
class SpreadsheetChunker implements AiChunkerInterface
{
    private const SUPPORTED_TYPES = ['xlsx', 'xls', 'csv', 'tsv'];

    public function supports(array $normalizedContent): bool
    {
        if (in_array($normalizedContent['document_type'] ?? null, self::SUPPORTED_TYPES, true)) {
            return true;
        }

        // Fallback: any normalized content carrying the sheets shape,
        // regardless of document_type label (doc S10's own rule that a
        // new processor should not require editing every chunker).
        return ! empty($normalizedContent['metadata']['sheets']);
    }

    public function chunk(array $normalizedContent): array
    {
        $sheets = $normalizedContent['metadata']['sheets'] ?? [];
        $rowsPerChunk = max(1, (int) config('ai.chunking.spreadsheet.rows_per_chunk', 100));

        $drafts = [];
        $index = 0;

        foreach ($sheets as $sheet) {
            $sheetName = (string) ($sheet['name'] ?? 'Sheet');
            $headers = (array) ($sheet['headers'] ?? []);
            $rows = (array) ($sheet['rows'] ?? []);

            if ($rows === []) {
                $drafts[] = new AiChunkDraft($index++, "Sheet: {$sheetName}\n(empty)", 'spreadsheet', [
                    'sheet_name' => $sheetName,
                    'row_start' => null,
                    'row_end' => null,
                ]);

                continue;
            }

            // Doc S24's own worked format: "Sheet: X\nColumns:\n...\nRows:\n...".
            // Streamed by row-group rather than ever materializing every
            // row of a huge sheet into one PHP string at once.
            foreach (array_chunk($rows, $rowsPerChunk) as $rowGroup) {
                $rowStart = $rowGroup[0]['_row_number'] ?? null;
                $rowEnd = $rowGroup[array_key_last($rowGroup)]['_row_number'] ?? null;

                $lines = ["Sheet: {$sheetName}"];

                if ($headers !== []) {
                    $lines[] = 'Columns:';
                    $lines[] = implode(' | ', $headers);
                }

                $lines[] = 'Rows:';

                foreach ($rowGroup as $row) {
                    $cells = $row;
                    unset($cells['_row_number']);
                    $lines[] = implode(' | ', array_map(fn ($v) => (string) $v, array_values($cells)));
                }

                $drafts[] = new AiChunkDraft($index++, implode("\n", $lines), 'spreadsheet', array_filter([
                    'sheet_name' => $sheetName,
                    'row_start' => $rowStart,
                    'row_end' => $rowEnd,
                ], fn ($v) => $v !== null));
            }
        }

        return $drafts;
    }
}
