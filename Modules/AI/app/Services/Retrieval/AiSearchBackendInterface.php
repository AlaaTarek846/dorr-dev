<?php

namespace Modules\AI\Services\Retrieval;

use Modules\AI\Models\AiFileChunk;

/**
 * Phase 9 (doc S6/S26): AiRetrievalEngine never queries Eloquent, runs
 * raw SQL, calls a provider, or assumes a particular vector database
 * directly - it only ever goes through this interface, so a new search
 * backend (a real ANN index, a provider-managed file-search product)
 * can be added later without AiRetrievalEngine itself changing.
 *
 * Candidate-chunk FETCHING (the authorization-bearing, bounded DB
 * query) deliberately happens ONCE in AiRetrievalEngine, not inside
 * each backend - every backend only SCORES a candidate it is handed,
 * which is what AiKnowledgeRetriever's own hybrid-scoring precedent
 * already does and avoids either (a) N separate backend queries against
 * the same table, or (b) a backend accidentally issuing its own
 * unscoped query and bypassing the authorization boundary the engine
 * already enforced when it built the candidate set.
 */
interface AiSearchBackendInterface
{
    /**
     * A short, stable identifier ('keyword', 'semantic') stored on
     * AiRetrievedChunk::$retrievalMethod and logged - never user-facing.
     */
    public function key(): string;

    /**
     * Whether this backend can score anything right now (e.g. the
     * semantic backend needs a resolvable, embeddings-capable provider -
     * doc S8: "do not pretend database rows contain vectors if they do
     * not" extends to not pretending a backend is usable when it isn't).
     */
    public function isAvailable(AiRetrievalQuery $query): bool;

    /**
     * Scores one candidate against the query text. Returns null when
     * this backend genuinely has nothing to say about this chunk (e.g.
     * the semantic backend on a chunk with no stored embedding) -
     * distinct from a real score of 0.0, so the caller never treats
     * "not scored" as "scored irrelevant".
     */
    public function score(AiRetrievalQuery $query, AiFileChunk $chunk, string $content): ?float;
}
