<?php

namespace Modules\AI\Services\Chunking;

/**
 * Phase 8 (doc S14): a clean abstraction so the chunking engine never
 * silently pretends a character count IS a token count. Every consumer
 * of `estimateTokenCount()` must treat the result as an ESTIMATE -
 * `isExact()` tells the caller whether to trust it as precise (no
 * implementation in this phase returns true - no real tokenizer library
 * exists in this codebase, confirmed by inspection; see
 * AiHeuristicTokenCounter's own docblock).
 */
interface AiTokenCounterInterface
{
    /**
     * An estimated token count for $text - never claimed as exact unless
     * `isExact()` also returns true for this implementation.
     */
    public function estimateTokenCount(string $text): int;

    /**
     * Whether estimateTokenCount() returns a real, exact token count
     * (true only for an implementation backed by a real tokenizer this
     * codebase does not currently have) or a heuristic estimate (false).
     */
    public function isExact(): bool;
}
