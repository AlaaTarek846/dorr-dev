<?php

namespace Modules\AI\Services\Retrieval;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\AI\Enums\AiRetrievalMode;

/**
 * Phase 9 (doc S25): the input to AiRetrievalEngine/AiSearchBackendInterface
 * - carries the authenticated actor directly (doc S14: "the retrieval
 * engine should accept the authenticated actor/context") so every
 * backend is handed the authorization boundary explicitly rather than
 * trusting a caller to have already filtered.
 */
class AiRetrievalQuery
{
    /**
     * @param  ?list<int>  $fileIds  Explicit file scope (doc S15.A/B). Null means "resolve from $conversationId" (doc S15.C) or the owner's whole indexed library if that is also absent and allowed (doc S15.D, config-gated).
     * @param  array<string, mixed>  $filters  content_type/page/sheet/slide/section/timestamp-range/chunk version/status - doc S13. Only keys that exist in the real data model are honored.
     */
    public function __construct(
        public readonly Authenticatable $owner,
        public readonly string $queryText,
        public readonly AiRetrievalMode $mode = AiRetrievalMode::Hybrid,
        public readonly ?int $conversationId = null,
        public readonly ?array $fileIds = null,
        public readonly array $filters = [],
        public readonly ?int $topK = null,
        public readonly ?int $candidateK = null,
    ) {}
}
