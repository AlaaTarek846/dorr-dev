<?php

namespace Modules\AI\Services\Retrieval;

use Illuminate\Support\Collection;
use Modules\AI\Enums\AiRetrievalMode;
use Modules\AI\Models\AiFile;
use Modules\AI\Models\AiFileChunk;
use Modules\AI\Services\Retrieval\Backends\DatabaseKeywordSearchBackend;
use Modules\AI\Services\Retrieval\Backends\DatabaseSemanticSearchBackend;

/**
 * Phase 9 (doc S3): the retrieval engine. Accepts an AiRetrievalQuery,
 * resolves which files are in scope (authorization-aware - doc S14),
 * fetches a bounded, authorized candidate set of AiFileChunk rows (doc
 * S16/S38 - never an unbounded table scan), scores them through
 * AiSearchBackendInterface implementations (never Eloquent/SQL/a
 * provider API directly from here), deduplicates, ranks, reorders for
 * document coherence (doc S20), and returns a structured
 * AiRetrievalResult. Never calls an LLM/chat provider itself (doc S3).
 */
class AiRetrievalEngine
{
    public function __construct(
        protected DatabaseKeywordSearchBackend $keywordBackend,
        protected DatabaseSemanticSearchBackend $semanticBackend,
    ) {}

    public function retrieve(AiRetrievalQuery $query): AiRetrievalResult
    {
        $startedAt = microtime(true);

        if ($query->mode === AiRetrievalMode::None || trim($query->queryText) === '') {
            return AiRetrievalResult::empty($query->queryText, $query->mode, $query->filters);
        }

        $fileIds = $this->resolveFileScope($query);

        if ($fileIds === []) {
            return AiRetrievalResult::empty($query->queryText, $query->mode, $query->filters);
        }

        $candidateK = max(1, $query->candidateK ?? (int) config('ai.retrieval.candidate_k', 30));
        $topK = max(1, $query->topK ?? (int) config('ai.retrieval.top_k', 8));

        $candidates = $this->fetchCandidates($fileIds, $query->filters, $candidateK);

        if ($candidates->isEmpty()) {
            return new AiRetrievalResult($query->queryText, $query->mode, [], 0, 0, $query->filters, $this->elapsedMs($startedAt), 'database', $this->fileCoverage($fileIds, []));
        }

        $scored = $this->scoreCandidates($query, $candidates);

        // "Summarize the attached file" shares no word with the file's content, so lexical
        // scoring finds nothing even though the user clearly wants the document itself.
        if ($scored === [] && $this->isGenericDocumentRequest($query->queryText)) {
            $scored = $this->overviewRows($candidates);
        }

        $deduped = $this->deduplicate($scored);

        usort($deduped, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        $selected = $this->applyFileDiversity($deduped, $topK);
        $top = $this->reorderForCoherence($selected);

        $results = [];

        foreach ($top as $rank => $row) {
            $results[] = new AiRetrievedChunk(
                $row['chunk'],
                $row['content'],
                round($row['score'], 3),
                $rank + 1,
                $row['method'],
                $this->sourceReference($row['chunk']),
            );
        }

        return new AiRetrievalResult(
            $query->queryText,
            $query->mode,
            $results,
            $candidates->count(),
            count($results),
            $query->filters,
            $this->elapsedMs($startedAt),
            'database',
            $this->fileCoverage($fileIds, $results),
        );
    }

    /**
     * Phase 10 (doc S7/S24): once more than one distinct file is present
     * among the ranked candidates, caps how many of the final top_k
     * slots a single file may occupy - first pass fills each file's
     * slots up to `max_chunks_per_file` in global-score order, second
     * pass fills any still-empty slots purely by global relevance
     * (ignoring the cap), so a highly relevant file never loses a slot
     * it would otherwise have earned just to make room for a weaker,
     * less relevant file. Disabled, or a single-file candidate set,
     * behaves exactly like Phase 9 (plain top_k slice) - doc S12's own
     * "do not over-engineer this".
     *
     * @param  list<array{chunk: AiFileChunk, content: string, score: float, method: string}>  $ranked
     * @return list<array{chunk: AiFileChunk, content: string, score: float, method: string}>
     */
    protected function applyFileDiversity(array $ranked, int $topK): array
    {
        if ($ranked === []) {
            return [];
        }

        $distinctFiles = array_unique(array_map(fn (array $row) => $row['chunk']->file_id, $ranked));

        if (count($distinctFiles) <= 1 || ! (bool) config('ai.retrieval.multi_file.diversity.enabled', true)) {
            return array_slice($ranked, 0, $topK);
        }

        $maxPerFile = max(1, (int) config('ai.retrieval.multi_file.diversity.max_chunks_per_file', 4));

        $selected = [];
        $perFileCount = [];
        $remaining = [];

        foreach ($ranked as $row) {
            if (count($selected) >= $topK) {
                break;
            }

            $fileId = $row['chunk']->file_id;

            if (($perFileCount[$fileId] ?? 0) < $maxPerFile) {
                $selected[] = $row;
                $perFileCount[$fileId] = ($perFileCount[$fileId] ?? 0) + 1;
            } else {
                $remaining[] = $row;
            }
        }

        foreach ($remaining as $row) {
            if (count($selected) >= $topK) {
                break;
            }

            $selected[] = $row;
        }

        return $selected;
    }

    /**
     * @param  list<int>  $searchedFileIds
     * @param  list<AiRetrievedChunk>  $results
     * @return array{searched_file_ids: list<int>, matched_file_ids: list<int>, unmatched_file_ids: list<int>}
     */
    protected function fileCoverage(array $searchedFileIds, array $results): array
    {
        $matched = array_values(array_unique(array_map(fn (AiRetrievedChunk $r) => $r->chunk->file_id, $results)));
        $unmatched = array_values(array_diff($searchedFileIds, $matched));

        return [
            'searched_file_ids' => $searchedFileIds,
            'matched_file_ids' => $matched,
            'unmatched_file_ids' => $unmatched,
        ];
    }

    /**
     * Doc S15: explicit file(s) (A/B) take priority, then the
     * conversation's own attached files (C), then - only if the project
     * is explicitly configured to allow it, since no such UI exists yet
     * - the owner's whole ready/indexed file library (D). Every branch
     * filters by owner_type/owner_id FIRST, so an explicit file id that
     * does not belong to the caller silently resolves to "not found",
     * never a leaked existence check (doc S35: "do not leak information
     * through search result counts or error messages").
     *
     * @return list<int>
     */
    protected function resolveFileScope(AiRetrievalQuery $query): array
    {
        $base = AiFile::query()
            ->where('owner_type', $query->owner->getMorphClass())
            ->where('owner_id', $query->owner->getAuthIdentifier())
            ->where('processing_status', AiFile::STATUS_READY);

        // Phase 10 hardening: an explicit, EMPTY fileIds array (as
        // opposed to fileIds simply not being passed - null) must mean
        // "search nothing", not silently fall through to the
        // conversation/global branches below - a caller that already
        // resolved "no searchable files" must never have that widened
        // back out from underneath it.
        if ($query->fileIds !== null) {
            return $query->fileIds === [] ? [] : $base->whereIn('id', $query->fileIds)->pluck('id')->all();
        }

        if ($query->conversationId !== null) {
            return $base->where('conversation_id', $query->conversationId)->pluck('id')->all();
        }

        if ((bool) config('ai.retrieval.allow_global_file_scope', false)) {
            return $base->pluck('id')->all();
        }

        return [];
    }

    /**
     * Doc S16 (bounded candidate_k) + S30 (only active/indexed/current
     * data) + S13 (filtering). file_id is always constrained to the
     * already-authorized $fileIds list computed above - this method
     * never widens that scope.
     *
     * @param  list<int>  $fileIds
     * @param  array<string, mixed>  $filters
     */
    protected function fetchCandidates(array $fileIds, array $filters, int $candidateK): Collection
    {
        $query = AiFileChunk::query()
            ->whereIn('file_id', $fileIds)
            ->where('is_active', true)
            ->where('status', AiFileChunk::STATUS_INDEXED)
            ->with('file');

        if (! empty($filters['content_type'])) {
            $query->where('content_type', $filters['content_type']);
        }

        if (! empty($filters['chunk_version'])) {
            $query->where('content_version', $filters['chunk_version']);
        }

        $candidates = $query->orderByDesc('id')->limit($candidateK)->get();

        return $this->applyMetadataFilters($candidates, $filters);
    }

    /**
     * page/sheet/slide/section/timestamp-range filters (doc S13) live
     * inside the `metadata` JSON column, which is not portably
     * queryable across sqlite (tests) and mysql (production) with the
     * same syntax - applied in PHP instead, against the already-bounded
     * candidate set fetchCandidates() just pulled, never against the
     * whole table.
     */
    protected function applyMetadataFilters(Collection $candidates, array $filters): Collection
    {
        if (empty($filters['page']) && empty($filters['sheet']) && empty($filters['slide']) && empty($filters['section'])) {
            return $candidates;
        }

        return $candidates->filter(function (AiFileChunk $chunk) use ($filters) {
            $meta = $chunk->metadata ?? [];

            if (! empty($filters['page']) && ! in_array((int) $filters['page'], (array) ($meta['pages'] ?? []), true) && ($meta['primary_page'] ?? null) !== (int) $filters['page']) {
                return false;
            }

            if (! empty($filters['sheet']) && ($meta['sheet_name'] ?? null) !== $filters['sheet']) {
                return false;
            }

            if (! empty($filters['slide']) && (int) ($meta['slide_number'] ?? -1) !== (int) $filters['slide']) {
                return false;
            }

            if (! empty($filters['section']) && ($meta['section'] ?? null) !== $filters['section']) {
                return false;
            }

            return true;
        })->values();
    }

    /**
     * @return list<array{chunk: AiFileChunk, content: string, score: float, method: string}>
     */
    protected function scoreCandidates(AiRetrievalQuery $query, Collection $candidates): array
    {
        $minScore = (float) config('ai.retrieval.min_relevance_score', 0.1);
        $keywordWeight = (float) config('ai.retrieval.hybrid.keyword_weight', 0.4);
        $semanticWeight = (float) config('ai.retrieval.hybrid.semantic_weight', 0.6);

        $semanticAvailable = in_array($query->mode, [AiRetrievalMode::Semantic, AiRetrievalMode::Hybrid], true)
            && $this->semanticBackend->isAvailable($query);

        $scored = [];

        foreach ($candidates as $chunk) {
            $content = $chunk->readContent();

            if ($content === null || $content === '') {
                continue;
            }

            [$score, $method] = match ($query->mode) {
                AiRetrievalMode::Exact => [$this->exactScore($query->queryText, $content), 'exact'],
                AiRetrievalMode::Keyword => [$this->keywordBackend->score($query, $chunk, $content) ?? 0.0, 'keyword'],
                AiRetrievalMode::Semantic => $semanticAvailable
                    ? [$this->semanticBackend->score($query, $chunk, $content), 'semantic']
                    : [null, 'semantic'],
                AiRetrievalMode::Hybrid => $this->hybridScore($query, $chunk, $content, $semanticAvailable, $keywordWeight, $semanticWeight),
                default => [null, 'none'],
            };

            if ($score === null || $score < $minScore) {
                continue;
            }

            $scored[] = ['chunk' => $chunk, 'content' => $content, 'score' => $score, 'method' => $method];
        }

        return $scored;
    }

    /**
     * Words that only point AT the document ("summarize", "the attached file", "لخص الملف") or
     * are plain function words. A query made only of these names no topic to search for.
     *
     * @var list<string>
     */
    private const GENERIC_REQUEST_WORDS = [
        'the', 'a', 'an', 'this', 'that', 'these', 'those', 'it', 'its', 'me', 'my', 'us', 'for', 'of', 'in', 'on', 'to', 'and', 'or',
        'please', 'can', 'could', 'you', 'give', 'show', 'tell', 'what', 'is', 'are', 'about', 'with', 'from', 'all',
        'does', 'do', 'did', 'say', 'says', 'said', 'contain', 'contains', 'mean', 'means', 'inside', 'here', 'there', 'how', 'why',
        'summarize', 'summarise', 'summary', 'overview', 'explain', 'describe', 'review', 'analyze', 'analyse', 'read', 'check',
        'attached', 'attachment', 'file', 'files', 'document', 'documents', 'doc', 'pdf', 'report', 'contract', 'spreadsheet',
        'sheet', 'presentation', 'slides', 'text', 'content', 'contents', 'uploaded', 'above',
        'لخص', 'لخصلي', 'تلخيص', 'ملخص', 'اشرح', 'اشرحلي', 'وصف', 'راجع', 'حلل', 'حللي', 'اقرا', 'اقرأ', 'الملف', 'ملف', 'المرفق',
        'مرفق', 'المستند', 'مستند', 'الوثيقة', 'العقد', 'التقرير', 'الجدول', 'الشيت', 'العرض', 'هذا', 'هذه', 'ده', 'دي', 'دا',
        'لي', 'لى', 'ليا', 'عن', 'في', 'فى', 'من', 'على', 'ايه', 'إيه', 'ما', 'ماذا', 'كل', 'محتوى', 'محتويات', 'بتاع', 'بتاعت',
    ];

    protected function isGenericDocumentRequest(string $queryText): bool
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($queryText), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($tokens === []) {
            return false;
        }

        foreach ($tokens as $token) {
            if (! in_array($token, self::GENERIC_REQUEST_WORDS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The opening chunks of each file in reading order, at the minimum relevance score, so the
     * per-file diversity cap still shares the slots fairly between files.
     *
     * @return list<array{chunk: AiFileChunk, content: string, score: float, method: string}>
     */
    protected function overviewRows(Collection $candidates): array
    {
        $minScore = (float) config('ai.retrieval.min_relevance_score', 0.1);
        $rows = [];

        foreach ($candidates->sortBy([['file_id', 'asc'], ['chunk_index', 'asc']]) as $chunk) {
            $content = $chunk->readContent();

            if ($content === null || $content === '') {
                continue;
            }

            $rows[] = ['chunk' => $chunk, 'content' => $content, 'score' => $minScore, 'method' => 'overview'];
        }

        return $rows;
    }

    protected function exactScore(string $queryText, string $content): float
    {
        $needle = trim($queryText);

        if (mb_strlen($needle) < 2) {
            return 0.0;
        }

        return mb_stripos($content, $needle) !== false ? 1.0 : 0.0;
    }

    /**
     * Doc S11/S12: the documented scoring formula for hybrid mode - a
     * weighted sum of the keyword and semantic scores (both already
     * bounded to [0, 1] by their own backends, so no further min-max
     * normalization step is needed before combining them). Falls back
     * to keyword-only when no semantic score is available for this
     * chunk (no stored embedding, or no embeddings-capable provider
     * configured) - the same graceful degradation
     * AiKnowledgeRetriever's own hybrid scoring already uses for the
     * admin Knowledge Base. Reciprocal Rank Fusion was considered but
     * not used, specifically to keep the formula identical to that
     * already-shipped precedent rather than introduce a second,
     * differently-shaped scoring strategy for a conceptually
     * near-identical feature.
     *
     * @return array{0: ?float, 1: string}
     */
    protected function hybridScore(AiRetrievalQuery $query, AiFileChunk $chunk, string $content, bool $semanticAvailable, float $keywordWeight, float $semanticWeight): array
    {
        $keywordScore = $this->keywordBackend->score($query, $chunk, $content) ?? 0.0;
        $semanticScore = $semanticAvailable ? $this->semanticBackend->score($query, $chunk, $content) : null;

        if ($semanticScore === null) {
            return [$keywordScore, 'keyword'];
        }

        $combined = ($semanticScore * $semanticWeight) + ($keywordScore * $keywordWeight);

        return [max(0.0, min(1.0, $combined)), 'hybrid'];
    }

    /**
     * Doc S17: dedupe by checksum (content-identical chunks - e.g. the
     * same table chunk matched via two different buffered contexts
     * never appear twice). Deliberately does NOT collapse merely
     * SIMILAR adjacent chunks (doc S17's own "do not remove legitimate
     * adjacent chunks merely because their content is similar").
     *
     * @param  list<array{chunk: AiFileChunk, content: string, score: float, method: string}>  $scored
     * @return list<array{chunk: AiFileChunk, content: string, score: float, method: string}>
     */
    protected function deduplicate(array $scored): array
    {
        $seen = [];
        $deduped = [];

        foreach ($scored as $row) {
            $checksum = $row['chunk']->checksum;

            if (isset($seen[$checksum])) {
                continue;
            }

            $seen[$checksum] = true;
            $deduped[] = $row;
        }

        return $deduped;
    }

    /**
     * Doc S20: do not sort purely by score - group the already-selected
     * top-K results by their source file, then order each file's chunks
     * by chunk_index (their real document order), so two adjacent
     * excerpts from the same file read coherently rather than jumbled
     * by score. The GROUP order itself is still relevance-led: a file's
     * group position is its best (max) scoring chunk, so the most
     * relevant file's excerpts still lead the context.
     *
     * @param  list<array{chunk: AiFileChunk, content: string, score: float, method: string}>  $rows
     * @return list<array{chunk: AiFileChunk, content: string, score: float, method: string}>
     */
    protected function reorderForCoherence(array $rows): array
    {
        $byFile = [];

        foreach ($rows as $row) {
            $byFile[$row['chunk']->file_id][] = $row;
        }

        uasort($byFile, function ($a, $b) {
            $maxA = max(array_column($a, 'score'));
            $maxB = max(array_column($b, 'score'));

            return $maxB <=> $maxA;
        });

        $ordered = [];

        foreach ($byFile as $group) {
            usort($group, fn ($a, $b) => $a['chunk']->chunk_index <=> $b['chunk']->chunk_index);
            array_push($ordered, ...$group);
        }

        return $ordered;
    }

    /**
     * Doc S21: structured source reference, only the fields the chunk's
     * own content type actually carries (never invented metadata).
     *
     * @return array<string, mixed>
     */
    protected function sourceReference(AiFileChunk $chunk): array
    {
        $meta = $chunk->metadata ?? [];

        $base = [
            'file_id' => $chunk->file_id,
            'chunk_id' => $chunk->id,
            'file_name' => $chunk->file?->file_name,
        ];

        return $base + array_filter(match ($chunk->content_type) {
            'spreadsheet' => [
                'sheet' => $meta['sheet_name'] ?? null,
                'row_start' => $meta['row_start'] ?? null,
                'row_end' => $meta['row_end'] ?? null,
            ],
            'presentation' => [
                'slide' => $meta['slide_number'] ?? null,
            ],
            'audio_transcript', 'video_transcript' => [
                'timestamp_start' => $meta['timestamp_start'] ?? null,
                'timestamp_end' => $meta['timestamp_end'] ?? null,
            ],
            'data' => [
                'path' => $meta['path'] ?? null,
            ],
            'image_reference' => [
                'width' => $meta['width'] ?? null,
                'height' => $meta['height'] ?? null,
            ],
            default => [
                'page' => $meta['primary_page'] ?? null,
                'section' => $meta['section'] ?? null,
            ],
        }, fn ($v) => $v !== null);
    }

    protected function elapsedMs(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 2);
    }
}
