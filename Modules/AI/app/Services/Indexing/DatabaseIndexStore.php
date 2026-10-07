<?php

namespace Modules\AI\Services\Indexing;

use Modules\AI\Models\AiFileChunk;

/**
 * Phase 8: the one AiIndexStoreInterface implementation built this
 * phase. "Indexing" here means making the chunk rows that already exist
 * (written by AiChunkingEngine) queryable/trustworthy for Phase 9 -
 * flipping status to indexed, stamping indexed_at - never a plain SQL
 * LIKE query dressed up as semantic search (doc S39's own warning), and
 * never computing/storing an embedding itself (that is AiIndexingEngine's
 * job when auto_embed is enabled, not this store's).
 */
class DatabaseIndexStore implements AiIndexStoreInterface
{
    public function index(array $chunks): void
    {
        if ($chunks === []) {
            return;
        }

        $ids = array_map(fn (AiFileChunk $chunk) => $chunk->id, $chunks);

        AiFileChunk::query()->whereIn('id', $ids)->update([
            'status' => AiFileChunk::STATUS_INDEXED,
            'indexed_at' => now(),
        ]);
    }

    public function remove(int $fileId, ?int $contentVersion = null): void
    {
        $query = AiFileChunk::query()->where('file_id', $fileId);

        if ($contentVersion !== null) {
            $query->where('content_version', $contentVersion);
        }

        foreach ($query->get() as $chunk) {
            $disk = (string) config('ai.chunking.storage_disk', config('ai.files.default_disk', 'public'));

            if ($chunk->content_ref && \Illuminate\Support\Facades\Storage::disk($disk)->exists($chunk->content_ref)) {
                \Illuminate\Support\Facades\Storage::disk($disk)->delete($chunk->content_ref);
            }
        }

        $query->update(['status' => AiFileChunk::STATUS_DELETED, 'is_active' => false, 'embedding_status' => AiFileChunk::EMBEDDING_DELETED]);
    }

    public function markStale(int $fileId, int $belowContentVersion): void
    {
        AiFileChunk::query()
            ->where('file_id', $fileId)
            ->where('content_version', '<', $belowContentVersion)
            ->where('is_active', true)
            ->update([
                'status' => AiFileChunk::STATUS_STALE,
                'is_active' => false,
                'embedding_status' => AiFileChunk::EMBEDDING_STALE,
            ]);
    }
}
