<?php

namespace Modules\AI\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Models\AiFile;
use Modules\AI\Models\AiFileProcessing;
use Modules\AI\Services\FileProcessors\AiFileProcessingResult;
use Modules\AI\Services\FileProcessors\AiFileProcessorManager;
use Modules\AI\Jobs\ChunkAndIndexAiFileJob;

/**
 * Universal AI File Engine - Phase 1: the actual processing work for an
 * AiFile row, run off the request/response cycle (acceptance criteria
 * doc S19) so a large upload never blocks the chat response or the
 * upload API's HTTP response. Mirrors the existing
 * IndexAiKnowledgeSourceJob's status-transition pattern (processing ->
 * ready/failed, error recorded on the row itself - doc S12/S27: no fake
 * "ready" status with no real content).
 *
 * Phase 2 (Document Processing) adds: persisting the normalized `blocks`
 * structure as its own content reference (`blocks_ref`, same pattern as
 * `extracted_content_ref`), and real retry/permanent-failure
 * differentiation (doc S29) - a processor result with `retryable: true`
 * throws so Laravel's own queue retry/backoff takes over, instead of
 * this job immediately recording a permanent `failed` status.
 */
class ProcessAiFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public AiFile $file) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(AiFileProcessorManager $processors): void
    {
        $this->file->update(['processing_status' => AiFile::STATUS_PROCESSING]);

        $step = AiFileProcessing::query()->create([
            'file_id' => $this->file->id,
            'processing_type' => AiFileProcessing::TYPE_TEXT_EXTRACTION,
            'status' => AiFileProcessing::STATUS_PROCESSING,
        ]);

        $processor = $processors->for($this->file->mime_type);

        if ($processor === null) {
            $this->markFailed($step, 'unsupported_file_type');

            return;
        }

        $absolutePath = Storage::disk($this->file->disk ?: 'public')->path($this->file->file_path);
        $startedAt = microtime(true);

        try {
            $result = $processor->process($absolutePath, $this->file->mime_type);
        } catch (\Throwable $e) {
            report($e);
            $this->markFailed($step, 'processing_exception');

            return;
        }

        if (! $result->success) {
            if ($result->retryable && $this->attempts() < $this->tries) {
                // Doc S29: a temporary failure is retried via Laravel's
                // own queue backoff, not recorded as permanently failed
                // on the first attempt.
                $step->update(['status' => AiFileProcessing::STATUS_FAILED, 'error_message' => $result->error]);

                throw new \RuntimeException((string) ($result->error ?? 'processing_failed_retryable'));
            }

            $this->markFailed($step, (string) ($result->error ?? 'unknown_error'));

            return;
        }

        $this->persistSuccess($step, $result, (int) round((microtime(true) - $startedAt) * 1000), $processor::class);
    }

    protected function persistSuccess(AiFileProcessing $step, AiFileProcessingResult $result, int $processingTimeMs, string $processorClass): void
    {
        // Extracted text is a content REFERENCE on the private disk, not
        // a column value - same storage convention already used by
        // AiKnowledgeIngestionService's chunk files (see its own
        // docblock), so a large document never bloats the ai_files row.
        $contentRef = "ai-files/{$this->file->id}/extracted-text.txt";
        Storage::disk('local')->put($contentRef, (string) $result->text);

        $blocksRef = null;

        if ($result->blocks !== []) {
            $blocksRef = "ai-files/{$this->file->id}/blocks.json";
            Storage::disk('local')->put($blocksRef, json_encode($result->blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
        }

        $step->update([
            'status' => AiFileProcessing::STATUS_COMPLETED,
            'extracted_content_ref' => $contentRef,
            'blocks_ref' => $blocksRef,
        ]);

        $metadata = [
            ...$result->metadata,
            'document_type' => $result->documentType,
            'warnings' => $result->warnings,
            'processor' => class_basename($processorClass),
            'processing_time_ms' => $processingTimeMs,
        ];

        if (isset($metadata['preview_assets']) && is_array($metadata['preview_assets'])) {
            $metadata['preview_assets'] = $this->persistPreviewAssets($metadata['preview_assets']);
        }

        $this->file->update([
            'processing_status' => AiFile::STATUS_READY,
            'processing_error' => null,
            'metadata' => $metadata,
        ]);

        Log::info('ai_file.processing_completed', [
            'file_id' => $this->file->id,
            'owner_type' => $this->file->owner_type,
            'owner_id' => $this->file->owner_id,
            'document_type' => $result->documentType,
            'warnings' => $result->warnings,
        ]);

        // Phase 8 (doc S33): chunking/indexing is its own queued step,
        // gated so it can be disabled independently of file processing
        // itself (e.g. during a migration/backfill window) without
        // touching ProcessAiFileJob's own retry semantics.
        if ((bool) config('ai.chunking.enabled', true)) {
            ChunkAndIndexAiFileJob::dispatch($this->file);
        }
    }

    /**
     * Phase 5 (doc S8): a processor (currently only
     * RasterImageFileProcessor) may hand back thumbnail/preview image
     * BYTES in its metadata, since a processor only ever receives an
     * absolute source path and a MIME type - it has no AiFile id to
     * build a stable storage path from. This is the one place that
     * already owns the "content reference on the private disk, not a
     * raw value in the row" convention (see extracted-text/blocks
     * above), so it is also the one place that turns those raw bytes
     * into a persisted file + path reference, exactly like every other
     * processor-produced artifact in this job - never a second storage
     * convention bolted onto the processor itself.
     *
     * @param  array<string, array{bytes: string, extension: string, width: int, height: int}>  $assets
     * @return array<string, array{path: string, disk: string, extension: string, width: int, height: int}>
     */
    protected function persistPreviewAssets(array $assets): array
    {
        $persisted = [];

        foreach ($assets as $key => $asset) {
            if (! isset($asset['bytes'], $asset['extension']) || ! is_string($asset['bytes'])) {
                continue;
            }

            $path = "ai-files/{$this->file->id}/{$key}.{$asset['extension']}";
            Storage::disk('local')->put($path, $asset['bytes']);

            $persisted[$key] = [
                'path' => $path,
                'disk' => 'local',
                'extension' => $asset['extension'],
                'width' => $asset['width'] ?? null,
                'height' => $asset['height'] ?? null,
            ];
        }

        return $persisted;
    }

    protected function markFailed(AiFileProcessing $step, string $error): void
    {
        $step->update([
            'status' => AiFileProcessing::STATUS_FAILED,
            'error_message' => $error,
        ]);

        $this->file->update([
            'processing_status' => AiFile::STATUS_FAILED,
            'processing_error' => $error,
        ]);

        Log::warning('ai_file.processing_failed', [
            'file_id' => $this->file->id,
            'owner_type' => $this->file->owner_type,
            'owner_id' => $this->file->owner_id,
            'reason' => $error,
        ]);
    }
}
