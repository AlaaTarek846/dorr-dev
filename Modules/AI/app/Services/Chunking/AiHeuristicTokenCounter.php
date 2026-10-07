<?php

namespace Modules\AI\Services\Chunking;

/**
 * Phase 8: formalizes the SAME heuristic
 * `AiKnowledgeIngestionService::indexChunks()` already used inline
 * (`ceil(mb_strlen($text) / 4)`) into a real, reusable, honestly-named
 * abstraction - doc S14 is explicit that character count must never be
 * presented as a real token count. ~4 characters per token is a common,
 * documented rough estimate for English; it is measurably less accurate
 * for Arabic (more bytes/characters per token on average for non-Latin
 * scripts) - this is disclosed in this phase's Final Report rather than
 * silently assumed to be equally accurate for both.
 *
 * No real tokenizer library (e.g. a BPE/tiktoken-compatible one) exists
 * anywhere in this codebase (confirmed by inspection of composer.json
 * and a module-wide search) - this is therefore the ONLY
 * AiTokenCounterInterface implementation this phase provides, and
 * `isExact()` honestly returns false.
 */
class AiHeuristicTokenCounter implements AiTokenCounterInterface
{
    public function estimateTokenCount(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        return (int) ceil(mb_strlen($text) / 4);
    }

    public function isExact(): bool
    {
        return false;
    }
}
