<?php

namespace Modules\AI\Services\Chunking;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Models\AiFile;
use Modules\AI\Models\AiFileChunk;
use Modules\AI\Services\AiFileEngine;

/**
 * Phase 8 (doc S3): converts a file's already-normalized content
 * (produced by Phase 1-7 processors, read via AiFileEngine::getContext())
 * into persisted AiFileChunk rows. Chunking is deliberately kept
 * separate from indexing (AiIndexingEngine, below) and from retrieval
 * (Phase 9, not built here) - this class never embeds, never searches,
 * only produces chunk rows + their on-disk content.
 *
 * Content-ref-on-disk pattern reused exactly from AiKnowledgeIngestionService
 * (doc S8's own permission to reuse an existing storage convention
 * rather than inventing a new one).
 */
class AiChunkingEngine
{
    public function __construct(
        protected AiFileEngine $fileEngine,
        protected AiChunkerManager $chunkers,
        protected AiTokenCounterInterface $tokenCounter,
    ) {}

    /**
     * Idempotent entry point (doc S31/S33): chunking the same unchanged
     * file content twice must never create duplicate active chunks. Uses
     * the DB unique constraint on `chunk_key` as the real safety net (an
     * upsert), not just a best-effort check, so a retried queue job is
     * always safe.
     *
     * @return list<AiFileChunk>
     *
     * @throws AiChunkingException
     */
    public function chunk(AiFile $file): array
    {
        return $this->chunkVersion($file, (int) ($file->metadata['chunking_version'] ?? 1));
    }

    /**
     * Explicit re-chunk (doc S9/S34): bumps the content version, so the
     * previous version's chunks are marked inactive rather than mixed
     * with the new ones - the caller decides when this is warranted
     * (e.g. the file's extracted content changed), this method never
     * guesses on its own.
     *
     * @return list<AiFileChunk>
     */
    public function rechunk(AiFile $file): array
    {
        $nextVersion = (int) ($file->metadata['chunking_version'] ?? 1) + 1;

        $metadata = $file->metadata ?? [];
        $metadata['chunking_version'] = $nextVersion;
        $file->update(['metadata' => $metadata]);

        $chunks = $this->chunkVersion($file, $nextVersion);

        // Doc S10 (Phase 9): a content-version bump also invalidates
        // any embedding computed for the old version's chunks - the
        // text they were embedded from no longer represents the file's
        // current content.
        AiFileChunk::query()
            ->where('file_id', $file->id)
            ->where('content_version', '<', $nextVersion)
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'status' => AiFileChunk::STATUS_STALE,
                'embedding_status' => AiFileChunk::EMBEDDING_STALE,
            ]);

        return $chunks;
    }

    /**
     * @return list<AiFileChunk>
     *
     * @throws AiChunkingException
     */
    protected function chunkVersion(AiFile $file, int $contentVersion): array
    {
        $context = $this->fileEngine->getContext($file);

        $normalizedContent = [
            'document_type' => $context['document_type'] ?? null,
            'text' => $context['text'] ?? null,
            'blocks' => $context['blocks'] ?? [],
            'metadata' => $file->metadata ?? [],
        ];

        // Doc S46: resolve the strategy BEFORE judging "empty" - an
        // image file legitimately has no text/blocks at all but real
        // metadata (width/height/OCR text), which ImageReferenceChunker
        // still turns into a real indexable record. Checking for empty
        // content up front (before knowing which strategy would apply)
        // would wrongly reject every image.
        // Doc S46/S14: a defensive absolute cap, distinct from the
        // per-block hard-split every block-walking strategy already
        // does - this guards against a pathological single text blob
        // (e.g. one gigantic un-split JSON value or a text file with no
        // paragraph breaks at all) that would otherwise be handed whole
        // to AiTextChunker::chunk() and processed in one PHP call.
        $maxFileCharacters = (int) config('ai.chunking.max_file_characters', 20_000_000);
        $contentLength = mb_strlen((string) ($normalizedContent['text'] ?? ''));

        if ($contentLength > $maxFileCharacters) {
            throw new AiChunkingException('CHUNKING_TOO_LARGE', "File #{$file->id} normalized text ({$contentLength} characters) exceeds the configured chunking.max_file_characters ({$maxFileCharacters}).");
        }

        $chunker = $this->chunkers->for($normalizedContent);

        if (! $chunker) {
            throw new AiChunkingException('CHUNKING_UNSUPPORTED_TYPE', "No chunking strategy supports file #{$file->id} (document_type=".($normalizedContent['document_type'] ?? 'null').').');
        }

        $startedAt = microtime(true);

        try {
            $drafts = $chunker->chunk($normalizedContent);
        } catch (\Throwable $e) {
            report($e);

            throw new AiChunkingException('CHUNKING_FAILED', "Chunking failed for file #{$file->id}: {$e->getMessage()}", previous: $e);
        }

        if ($drafts === []) {
            throw new AiChunkingException('CHUNKING_EMPTY_CONTENT', "Chunking strategy produced no chunks for file #{$file->id} - nothing to index.");
        }

        $disk = (string) config('ai.chunking.storage_disk', config('ai.files.default_disk', 'public'));
        $rows = [];

        foreach ($drafts as $draft) {
            $checksum = $draft->contentChecksum();
            $chunkKey = hash('sha256', implode('|', [$file->id, $contentVersion, $draft->index, $checksum]));
            $contentRef = "ai-files/{$file->id}/chunks/v{$contentVersion}/chunk-{$draft->index}.json";

            Storage::disk($disk)->put($contentRef, json_encode([
                'content' => $draft->content,
            ], JSON_UNESCAPED_UNICODE));

            $rows[] = [
                'file_id' => $file->id,
                'content_version' => $contentVersion,
                'chunk_index' => $draft->index,
                'chunk_key' => $chunkKey,
                'content_ref' => $contentRef,
                'content_type' => $draft->contentType,
                'token_count' => $this->tokenCounter->estimateTokenCount($draft->content),
                'token_count_is_estimated' => ! $this->tokenCounter->isExact(),
                'character_count' => $draft->characterCount(),
                'checksum' => $checksum,
                'metadata' => json_encode($draft->metadata, JSON_UNESCAPED_UNICODE),
                'status' => AiFileChunk::STATUS_PENDING,
                'is_active' => true,
                // upsert() is a raw query-builder insert - it does not
                // run through Eloquent's date casts, so Carbon instances
                // must be pre-stringified here rather than passed as
                // objects (which the PDO driver cannot bind directly).
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ];
        }

        if ($rows !== []) {
            // Doc S36: never insert e.g. 100,000 chunks one query at a
            // time - batch upsert (also the real idempotency mechanism:
            // an identical chunk_key from a retried job updates the same
            // row rather than erroring or duplicating).
            foreach (array_chunk($rows, (int) config('ai.chunking.batch_size', 200)) as $batch) {
                AiFileChunk::query()->upsert(
                    $batch,
                    ['chunk_key'],
                    ['content_ref', 'content_type', 'token_count', 'token_count_is_estimated', 'character_count', 'metadata', 'updated_at'],
                );
            }
        }

        Log::info('ai_file.chunking.completed', [
            'file_id' => $file->id,
            'content_version' => $contentVersion,
            'chunk_count' => count($rows),
            'processing_time_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

        return AiFileChunk::query()
            ->where('file_id', $file->id)
            ->where('content_version', $contentVersion)
            ->orderBy('chunk_index')
            ->get()
            ->all();
    }
}
