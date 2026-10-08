<?php

namespace Modules\AI\Services\Indexing;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Models\AiFile;
use Modules\AI\Models\AiFileChunk;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\AI\Services\AiGateway;
use Modules\AI\Services\Indexing\Concerns\ResolvesEmbeddingProvider;

/**
 * Phase 8 (doc S37): accepts chunks already written by AiChunkingEngine,
 * creates/updates their index entries via AiIndexStoreInterface, and
 * optionally computes embeddings for them - gated behind
 * `ai.indexing.auto_embed` (default false, doc S44's own instruction
 * not to call an embedding API for every chunk unless explicitly
 * required). This is deliberately a narrower, more cost-conscious
 * default than the admin Knowledge Base's own eager-embed-every-chunk
 * behavior in AiKnowledgeIngestionService, because File Engine chunking
 * runs automatically and potentially very frequently on ordinary chat
 * attachments, while KB ingestion is an infrequent, admin-triggered
 * action.
 *
 * Phase 9 update: embedChunks() now actually PERSISTS the computed
 * vector (Phase 8 computed it and threw it away - documented as a known
 * limitation in that phase's report). It is written into the same
 * content_ref JSON file as the chunk's text (mirroring
 * ai_knowledge_chunks' own content+embedding shape), and
 * embedding_status/embedding_model/embedding_provider/embedded_at are
 * updated on the row - this is the one place that writes those columns.
 *
 * Retrieval (AiRetrievalEngine) is a separate concern, built on top of
 * this - this class only prepares chunks + index + (optionally)
 * embedding records for it to consume.
 */
class AiIndexingEngine
{
    use ResolvesEmbeddingProvider;

    public function __construct(
        protected AiIndexStoreInterface $store,
        protected AiGateway $gateway,
        protected AiProviderRepository $providers,
    ) {}

    /**
     * @param  list<AiFileChunk>  $chunks
     */
    public function index(array $chunks): void
    {
        if ($chunks === []) {
            return;
        }

        $startedAt = microtime(true);
        $fileId = $chunks[0]->file_id;

        // INDEXING_INVALID_CHUNK: a chunk with no content_ref/checksum
        // is not something AiChunkingEngine should ever produce, but
        // indexing it anyway would silently create an unsearchable
        // "indexed" row - reject it explicitly instead.
        $invalid = array_filter($chunks, fn (AiFileChunk $c) => $c->content_ref === null || $c->checksum === null || $c->checksum === '');

        if ($invalid !== []) {
            Log::error('ai_file.indexing.invalid_chunk', [
                'file_id' => $fileId,
                'invalid_chunk_ids' => array_map(fn ($c) => $c->id, $invalid),
                'error_code' => 'INDEXING_INVALID_CHUNK',
            ]);

            $chunks = array_values(array_diff_key($chunks, $invalid));

            if ($chunks === []) {
                return;
            }
        }

        AiFileChunk::query()->whereIn('id', array_map(fn ($c) => $c->id, $chunks))
            ->update(['status' => AiFileChunk::STATUS_INDEXING]);

        if ((bool) config('ai.indexing.auto_embed', false)) {
            $this->embedChunks($chunks);
        }

        try {
            $this->store->index($chunks);
        } catch (\Throwable $e) {
            report($e);

            AiFileChunk::query()->whereIn('id', array_map(fn ($c) => $c->id, $chunks))
                ->update(['status' => AiFileChunk::STATUS_FAILED]);

            Log::error('ai_file.indexing.failed', [
                'file_id' => $fileId,
                'chunk_count' => count($chunks),
                'error' => 'INDEXING_FAILED',
            ]);

            return;
        }

        Log::info('ai_file.indexing.completed', [
            'file_id' => $fileId,
            'chunk_count' => count($chunks),
            'processing_time_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);
    }

    /**
     * Reindexes a file by marking its prior content versions stale and
     * indexing the chunks of the version passed in - never mixes
     * versions, only the latest is ever considered active (doc S34).
     *
     * @param  list<AiFileChunk>  $chunks
     */
    public function reindex(AiFile $file, array $chunks, int $newContentVersion): void
    {
        $this->store->markStale($file->id, $newContentVersion);
        $this->index($chunks);
    }

    public function remove(AiFile $file): void
    {
        $this->store->remove($file->id);
    }

    /**
     * Doc S20.3's own failure-isolation pattern reused exactly: one
     * chunk's embedding call failing never aborts the batch - it is
     * still indexed lexical-only.
     *
     * @param  list<AiFileChunk>  $chunks
     */
    protected function embedChunks(array $chunks): void
    {
        $provider = $this->resolveEmbeddingProvider($this->providers);

        if (! $provider) {
            return;
        }

        $disk = (string) config('ai.chunking.storage_disk', config('ai.files.default_disk', 'public'));
        $model = (string) config('ai.knowledge.embedding_model', 'text-embedding-3-small');

        foreach ($chunks as $chunk) {
            $content = $chunk->readContent();

            if ($content === null || $content === '') {
                continue;
            }

            $chunk->update(['embedding_status' => AiFileChunk::EMBEDDING_PROCESSING]);

            try {
                // Doc S20.3's own failure-isolation pattern reused
                // exactly: one chunk's embedding call failing never
                // aborts the batch - it is still indexed lexical-only,
                // just with embedding_status left as 'failed' rather
                // than silently looking identical to a chunk that was
                // never attempted.
                $result = $this->gateway->embed($provider, $content);
            } catch (\Throwable $e) {
                report($e);
                $chunk->update(['embedding_status' => AiFileChunk::EMBEDDING_FAILED]);

                continue;
            }

            if (! ($result['success'] ?? false) || empty($result['vector'])) {
                $chunk->update(['embedding_status' => AiFileChunk::EMBEDDING_FAILED]);

                continue;
            }

            if (! $chunk->content_ref || ! Storage::disk($disk)->exists($chunk->content_ref)) {
                $chunk->update(['embedding_status' => AiFileChunk::EMBEDDING_FAILED]);

                continue;
            }

            $payload = json_decode((string) Storage::disk($disk)->get($chunk->content_ref), true);
            $payload = is_array($payload) ? $payload : ['content' => $content];
            $payload['embedding'] = array_map('floatval', $result['vector']);
            $payload['embedding_provider'] = $provider->key;
            $payload['embedding_model'] = $model;

            Storage::disk($disk)->put($chunk->content_ref, json_encode($payload, JSON_UNESCAPED_UNICODE));

            $chunk->update([
                'embedding_status' => AiFileChunk::EMBEDDING_EMBEDDED,
                'embedding_model' => $model,
                'embedding_provider' => $provider->key,
                'embedded_at' => now(),
            ]);
        }
    }
}
