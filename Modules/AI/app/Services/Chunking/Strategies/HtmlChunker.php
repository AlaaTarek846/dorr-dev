<?php

namespace Modules\AI\Services\Chunking\Strategies;

use Modules\AI\Services\Chunking\AiChunkerInterface;
use Modules\AI\Services\Chunking\Concerns\ChunksNormalizedBlocks;

/**
 * Phase 8 (doc S19): HtmlFileProcessor already strips scripts/styles/
 * tracking elements and normalizes to the same block shape as the
 * document family (confirmed by inspection) - so this is a thin
 * wrapper around the same shared trait, never a raw-markup chunker.
 */
class HtmlChunker implements AiChunkerInterface
{
    use ChunksNormalizedBlocks;

    public function supports(array $normalizedContent): bool
    {
        return ($normalizedContent['document_type'] ?? null) === 'html';
    }

    public function chunk(array $normalizedContent): array
    {
        return $this->chunkBlocks(
            $normalizedContent['blocks'] ?? [],
            'html',
            (int) config('ai.chunking.document.max_characters', 2000),
            (int) config('ai.chunking.document.min_characters', 20),
            (int) config('ai.chunking.document.overlap_blocks', 1),
            (int) config('ai.chunking.document.table_rows_per_chunk', 50),
        );
    }
}
