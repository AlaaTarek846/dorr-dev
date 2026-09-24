<?php

namespace Modules\AI\Services;

use Illuminate\Support\Facades\Storage;
use Modules\AI\Models\AiKnowledgeChunk;
use Modules\AI\Models\AiKnowledgeSource;
use Modules\AI\Repositories\AiProviderRepository;

/**
 * The ingestion pipeline for the Knowledge base / RAG (v2.0 doc, 5.2):
 * fetch content -> inspect/clean -> chunk -> add metadata -> dedupe ->
 * index (embed) -> verify -> publish.
 *
 * "Publish" here means the source is created with approval_status=pending:
 * an admin still has to approve it (see AiKnowledgeSourceService::approve())
 * before AiKnowledgeRetriever will ever use it as evidence (5.5 - open,
 * unreviewed content is never final evidence without an approval policy).
 *
 * Chunk *content* is deliberately not stored in the ai_knowledge_chunks
 * table (content_ref only points at it, per the existing schema comment)
 * - it is written, together with its embedding vector when one could be
 * computed, to a JSON file on the private disk instead.
 */
class AiKnowledgeIngestionService
{
    public function __construct(
        protected AiGateway $gateway,
        protected AiProviderRepository $providers,
    ) {}

    /**
     * @param  array{owner_type: string, owner_id: ?int, file_id: ?int, name: string, domain: ?string, country_code: ?string, publisher: ?string, authority: ?string, published_at: ?string, effective_at: ?string, data_classification: string, access_scope: ?array}  $meta
     */
    public function ingestText(array $meta, string $rawContent): AiKnowledgeSource
    {
        $cleaned = $this->clean($rawContent);

        $source = AiKnowledgeSource::query()->create([
            ...$meta,
            'fetched_at' => now(),
            'current_version' => 1,
            'change_detected' => false,
            'is_active' => true,
            'approval_status' => AiKnowledgeSource::APPROVAL_PENDING,
        ]);

        $this->indexChunks($source, $cleaned);

        return $source->fresh();
    }

    /**
     * Re-ingests content for an existing source (a document changed) as a
     * new version: bumps current_version, replaces its chunks, and resets
     * approval to pending so the updated content is reviewed again before
     * it is trusted as evidence (5.4 - invalidate stale versions).
     */
    public function reingestText(AiKnowledgeSource $source, string $rawContent): AiKnowledgeSource
    {
        $cleaned = $this->clean($rawContent);

        foreach ($source->chunks as $chunk) {
            $this->deleteChunkFile($chunk);
            $chunk->delete();
        }

        $source->update([
            'current_version' => $source->current_version + 1,
            'change_detected' => false,
            'fetched_at' => now(),
            'approval_status' => AiKnowledgeSource::APPROVAL_PENDING,
        ]);

        $this->indexChunks($source, $cleaned);

        return $source->fresh();
    }

    protected function indexChunks(AiKnowledgeSource $source, string $cleaned): void
    {
        $pieces = $this->chunk($cleaned);
        $embeddingProvider = $this->resolveEmbeddingProvider();
        $seenHashes = [];

        foreach (array_values($pieces) as $index => $text) {
            // Dedupe: skip exact-duplicate chunks within the same source
            // (common with repeated boilerplate/headers in source
            // documents) rather than indexing the same text twice.
            $hash = md5($text);

            if (isset($seenHashes[$hash])) {
                continue;
            }

            $seenHashes[$hash] = true;

            $embedding = null;
            $embeddingMeta = null;

            if ($embeddingProvider) {
                try {
                    // v2.0 requirements doc S20.3: one chunk's embedding
                    // call failing must not abort the whole ingestion
                    // batch - the chunk is still indexed and searchable
                    // via lexical retrieval, just without a vector, same
                    // as when embeddingProvider resolves to null at all.
                    $result = $this->gateway->embed($embeddingProvider, $text);
                } catch (\Throwable $e) {
                    report($e);
                    $result = ['success' => false, 'vector' => null];
                }

                if ($result['success']) {
                    $embedding = $result['vector'];
                    $embeddingMeta = [
                        'provider_key' => $embeddingProvider->key,
                        'model' => config('ai.knowledge.embedding_model', 'text-embedding-3-small'),
                    ];
                }
            }

            $path = "ai-knowledge/{$source->id}/chunk-{$index}.json";

            Storage::disk('local')->put($path, json_encode([
                'content' => $text,
                'embedding' => $embedding,
                'embedding_provider' => $embeddingMeta['provider_key'] ?? null,
                'embedding_model' => $embeddingMeta['model'] ?? null,
            ], JSON_UNESCAPED_UNICODE));

            AiKnowledgeChunk::query()->create([
                'knowledge_source_id' => $source->id,
                'chunk_index' => $index,
                'content_ref' => $path,
                'token_count' => (int) ceil(mb_strlen($text) / 4),
                'searchable' => true,
            ]);
        }
    }

    protected function deleteChunkFile(AiKnowledgeChunk $chunk): void
    {
        if ($chunk->content_ref && Storage::disk('local')->exists($chunk->content_ref)) {
            Storage::disk('local')->delete($chunk->content_ref);
        }
    }

    protected function clean(string $raw): string
    {
        // Strip control characters, normalize line endings and collapse
        // runs of blank lines/whitespace left over from PDF/HTML/OCR
        // extraction, without touching Arabic diacritics or punctuation.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $raw) ?? $raw;
        $text = str_replace("\r\n", "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Sentence/paragraph-aware fixed-size chunking: splits on paragraph
     * and sentence boundaries where possible instead of cutting mid-word,
     * targeting config('ai.knowledge.chunk_size') characters per chunk
     * with a small overlap between consecutive chunks so context is not
     * lost right at a boundary.
     *
     * @return list<string>
     */
    protected function chunk(string $text): array
    {
        $targetSize = max(200, (int) config('ai.knowledge.chunk_size', 1000));
        $overlap = max(0, (int) config('ai.knowledge.chunk_overlap', 150));

        if (mb_strlen($text) <= $targetSize) {
            return $text === '' ? [] : [$text];
        }

        preg_match_all('/.+?(?:[\.\!\?\x{061F}\x{06D4}]+\s+|\n\n+|$)/su', $text, $matches);
        $sentences = array_values(array_filter(array_map('trim', $matches[0] ?? []), fn ($s) => $s !== ''));

        if ($sentences === []) {
            $sentences = [$text];
        }

        $chunks = [];
        $current = '';

        foreach ($sentences as $sentence) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($sentence) + 1 > $targetSize) {
                $chunks[] = trim($current);
                $current = $overlap > 0 ? mb_substr($current, max(0, mb_strlen($current) - $overlap)) : '';
            }

            $current .= ($current !== '' ? ' ' : '').$sentence;

            // A single sentence longer than the target size on its own -
            // hard-split it rather than producing one giant chunk.
            while (mb_strlen($current) > $targetSize * 2) {
                $chunks[] = trim(mb_substr($current, 0, $targetSize));
                $current = mb_substr($current, $targetSize - $overlap);
            }
        }

        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return array_values(array_filter($chunks, fn ($c) => mb_strlen($c) >= 20));
    }

    /**
     * The provider used for embeddings across the whole knowledge base
     * right now: the platform's default usable chat provider, if it
     * actually supports embeddings (currently OpenAI only - see
     * OpenAiConnector::embed()). Returns null when none does, so ingestion
     * still succeeds with lexical-only chunks instead of failing outright.
     */
    protected function resolveEmbeddingProvider()
    {
        $provider = $this->providers->resolveActiveForChat();

        if (! $provider) {
            return null;
        }

        // A single lightweight probe embed would cost a real API call on
        // every ingestion just to check support - instead we only support
        // known embeddings-capable provider keys explicitly.
        return in_array($provider->key, ['openai'], true) ? $provider : null;
    }
}
