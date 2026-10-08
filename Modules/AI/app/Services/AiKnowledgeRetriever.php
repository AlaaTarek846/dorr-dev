<?php

namespace Modules\AI\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Models\AiKnowledgeChunk;
use Modules\AI\Models\AiKnowledgeSource;
use Modules\AI\Repositories\AiProviderRepository;

/**
 * Hybrid retrieval (v2.0 doc, 5.3): lexical/keyword score + vector
 * (embedding cosine similarity) score, metadata-filtered to only
 * approved/active sources the owner is allowed to see, then reranked by a
 * combined score with a small freshness boost (5.4).
 *
 * This scores every searchable chunk of every candidate source in PHP
 * rather than delegating to a vector database - a deliberate, honest
 * trade-off for a first working version bounded by
 * config('ai.knowledge.max_chunks_scanned'), not a stand-in for a real
 * ANN index at larger scale.
 */
class AiKnowledgeRetriever
{
    public function __construct(
        protected AiGateway $gateway,
        protected AiProviderRepository $providers,
    ) {}

    /**
     * @return list<array{source: AiKnowledgeSource, chunk: AiKnowledgeChunk, content: string, score: float}>
     */
    public function retrieve(Authenticatable $owner, string $query, ?string $domain = null): array
    {
        if (! config('ai.knowledge.enabled', true) || trim($query) === '') {
            return [];
        }

        $sources = $this->candidateSources($owner, $domain);

        if ($sources->isEmpty()) {
            return [];
        }

        $maxScan = max(1, (int) config('ai.knowledge.max_chunks_scanned', 500));

        $chunks = AiKnowledgeChunk::query()
            ->whereIn('knowledge_source_id', $sources->pluck('id'))
            ->where('searchable', true)
            ->orderByDesc('id')
            ->limit($maxScan)
            ->get();

        if ($chunks->isEmpty()) {
            return [];
        }

        $queryVector = $this->embedQuery($query);
        $queryTokens = $this->tokenize($query);
        $limit = max(1, (int) config('ai.knowledge.retrieval_limit', 5));
        $minScore = (float) config('ai.knowledge.min_relevance_score', 0.15);

        $scored = [];

        foreach ($chunks as $chunk) {
            $payload = $this->readChunkFile($chunk);

            if ($payload === null || blank($payload['content'] ?? null)) {
                continue;
            }

            $source = $sources->get($chunk->knowledge_source_id);

            if (! $source) {
                continue;
            }

            $lexicalScore = $this->lexicalScore($queryTokens, $this->tokenize($payload['content']));

            $vectorScore = null;

            if ($queryVector && ! empty($payload['embedding']) && ($payload['embedding_provider'] ?? null) === $queryVector['provider_key']) {
                $vectorScore = $this->cosineSimilarity($queryVector['vector'], $payload['embedding']);
            }

            $hybridScore = $vectorScore !== null
                ? ($vectorScore * 0.6) + ($lexicalScore * 0.4)
                : $lexicalScore;

            $hybridScore += $this->freshnessBoost($source);
            $hybridScore = max(0.0, min(1.0, $hybridScore));

            if ($hybridScore < $minScore) {
                continue;
            }

            $scored[] = [
                'source' => $source,
                'chunk' => $chunk,
                'content' => $payload['content'],
                'score' => round($hybridScore, 3),
            ];
        }

        usort($scored, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * @return Collection<int, AiKnowledgeSource>
     */
    protected function candidateSources(Authenticatable $owner, ?string $domain)
    {
        $query = AiKnowledgeSource::query()
            ->where('approval_status', AiKnowledgeSource::APPROVAL_APPROVED)
            ->where('is_active', true)
            // A source queued for (re)indexing has no chunks yet, or is
            // mid-reindex with stale ones about to be replaced - never
            // usable as evidence until indexing actually finishes.
            ->where('processing_status', AiKnowledgeSource::PROCESSING_READY)
            ->where(function ($q) use ($owner) {
                // Public/internal knowledge is visible platform-wide;
                // anything more sensitive only to its own owner - there is
                // no role/permission system for chat end-users to check
                // access_scope against beyond direct ownership yet.
                $q->whereIn('data_classification', [
                    AiKnowledgeSource::CLASSIFICATION_PUBLIC,
                    AiKnowledgeSource::CLASSIFICATION_INTERNAL,
                ])->orWhere(function ($ownerQuery) use ($owner) {
                    $ownerQuery->where('owner_type', $owner->getMorphClass())
                        ->where('owner_id', $owner->getAuthIdentifier());
                });
            });

        if ($domain) {
            $query->where('domain', $domain);
        }

        return $query->get()->keyBy('id');
    }

    /**
     * @return ?array{content: string, embedding: ?array, embedding_provider: ?string}
     */
    protected function readChunkFile(AiKnowledgeChunk $chunk): ?array
    {
        if (! $chunk->content_ref || ! Storage::disk('local')->exists($chunk->content_ref)) {
            return null;
        }

        $decoded = json_decode(Storage::disk('local')->get($chunk->content_ref), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return ?array{vector: list<float>, provider_key: string}
     */
    protected function embedQuery(string $query): ?array
    {
        $provider = $this->providers->resolveActiveForChat();

        if (! $provider || ! in_array($provider->key, ['openai'], true)) {
            return null;
        }

        try {
            // This runs on every chat message's retrieval pass - an
            // unexpected throwable here (same class of gap fixed in
            // AiChatService::dispatchWithFallback()/AiVerificationEngine)
            // must degrade to "no embedding, fall back to lexical-only
            // retrieval" rather than take the whole message down.
            $result = $this->gateway->embed($provider, $query);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        return $result['success'] ? ['vector' => $result['vector'], 'provider_key' => $provider->key] : null;
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    protected function lexicalScore(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $setA = array_unique($a);
        $setB = array_unique($b);
        $intersection = array_intersect($setA, $setB);

        if ($intersection === []) {
            return 0.0;
        }

        // Jaccard-style overlap, weighted toward how much of the *query*
        // is covered (recall matters more than the chunk being short).
        $coverage = count($intersection) / count($setA);
        $jaccard = count($intersection) / count(array_unique(array_merge($setA, $setB)));

        return ($coverage * 0.7) + ($jaccard * 0.3);
    }

    /**
     * @return list<string>
     */
    protected function tokenize(string $text): array
    {
        $normalized = mb_strtolower($text);
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized) ?? $normalized;

        $tokens = preg_split('/\s+/u', trim($normalized)) ?: [];

        return array_values(array_filter($tokens, fn ($t) => mb_strlen($t) >= 2));
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    protected function cosineSimilarity(array $a, array $b): float
    {
        $count = min(count($a), count($b));

        if ($count === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] ** 2;
            $normB += $b[$i] ** 2;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        // Cosine similarity is in [-1, 1]; clamp to [0, 1] since a
        // negative score is not meaningful as "relevance" here.
        return max(0.0, $dot / (sqrt($normA) * sqrt($normB)));
    }

    /**
     * A small, bounded bonus for more recently fetched/published sources
     * (5.4 - freshness policy), so two equally relevant chunks prefer the
     * newer one without freshness ever dominating actual relevance.
     */
    protected function freshnessBoost(AiKnowledgeSource $source): float
    {
        $reference = $source->effective_at ?? $source->published_at ?? $source->fetched_at ?? $source->created_at;

        if (! $reference) {
            return 0.0;
        }

        $ageInDays = $reference->diffInDays(now());

        return match (true) {
            $ageInDays <= 30 => 0.05,
            $ageInDays <= 180 => 0.03,
            $ageInDays <= 365 => 0.01,
            default => 0.0,
        };
    }
}
