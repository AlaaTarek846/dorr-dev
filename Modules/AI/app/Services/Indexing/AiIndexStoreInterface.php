<?php

namespace Modules\AI\Services\Indexing;

use Modules\AI\Models\AiFileChunk;

/**
 * Phase 8 (doc S37): abstraction over where indexed chunks actually
 * live. `DatabaseIndexStore` (the only implementation built this phase)
 * operates directly on the `ai_file_chunks` table itself - there is no
 * separate "index" table, mirroring the same "avoid a redundant status
 * column" reasoning Phase 1 already used for `ai_files`.
 *
 * Reserved for future implementers (not built this phase, per the doc's
 * own instruction not to assume one vector database):
 * - VectorIndexStore: would write embeddings into a dedicated vector DB
 *   (pgvector, a hosted vector service, etc).
 * - ProviderManagedIndexStore: would delegate to a provider's own
 *   managed file-search/retrieval index (e.g. an LLM vendor's file
 *   search product) instead of storing vectors locally at all.
 * Phase 9 picks which backend(s) a conversation actually queries - this
 * phase only makes the choice swappable.
 */
interface AiIndexStoreInterface
{
    /**
     * @param  list<AiFileChunk>  $chunks
     */
    public function index(array $chunks): void;

    public function remove(int $fileId, ?int $contentVersion = null): void;

    public function markStale(int $fileId, int $belowContentVersion): void;
}
