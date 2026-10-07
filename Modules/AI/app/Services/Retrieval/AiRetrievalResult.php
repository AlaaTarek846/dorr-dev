<?php

namespace Modules\AI\Services\Retrieval;

use Modules\AI\Enums\AiRetrievalMode;

/**
 * Phase 9 (doc S25): the structured object AiRetrievalEngine::retrieve()
 * returns - never an arbitrary array (doc S25's own "avoid returning
 * arbitrary arrays throughout the application").
 */
class AiRetrievalResult
{
    /**
     * @param  list<AiRetrievedChunk>  $results
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public readonly string $query,
        public readonly AiRetrievalMode $mode,
        public readonly array $results,
        public readonly int $totalCandidates,
        public readonly int $returnedCount,
        public readonly array $filters,
        public readonly float $timingMs,
        public readonly string $backend,
        // Phase 10 (doc S23): {searched_file_ids, matched_file_ids,
        // unmatched_file_ids} - which of the files that were actually
        // searched ended up contributing a result, for multi-file
        // debugging/coverage. Never includes a file id the caller was
        // not authorized to search in the first place (those are
        // excluded upstream, before this result even exists - doc S35).
        public readonly array $fileCoverage = [],
    ) {}

    public static function empty(string $query, AiRetrievalMode $mode, array $filters = [], string $backend = 'none'): self
    {
        return new self($query, $mode, [], 0, 0, $filters, 0.0, $backend);
    }

    public function isEmpty(): bool
    {
        return $this->results === [];
    }
}
