<?php

namespace Modules\AI\Services\Chunking\Concerns;

use Modules\AI\Services\AiTextChunker;
use Modules\AI\Services\Chunking\AiChunkDraft;

/**
 * Phase 8 (doc S11/S12/S17/S18/S20): shared block-walking chunking logic
 * for any normalized content shaped as a flat list of document blocks
 * (heading/paragraph/list/quote/code/table/link/image_reference - the
 * exact shape PdfFileProcessor/WordFileProcessor/HtmlFileProcessor all
 * already produce, confirmed by inspection). Used by both
 * `DocumentChunker` and `HtmlChunker` rather than duplicated between
 * them - they differ only in which `document_type` values they claim.
 *
 * Chunking preference (doc S11): a heading starts a new section and
 * flushes whatever was buffered; consecutive paragraph/list/quote/code
 * blocks accumulate into one chunk up to `max_characters`; a single
 * block too large on its own is hard-split via the existing
 * AiTextChunker (never truncated, never silently dropped); a table is
 * always flushed as its own chunk(s), split by row groups (doc S16)
 * rather than ever being flattened into the surrounding prose buffer.
 */
trait ChunksNormalizedBlocks
{
    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<AiChunkDraft>
     */
    protected function chunkBlocks(array $blocks, string $contentType, int $maxCharacters, int $minCharacters, int $overlapBlocks, int $tableRowsPerChunk): array
    {
        $chunker = app(AiTextChunker::class);
        $drafts = [];
        $index = 0;

        $buffer = [];
        $bufferPages = [];
        $currentSection = null;

        $flush = function () use (&$buffer, &$bufferPages, &$currentSection, &$drafts, &$index, $contentType, $minCharacters, $overlapBlocks) {
            if ($buffer === []) {
                return;
            }

            $text = trim(implode("\n\n", array_column($buffer, 'text')));

            if (mb_strlen($text) >= $minCharacters) {
                $pages = array_values(array_unique(array_filter($bufferPages)));

                $drafts[] = new AiChunkDraft($index++, $text, $contentType, array_filter([
                    'section' => $currentSection,
                    'pages' => $pages !== [] ? $pages : null,
                    'primary_page' => $pages[0] ?? null,
                ], fn ($v) => $v !== null));
            }

            // Doc S15's own worked example: the overlap carried forward
            // is the trailing $overlapBlocks whole blocks, not a partial-
            // character slice - a semantic boundary is never cut
            // mid-sentence just to satisfy an overlap byte count.
            $buffer = $overlapBlocks > 0 ? array_slice($buffer, -$overlapBlocks) : [];
            $bufferPages = array_map(fn ($b) => $b['page'] ?? null, $buffer);
        };

        foreach ($blocks as $block) {
            $type = $block['type'] ?? null;
            $page = $block['source']['page'] ?? null;

            if ($type === 'heading') {
                $flush();
                $currentSection = (string) ($block['text'] ?? '');
                $buffer[] = ['text' => $this->blockToPlainText($block), 'page' => $page];

                continue;
            }

            if ($type === 'table') {
                $flush();

                foreach ($this->tableToChunkTexts($block, $tableRowsPerChunk) as $tableText) {
                    $drafts[] = new AiChunkDraft($index++, $tableText, 'table', array_filter([
                        'section' => $currentSection,
                        'pages' => $page !== null ? [$page] : null,
                        'primary_page' => $page,
                    ], fn ($v) => $v !== null));
                }

                continue;
            }

            if (! in_array($type, ['paragraph', 'list', 'quote', 'code', 'link'], true)) {
                // image_reference and anything else未知: not meaningful
                // standalone searchable text (doc S29 handles images
                // separately) - skipped from the text buffer, not an
                // error.
                continue;
            }

            $blockText = $this->blockToPlainText($block);

            if ($blockText === '') {
                continue;
            }

            // Doc S12: a single block too large on its own is hard-split
            // rather than ever producing one giant chunk.
            if (mb_strlen($blockText) > $maxCharacters * 2) {
                $flush();

                foreach ($chunker->chunk($blockText, $maxCharacters, 0) as $piece) {
                    $drafts[] = new AiChunkDraft($index++, $piece, $contentType, array_filter([
                        'section' => $currentSection,
                        'pages' => $page !== null ? [$page] : null,
                        'primary_page' => $page,
                    ], fn ($v) => $v !== null));
                }

                continue;
            }

            $currentBufferLength = array_sum(array_map(fn ($b) => mb_strlen($b['text']), $buffer));

            if ($buffer !== [] && $currentBufferLength + mb_strlen($blockText) > $maxCharacters) {
                $flush();
            }

            $buffer[] = ['text' => $blockText, 'page' => $page];
            $bufferPages[] = $page;
        }

        $flush();

        return $drafts;
    }

    protected function blockToPlainText(array $block): string
    {
        return match ($block['type'] ?? null) {
            'heading' => str_repeat('#', max(1, (int) ($block['level'] ?? 1))).' '.($block['text'] ?? ''),
            'paragraph', 'quote', 'code', 'link' => (string) ($block['text'] ?? ''),
            'list' => implode("\n", array_map(fn ($item) => '- '.$item, (array) ($block['items'] ?? []))),
            default => '',
        };
    }

    /**
     * Doc S16: a large table is split by row groups while the header
     * row is repeated at the top of every group - never flattened into
     * one giant string, never silently dropped.
     *
     * @return list<string>
     */
    protected function tableToChunkTexts(array $block, int $rowsPerChunk): array
    {
        $headers = (array) ($block['headers'] ?? []);
        $rows = (array) ($block['rows'] ?? []);

        if ($rows === []) {
            return $headers !== [] ? [implode(' | ', $headers)] : [];
        }

        $texts = [];

        foreach (array_chunk($rows, max(1, $rowsPerChunk)) as $rowGroup) {
            $lines = $headers !== [] ? [implode(' | ', $headers)] : [];

            foreach ($rowGroup as $row) {
                $lines[] = implode(' | ', array_map(fn ($cell) => (string) $cell, array_values((array) $row)));
            }

            $texts[] = implode("\n", $lines);
        }

        return $texts;
    }
}
