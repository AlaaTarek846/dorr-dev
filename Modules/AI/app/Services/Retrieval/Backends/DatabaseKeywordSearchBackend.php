<?php

namespace Modules\AI\Services\Retrieval\Backends;

use Modules\AI\Models\AiFileChunk;
use Modules\AI\Services\Retrieval\AiRetrievalQuery;
use Modules\AI\Services\Retrieval\AiSearchBackendInterface;
use Modules\AI\Services\Retrieval\Concerns\ScoresLexicalOverlap;

/**
 * Phase 9 (doc S7): REAL keyword/full-text retrieval against the Phase
 * 8 DatabaseIndexStore (ai_file_chunks) - not a MySQL-only FULLTEXT
 * index (this project's tests run on sqlite - see phpunit.xml - and a
 * FULLTEXT MATCH()/AGAINST() clause would silently never run there),
 * and not a fake stand-in: this does real exact/partial/phrase
 * matching via token overlap, computed once per candidate against its
 * REAL stored content.
 *
 * Always available - this is the baseline every retrieval mode can
 * fall back to.
 */
class DatabaseKeywordSearchBackend implements AiSearchBackendInterface
{
    use ScoresLexicalOverlap;

    public function key(): string
    {
        return 'keyword';
    }

    public function isAvailable(AiRetrievalQuery $query): bool
    {
        return true;
    }

    public function score(AiRetrievalQuery $query, AiFileChunk $chunk, string $content): ?float
    {
        $queryTokens = $this->tokenize($query->queryText);
        $contentTokens = $this->tokenize($content);

        // Doc S7's "phrase matching where practical": an exact
        // substring hit (case-insensitive, the literal phrase the user
        // typed) is a strong, deliberate signal beyond plain token
        // overlap - boosted rather than left indistinguishable from a
        // few scattered matching words.
        $phraseBoost = (mb_stripos($content, $query->queryText) !== false && mb_strlen(trim($query->queryText)) >= 3) ? 0.15 : 0.0;

        return min(1.0, $this->lexicalScore($queryTokens, $contentTokens) + $phraseBoost);
    }
}
