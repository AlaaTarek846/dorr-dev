<?php

namespace Modules\AI\Services\FileProcessors;

/**
 * The normalized output every AiFileProcessorInterface implementation
 * returns - see the interface's own docblock for why this exists instead
 * of each processor returning an ad-hoc shape.
 *
 * Phase 2 (Document Processing) adds `blocks`/`warnings`/`documentType`/
 * `stats` without touching the Phase 1 `text`/`metadata` fields a
 * non-document processor (ExcelFileProcessor) still returns as-is - this
 * keeps both "phases" of processor on one shared result type instead of
 * forking a second one.
 */
class AiFileProcessingResult
{
    /**
     * @param  array<string, mixed>  $metadata  Free-form, per-file-type
     *                                          metadata (page_count,
     *                                          sheet_count, title,
     *                                          author, requires_ocr,
     *                                          ...) - stored as-is into
     *                                          ai_files.metadata.
     * @param  list<array<string, mixed>>  $blocks  Normalized structural
     *                                              blocks (doc S5/S6):
     *                                              heading/paragraph/
     *                                              list/table/quote/
     *                                              code/link/
     *                                              image_reference, each
     *                                              with an optional
     *                                              `source` reference
     *                                              (page/path/line).
     *                                              Empty for processors
     *                                              that only produce flat
     *                                              text (e.g. the
     *                                              spreadsheet
     *                                              processor).
     * @param  list<string>  $warnings  Non-fatal issues (doc S9: optional
     *                                  metadata missing, a page that
     *                                  failed to extract, ...) that must
     *                                  not fail the whole document.
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $text = null,
        public readonly array $metadata = [],
        public readonly ?string $error = null,
        public readonly array $blocks = [],
        public readonly array $warnings = [],
        public readonly ?string $documentType = null,
        public readonly bool $retryable = false,
    ) {}

    public static function ok(?string $text, array $metadata = [], array $blocks = [], array $warnings = [], ?string $documentType = null): self
    {
        return new self(success: true, text: $text, metadata: $metadata, blocks: $blocks, warnings: $warnings, documentType: $documentType);
    }

    public static function failed(string $error, bool $retryable = false): self
    {
        return new self(success: false, error: $error, retryable: $retryable);
    }
}
