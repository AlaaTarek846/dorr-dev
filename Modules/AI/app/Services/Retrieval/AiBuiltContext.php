<?php

namespace Modules\AI\Services\Retrieval;

/**
 * Phase 9 (doc S18): the output of AiContextBuilder - a ready-to-inject
 * text block plus the structured citations that back it, and enough
 * bookkeeping (character/token counts, whether the budget cut it off)
 * for logging/observability (doc S32) without re-deriving them.
 */
class AiBuiltContext
{
    /**
     * @param  list<array<string, mixed>>  $citations
     */
    public function __construct(
        public readonly ?string $text,
        public readonly array $citations,
        public readonly int $chunkCount,
        public readonly int $characterCount,
        public readonly int $estimatedTokenCount,
        public readonly bool $truncatedByBudget,
    ) {}

    public function isEmpty(): bool
    {
        return $this->text === null || $this->citations === [];
    }
}
