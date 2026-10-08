<?php

namespace Modules\AI\Services\Retrieval;

use Modules\AI\Models\AiFileChunk;

/**
 * Phase 9 (doc S25): one scored retrieval result. `sourceReference`
 * is the structured citation shape (doc S21) - built once here so
 * AiContextBuilder and the chat layer never have to re-derive it from
 * raw chunk metadata.
 */
class AiRetrievedChunk
{
    public function __construct(
        public readonly AiFileChunk $chunk,
        public readonly string $content,
        public readonly float $score,
        public readonly int $rank,
        public readonly string $retrievalMethod,
        public readonly array $sourceReference,
    ) {}
}
