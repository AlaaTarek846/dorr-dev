<?php

namespace Modules\AI\Services\Chunking\Strategies;

use Modules\AI\Services\Chunking\AiChunkerInterface;
use Modules\AI\Services\Chunking\Concerns\ChunksNormalizedBlocks;

/**
 * Phase 8 (doc S11-S18): handles the "document-family" normalized
 * content produced by PdfFileProcessor/WordFileProcessor and any other
 * processor whose document_type is plain text/markdown - anything
 * whose normalized shape is the flat block list already covered by
 * ChunksNormalizedBlocks. HtmlChunker (below) reuses the exact same
 * trait rather than duplicating this logic, differing only in which
 * document_type values it claims.
 */
class DocumentChunker implements AiChunkerInterface
{
    use ChunksNormalizedBlocks;

    // Real document_type values these processors actually emit
    // (confirmed by inspection - 'word'/'text'/'rtf' were wrong guesses,
    // no RtfFileProcessor exists in this codebase).
    private const SUPPORTED_TYPES = ['pdf', 'doc', 'docx', 'txt', 'markdown'];

    public function supports(array $normalizedContent): bool
    {
        $type = $normalizedContent['document_type'] ?? null;

        if (in_array($type, self::SUPPORTED_TYPES, true)) {
            return true;
        }

        // A processor we don't explicitly recognize but which still
        // produced the same flat block shape - doc S10's own rule that
        // adding a new strategy later must not require touching the
        // others cuts both ways: an unrecognized document_type with
        // real blocks should still chunk, rather than silently falling
        // through to "unsupported".
        return $type === null && ! empty($normalizedContent['blocks']);
    }

    public function chunk(array $normalizedContent): array
    {
        $blocks = $normalizedContent['blocks'] ?? [];

        if ($blocks === [] && ($normalizedContent['text'] ?? '') !== '') {
            // No structured blocks at all (e.g. a plain .txt file) -
            // fall back to one synthetic paragraph block so the same
            // block-walking code path (and its hard-split-if-too-large
            // safety net) still applies.
            $blocks = [['type' => 'paragraph', 'text' => $normalizedContent['text']]];
        }

        return $this->chunkBlocks(
            $blocks,
            'document',
            (int) config('ai.chunking.document.max_characters', 2000),
            (int) config('ai.chunking.document.min_characters', 20),
            (int) config('ai.chunking.document.overlap_blocks', 1),
            (int) config('ai.chunking.document.table_rows_per_chunk', 50),
        );
    }
}
