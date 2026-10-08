<?php

namespace Modules\AI\Services\Retrieval\Backends;

use Modules\AI\Models\AiFileChunk;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\AI\Services\AiGateway;
use Modules\AI\Services\Indexing\Concerns\ResolvesEmbeddingProvider;
use Modules\AI\Services\Retrieval\AiRetrievalQuery;
use Modules\AI\Services\Retrieval\AiSearchBackendInterface;

/**
 * Phase 9 (doc S8): a REAL vector-similarity backend - cosine
 * similarity between the query's own embedding and each candidate
 * chunk's ALREADY-STORED embedding (persisted by
 * AiIndexingEngine::embedChunks(), Phase 9's own fix to the gap Phase 8
 * left open). This is honestly a bounded, in-PHP brute-force scan over
 * the candidate set AiRetrievalEngine already fetched - the exact same
 * trade-off AiKnowledgeRetriever already makes and documents for the
 * admin Knowledge Base, not a true ANN index. No fake/random vectors
 * are ever used: a chunk with no real stored embedding (most of them,
 * today, since `indexing.auto_embed` defaults false) simply returns
 * null from score() - "not scored by this backend", never a made-up
 * number.
 *
 * Unavailable whenever no embeddings-capable provider is configured -
 * callers must not assume this backend contributes anything and must
 * check isAvailable() (AiRetrievalEngine does, before even trying to
 * embed the query itself).
 */
class DatabaseSemanticSearchBackend implements AiSearchBackendInterface
{
    use ResolvesEmbeddingProvider;

    /** @var array<string, ?array{vector: list<float>, provider_key: string}> */
    protected array $queryEmbeddingCache = [];

    public function __construct(
        protected AiGateway $gateway,
        protected AiProviderRepository $providers,
    ) {}

    public function key(): string
    {
        return 'semantic';
    }

    public function isAvailable(AiRetrievalQuery $query): bool
    {
        return $this->resolveEmbeddingProvider($this->providers) !== null;
    }

    public function score(AiRetrievalQuery $query, AiFileChunk $chunk, string $content): ?float
    {
        $chunkVector = $chunk->readEmbedding();

        if ($chunkVector === null) {
            return null;
        }

        $queryEmbedding = $this->embedQuery($query->queryText);

        if ($queryEmbedding === null || $queryEmbedding['provider_key'] !== $chunk->embedding_provider) {
            // A query embedded by provider A is not comparable to a
            // chunk embedded by provider B - different models produce
            // vectors in different, incompatible spaces. Not scored,
            // never force-compared.
            return null;
        }

        return $this->cosineSimilarity($queryEmbedding['vector'], $chunkVector);
    }

    /**
     * @return ?array{vector: list<float>, provider_key: string}
     */
    protected function embedQuery(string $query): ?array
    {
        if (array_key_exists($query, $this->queryEmbeddingCache)) {
            return $this->queryEmbeddingCache[$query];
        }

        $provider = $this->resolveEmbeddingProvider($this->providers);

        if (! $provider) {
            return $this->queryEmbeddingCache[$query] = null;
        }

        try {
            // Doc S14 failure-isolation pattern, same as
            // AiKnowledgeRetriever::embedQuery(): an unexpected
            // throwable here degrades to "no semantic score for this
            // query", it must never fail the whole retrieval request.
            $result = $this->gateway->embed($provider, $query);
        } catch (\Throwable $e) {
            report($e);

            return $this->queryEmbeddingCache[$query] = null;
        }

        return $this->queryEmbeddingCache[$query] = ($result['success'] ?? false)
            ? ['vector' => $result['vector'], 'provider_key' => $provider->key]
            : null;
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

        return max(0.0, $dot / (sqrt($normA) * sqrt($normB)));
    }
}
