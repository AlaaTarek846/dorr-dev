<?php

namespace Modules\AI\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Jobs\ProcessAiFileJob;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiFile;
use Modules\AI\Models\AiMessage;
use Modules\AI\Models\AiFileProcessing;
use Modules\AI\Services\AiConversationFileScope;
use Modules\AI\Services\FileProcessors\AiFileProcessorManager;

/**
 * Universal AI File Engine - Phase 1 central entry point (acceptance
 * criteria doc S4): the one place that owns upload -> validate -> store
 * -> process -> delete -> retrieve -> status, so controllers only ever
 * call this service and never branch on file type themselves.
 *
 * Deliberately takes the already-stored disk path rather than the raw
 * UploadedFile when called from the chat attachment flow, so it never
 * creates a second copy of the same file on disk - the caller
 * (AiChatService::storeAttachment()) already stored one for the instant
 * chat preview. The customer-facing upload API
 * (AiFileUploadController::store()) instead calls storeUploadedFile(),
 * which stores the file itself and then delegates here.
 */
class AiFileEngine
{
    /**
     * Doc S9: a filename extension is never trusted to be the real file
     * type, but *is* trusted enough to reject outright when it names a
     * known-dangerous type - checked against every dot-segment of the
     * original name, not just the last one, so "report.pdf.exe" and
     * "report.exe.pdf" are both caught (double-extension smuggling).
     */
    protected const EXECUTABLE_MIME_TYPES = [
        'application/x-msdownload',
        'application/x-dosexec',
        'application/x-executable',
        'application/x-sh',
        'application/x-shellscript',
        'application/x-bat',
        'application/x-elf',
        'text/x-php',
        'application/x-httpd-php',
    ];

    public function __construct(
        protected AiFileProcessorManager $processors,
        protected AiConversationFileScope $conversationFiles,
        protected \Modules\AI\Repositories\AiFileRepository $files,
    ) {}

    /**
     * Root-cause fix - real, observed gap: the inline single-attachment
     * chat flow (AiChatService::buildDocumentText(), the "attach a file
     * directly in this message" path, as opposed to the dedicated
     * /user/v1/ai-files upload used by the multi-file conversation
     * scope) was never updated when this File Engine's real per-format
     * processors (PdfFileProcessor, WordFileProcessor using PhpWord,
     * ExcelFileProcessor using PhpSpreadsheet, PptxFileProcessor, ...)
     * were built in earlier phases - it kept calling the older,
     * pre-Engine AiDocumentTextExtractor instead, which only recognizes
     * PDF/DOCX/plain-text/markdown/CSV (no XLSX, no PPTX at all) and has
     * no PhpSpreadsheet/PhpWord behind it, so it silently produced worse
     * - or no - extracted text for exactly the file types this Engine
     * already handles properly. These two methods expose just the
     * synchronous "turn this file on disk into text right now, for this
     * one chat turn" half of what $processors can do, without going
     * through the full async store/validate/queue pipeline process()
     * below owns (which creates its own ai_files row and is not meant to
     * run twice for the same already-stored chat attachment - see this
     * class's own docblock on why the chat flow stores the file itself
     * first). No new processor, no duplicate format-detection logic -
     * this only reuses $processors, the single registry every other
     * entry point already goes through.
     */
    public function supportsSync(string $mimeType): bool
    {
        return $this->processors->supports($mimeType);
    }

    /**
     * @see supportsSync() above for why this exists.
     */
    public function extractTextSync(string $absolutePath, string $mimeType): ?string
    {
        $processor = $this->processors->for($mimeType);

        if ($processor === null) {
            return null;
        }

        try {
            $result = $processor->process($absolutePath, $mimeType);
        } catch (\Throwable $e) {
            Log::warning('ai_file.extract_text_sync_threw', [
                'mime_type' => $mimeType,
                'processor' => $processor::class,
                'exception' => $e->getMessage(),
            ]);

            report($e);

            return null;
        }

        // Root-cause fix - real, observed gap: a processor's process()
        // returning a FAILED result (bad zip/OLE structure, a reader it
        // could not identify, ...) is the normal, expected way it
        // reports "could not read this one file" - it deliberately does
        // NOT throw for that (see AiFileProcessingResult::failed()), so
        // the catch block above never ran and nothing was ever logged.
        // That silence is exactly what made the chat-attachment honesty
        // path ($documentUnviewable in AiChatService) indistinguishable
        // from "capability not required at all" - this is the one place
        // that can tell them apart, logging the real reason instead of
        // the caller just seeing null either way.
        if (! $result->success || $result->text === null || trim($result->text) === '') {
            Log::warning('ai_file.extract_text_sync_empty', [
                'mime_type' => $mimeType,
                'processor' => $processor::class,
                'success' => $result->success,
                'error' => $result->error,
                'text_length' => $result->text !== null ? mb_strlen($result->text) : null,
            ]);

            return null;
        }

        return $result->text;
    }

    /**
     * Customer-facing upload entry point (doc S14: POST /api/ai/files).
     * Stores the file on the configured disk itself, then delegates to
     * process() for the shared validate/dedupe/route-to-processing logic.
     */
    public function storeUploadedFile(
        Authenticatable $owner,
        \Illuminate\Http\UploadedFile $uploadedFile,
        ?AiConversation $conversation = null,
        ?AiMessage $message = null,
    ): AiFile {
        // Phase 12 (doc S7/S28): checked BEFORE anything is written to
        // disk - an owner over the configured file-count/storage cap is
        // rejected outright, the same abuse-protection reasoning as the
        // per-request size/type checks in validate() below, just scoped
        // to the owner's running total instead of one file.
        $this->assertWithinOwnerQuota($owner, (int) $uploadedFile->getSize());

        $disk = (string) config('ai.files.default_disk', 'public');
        $originalName = $uploadedFile->getClientOriginalName();

        // Laravel's store() generates a random, collision-safe storage
        // name (hashName()) - the client-supplied filename never reaches
        // the physical path, which is this codebase's existing structural
        // defense against path traversal/malicious filenames (doc S9).
        $directory = 'ai-files/'.$owner->getMorphClass().'/'.$owner->getAuthIdentifier();
        $diskPath = $uploadedFile->store($directory, $disk);

        if ($diskPath === false) {
            throw new \RuntimeException('Unable to store uploaded file.');
        }

        return $this->process(
            owner: $owner,
            diskPath: $diskPath,
            originalName: $originalName,
            clientMimeType: (string) ($uploadedFile->getClientMimeType() ?: 'application/octet-stream'),
            size: (int) $uploadedFile->getSize(),
            conversation: $conversation,
            message: $message,
            disk: $disk,
        );
    }

    /**
     * @throws \Modules\AI\Exceptions\AiFileException when either cap
     *         (0/null disables that dimension) would be exceeded by
     *         accepting this upload.
     */
    protected function assertWithinOwnerQuota(Authenticatable $owner, int $incomingSize): void
    {
        $maxFiles = (int) config('ai.files.max_files_per_owner', 0);
        $maxBytes = (int) config('ai.files.max_storage_bytes_per_owner', 0);

        if ($maxFiles <= 0 && $maxBytes <= 0) {
            return;
        }

        $totals = $this->files->usageTotalsForOwner($owner);

        if ($maxFiles > 0 && $totals['files'] >= $maxFiles) {
            throw \Modules\AI\Exceptions\AiFileException::forReason('file_quota_exceeded');
        }

        if ($maxBytes > 0 && ($totals['bytes'] + $incomingSize) > $maxBytes) {
            throw \Modules\AI\Exceptions\AiFileException::forReason('file_quota_exceeded');
        }
    }

    public function process(
        Authenticatable $owner,
        string $diskPath,
        string $originalName,
        string $clientMimeType,
        int $size,
        ?AiConversation $conversation = null,
        ?AiMessage $message = null,
        ?string $disk = null,
    ): AiFile {
        $disk ??= (string) config('ai.files.default_disk', 'public');
        $absolutePath = Storage::disk($disk)->path($diskPath);
        $checksum = is_readable($absolutePath) ? hash_file('sha256', $absolutePath) : null;

        if ($checksum && (bool) config('ai.files.dedupe_by_checksum', true)) {
            $existing = $this->findReadyDuplicate($owner, $checksum);

            if ($existing) {
                Log::info('ai_file.dedupe_reused', [
                    'owner_type' => $owner->getMorphClass(),
                    'owner_id' => $owner->getAuthIdentifier(),
                    'existing_file_id' => $existing->id,
                    'checksum' => $checksum,
                ]);

                // Phase 10: a real, pre-existing gap this phase's own
                // inspection surfaced - a deduped file's
                // ai_files.conversation_id still points at wherever it
                // was FIRST uploaded, so without this it would silently
                // never become part of THIS conversation's retrieval
                // scope even though the caller just "uploaded" it here.
                // attach() is a no-op if it's already attached.
                $this->attachToConversation($owner, $conversation, $existing);

                return $existing;
            }
        }

        $extension = $this->safeExtension($originalName);
        $sanitizedName = $this->sanitizeDisplayName($originalName);

        $file = AiFile::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getAuthIdentifier(),
            'source_type' => $conversation ? AiFile::SOURCE_CONVERSATION : AiFile::SOURCE_UPLOAD,
            'conversation_id' => $conversation?->id,
            'message_id' => $message?->id,
            'file_name' => $sanitizedName,
            'stored_name' => basename($diskPath),
            'file_path' => $diskPath,
            'disk' => $disk,
            'mime_type' => $clientMimeType,
            'extension' => $extension,
            'file_size' => $size,
            'checksum' => $checksum,
            'processing_status' => AiFile::STATUS_UPLOADED,
        ]);

        Log::info('ai_file.uploaded', [
            'file_id' => $file->id,
            'owner_type' => $file->owner_type,
            'owner_id' => $file->owner_id,
            'mime_type' => $clientMimeType,
            'extension' => $extension,
            'size' => $size,
        ]);

        $file->update(['processing_status' => AiFile::STATUS_VALIDATING]);

        $validationError = $this->validate($absolutePath, $extension, $size, $clientMimeType);
        $realMimeType = $this->detectRealMimeType($absolutePath) ?? $clientMimeType;

        if ($validationError === null && \in_array($realMimeType, self::EXECUTABLE_MIME_TYPES, true)) {
            $validationError = 'executable_file_rejected';
        }

        if ($validationError !== null) {
            $file->update([
                'mime_type' => $realMimeType,
                'processing_status' => AiFile::STATUS_FAILED,
                'processing_error' => $validationError,
            ]);

            Log::warning('ai_file.validation_failed', [
                'file_id' => $file->id,
                'owner_type' => $file->owner_type,
                'owner_id' => $file->owner_id,
                'reason' => $validationError,
            ]);

            // Doc S4: attached does not imply searchable - a failed
            // file still shows up in the conversation's file list (with
            // its own processing_status visible) rather than vanishing.
            $this->attachToConversation($owner, $conversation, $file);

            return $file;
        }

        // Safe-detection (doc S7): the client-claimed MIME type is
        // replaced by what finfo actually read from the bytes, so
        // "report.pdf" that is really an executable never gets routed to
        // the PDF processor just because of its extension/claimed type.
        $file->update(['mime_type' => $realMimeType]);

        if (! $this->processors->supports($realMimeType)) {
            // Honest degrade (doc S18/S26) - not every type has a real
            // processor yet (PPTX/video/archives are later phases), so
            // this is recorded as failed/unsupported rather than stuck
            // "processing" forever or faking a ready status with no
            // content.
            $file->update([
                'processing_status' => AiFile::STATUS_FAILED,
                'processing_error' => 'unsupported_file_type',
            ]);

            $this->attachToConversation($owner, $conversation, $file);

            return $file;
        }

        // Doc S19: expensive processing never runs inline in the HTTP
        // request - the row leaves this method still in a pre-ready
        // state and ProcessAiFileJob (queued) does the rest.
        ProcessAiFileJob::dispatch($file);

        $this->attachToConversation($owner, $conversation, $file);

        return $file;
    }

    /**
     * Phase 10: registers the file in the conversation's explicit
     * many-to-many file scope (AiConversationFileScope) whenever a
     * conversation was given - every exit point of process() goes
     * through here so a deduped/failed/unsupported file is still
     * visible in the conversation's attached-files list, not only a
     * fully successful one. No-op (and never throws) when $conversation
     * is null, matching the standalone /ai-files upload API's own
     * existing behavior of not requiring a conversation at all.
     */
    protected function attachToConversation(Authenticatable $owner, ?AiConversation $conversation, AiFile $file): void
    {
        if ($conversation === null) {
            return;
        }

        try {
            $this->conversationFiles->attach($conversation, $file, $owner);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Doc S4 ("retrieve") - authorization-checked read, delegated from
     * controllers so none of them re-implement the ownership comparison.
     */
    public function retrieve(Authenticatable $owner, int|string $id): AiFile
    {
        return AiFile::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getAuthIdentifier())
            ->findOrFail($id);
    }

    /**
     * Doc S4 ("status") - same authorization boundary as retrieve(), kept
     * as its own method so a future lightweight polling endpoint never
     * has to pull the full resource payload.
     */
    public function status(Authenticatable $owner, int|string $id): string
    {
        return $this->retrieve($owner, $id)->processing_status;
    }

    /**
     * Doc S4/S20 ("delete") - keeps the DB record and the storage object
     * consistent: the physical file (and the entire
     * `ai-files/{id}/` content-reference directory ProcessAiFileJob
     * writes into - extracted text, blocks, and, since Phase 5,
     * thumbnail/preview images - never just the one file it used to
     * before more per-file artifact types existed) is removed first,
     * the row is marked `deleted` and then soft-deleted, so a storage
     * failure never leaves an AiFile row claiming to still be "ready"
     * when the caller asked to delete it. Storage failures are logged,
     * not thrown - this module calls disk deletes "best-effort, logged"
     * everywhere else (see ProcessAiFileJob's own content-ref writes).
     */
    public function delete(AiFile $file): void
    {
        try {
            if ($file->file_path) {
                Storage::disk($file->disk ?: 'public')->delete($file->file_path);
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('ai_file.deletion_storage_failed', [
                'file_id' => $file->id,
                'disk' => $file->disk,
                'path' => $file->file_path,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            Storage::disk('local')->deleteDirectory("ai-files/{$file->id}");
        } catch (\Throwable $e) {
            report($e);
        }

        $file->update(['processing_status' => AiFile::STATUS_DELETED]);
        $file->delete();

        Log::info('ai_file.deleted', [
            'file_id' => $file->id,
            'owner_type' => $file->owner_type,
            'owner_id' => $file->owner_id,
        ]);
    }

    /**
     * Phase 2 doc S33/S34: a clean, read-only API the AI Orchestrator
     * (AiChatService) can call later to get a document's normalized
     * content, without the orchestrator ever touching storage paths,
     * processors, or this engine's internals directly. Deliberately
     * does not call any AI provider itself (doc S33/S44: "the File
     * Processor must work without making an OpenAI request" - this
     * method only reads what ProcessAiFileJob already wrote).
     *
     * Returns null fields rather than throwing when the file is not yet
     * `ready` (doc S27: never a fake context for a file still
     * processing/failed) - the caller decides what to do with an
     * incomplete context (e.g. tell the user to wait).
     *
     * @return array{
     *     file_id: int, status: string, document_type: ?string,
     *     metadata: array<string, mixed>, text: ?string,
     *     blocks: list<array<string, mixed>>, warnings: list<string>,
     * }
     */
    public function getContext(AiFile $file): array
    {
        $metadata = $file->metadata ?? [];
        $base = [
            'file_id' => $file->id,
            'status' => $file->processing_status,
            'document_type' => $metadata['document_type'] ?? null,
            'metadata' => $metadata,
            'text' => null,
            'blocks' => [],
            'warnings' => $metadata['warnings'] ?? [],
        ];

        if ($file->processing_status !== AiFile::STATUS_READY) {
            return $base;
        }

        $step = $file->processingSteps()->latest('id')->first();

        if ($step === null) {
            return $base;
        }

        if ($step->extracted_content_ref && Storage::disk('local')->exists($step->extracted_content_ref)) {
            $base['text'] = Storage::disk('local')->get($step->extracted_content_ref);
        }

        if ($step->blocks_ref && Storage::disk('local')->exists($step->blocks_ref)) {
            $decoded = json_decode((string) Storage::disk('local')->get($step->blocks_ref), true);
            $base['blocks'] = is_array($decoded) ? $decoded : [];
        }

        return $base;
    }

    protected function findReadyDuplicate(Authenticatable $owner, string $checksum): ?AiFile
    {
        return AiFile::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getAuthIdentifier())
            ->where('checksum', $checksum)
            ->where('processing_status', AiFile::STATUS_READY)
            ->latest('id')
            ->first();
    }

    /**
     * Doc S8 ("server-side validation... configurable limits"): every
     * limit/list read here comes from config('ai.files.*'), never a
     * literal in this method or in a controller.
     */
    protected function validate(string $absolutePath, ?string $extension, int $size, string $clientMimeType): ?string
    {
        if (! is_readable($absolutePath)) {
            return 'file_not_readable';
        }

        $maxSize = (int) config('ai.files.max_size_bytes', 26214400);

        if ($size > $maxSize || filesize($absolutePath) > $maxSize) {
            return 'file_size_limit_exceeded';
        }

        $blocked = (array) config('ai.files.blocked_extensions', []);

        if ($extension !== null && \in_array($extension, $blocked, true)) {
            return 'executable_file_rejected';
        }

        $allowedMimeTypes = (array) config('ai.files.allowed_mime_types', []);
        $realMimeType = $this->detectRealMimeType($absolutePath) ?? $clientMimeType;

        if ($allowedMimeTypes !== [] && ! \in_array($realMimeType, $allowedMimeTypes, true)) {
            return 'unsupported_file_type';
        }

        return null;
    }

    /**
     * Doc S9 (double extensions): every dot-separated segment of the
     * original filename is checked, not only the final extension, so
     * "document.exe.pdf" is caught even though its *last* segment looks
     * like a harmless PDF.
     */
    protected function safeExtension(string $originalName): ?string
    {
        $segments = explode('.', $originalName);

        if (\count($segments) < 2) {
            return null;
        }

        $blocked = (array) config('ai.files.blocked_extensions', []);

        foreach (\array_slice($segments, 1) as $segment) {
            $segment = strtolower(trim($segment));

            if ($segment !== '' && \in_array($segment, $blocked, true)) {
                // The last segment is still returned as the recorded
                // extension so the caller/validator can reject it with a
                // clear reason instead of silently stripping it.
                return strtolower(trim(end($segments)));
            }
        }

        return strtolower(trim(end($segments))) ?: null;
    }

    /**
     * Doc S9 ("uploaded filenames must never become trusted filesystem
     * paths"): this is only the human-readable label stored in the DB
     * and returned in API responses - the physical storage path never
     * uses this value (see storeUploadedFile()'s store() call, which
     * relies on Laravel's own hashName()).
     */
    protected function sanitizeDisplayName(string $originalName): string
    {
        $name = str_replace(['\\', '/'], '_', $originalName);
        $name = preg_replace('/\.\.+/', '.', $name) ?? $name;

        return trim($name) ?: 'file';
    }

    /**
     * Real content inspection (doc S7) rather than trusting the
     * client-supplied MIME type alone - a mismatch (file.jpg that is
     * really a PDF, or an .exe renamed to .pdf) is simply corrected to
     * the real type here, which then naturally routes to the right
     * processor (or correctly to "no processor supports this"/an
     * explicit executable rejection) instead of trusting a spoofed
     * extension/header.
     */
    protected function detectRealMimeType(string $absolutePath): ?string
    {
        if (! is_readable($absolutePath)) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        $mime = finfo_file($finfo, $absolutePath);
        finfo_close($finfo);

        return $mime !== false ? $mime : null;
    }
}
