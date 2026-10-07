<?php

namespace Modules\AI\Services\FileProcessors;

use Modules\AI\Services\AiDocumentTextExtractor;

/**
 * Phase 2 (Document Processing): page-aware extraction, metadata, and
 * scanned-PDF detection - all real, all gated behind
 * `smalot/pdfparser` actually being installed (it is NOT in this
 * project's composer.json today). This mirrors how Phase 1 already
 * reported `page_count` only when smalot was present - master plan #46
 * ("never fake file support"): without the real per-page API smalot
 * provides, true page boundaries cannot be reconstructed safely from
 * raw PDF bytes (the dependency-free fallback in AiDocumentTextExtractor
 * only scrapes text-showing operators out of decompressed content
 * streams - it has no page-tree/object-graph parsing, so it cannot know
 * which stream belongs to which page). Per the Phase 2 doc's own Critical
 * Rule ("do not silently downgrade requirements... stop that part and
 * explain why"), the no-dependency path below returns one
 * whole-document block with an explicit warning instead of pretending to
 * track pages - see the Phase 2 report for the exact command to install
 * smalot/pdfparser and upgrade this automatically (no code change
 * needed once it's present).
 */
class PdfFileProcessor implements AiFileProcessorInterface
{
    public function __construct(protected AiDocumentTextExtractor $extractor) {}

    public function supports(string $mimeType): bool
    {
        return $mimeType === 'application/pdf';
    }

    public function process(string $absolutePath, string $mimeType): AiFileProcessingResult
    {
        if (! $this->supports($mimeType)) {
            return AiFileProcessingResult::failed('unsupported_mime_type');
        }

        if (class_exists(\Smalot\PdfParser\Parser::class)) {
            return $this->processWithSmalot($absolutePath);
        }

        return $this->processWithoutDependencies($absolutePath, $mimeType);
    }

    protected function processWithSmalot(string $absolutePath): AiFileProcessingResult
    {
        try {
            $document = (new \Smalot\PdfParser\Parser)->parseFile($absolutePath);
        } catch (\Throwable) {
            // Doc S7 ("corrupted PDF detection"): a PDF smalot cannot
            // even open is reported as a clean, named failure - never a
            // 500 and never a silently empty "ready" document.
            return AiFileProcessingResult::failed('PDF_PARSE_FAILED', retryable: false);
        }

        $pages = $document->getPages();
        $pageCount = count($pages);

        if ($pageCount === 0) {
            return AiFileProcessingResult::failed('PDF_PARSE_FAILED', retryable: false);
        }

        $blocks = [];
        $textParts = [];
        $totalChars = 0;
        $warnings = [];

        foreach ($pages as $index => $page) {
            $pageNumber = $index + 1;

            try {
                $pageText = $this->sanitizeUtf8(trim((string) $page->getText()));
            } catch (\Throwable) {
                $pageText = '';
                $warnings[] = "page_{$pageNumber}_extraction_failed";
            }

            $totalChars += strlen($pageText);

            if ($pageText !== '') {
                $textParts[] = $pageText;

                foreach ($this->splitIntoParagraphs($pageText) as $paragraph) {
                    $blocks[] = [
                        'type' => 'paragraph',
                        'text' => $paragraph,
                        'source' => ['page' => $pageNumber],
                    ];
                }
            }
        }

        $details = [];

        try {
            $details = $document->getDetails();
        } catch (\Throwable) {
            // Doc S9: optional metadata missing must never fail the
            // whole document.
        }

        // Doc S10 (scanned PDF detection): a real, honest heuristic -
        // many pages but almost no extractable text per page - never an
        // automatically-built fake OCR pass (doc is explicit: mark
        // requires_ocr/OCR_REQUIRED and stop there).
        $averageCharsPerPage = $pageCount > 0 ? $totalChars / $pageCount : 0;
        $requiresOcr = $averageCharsPerPage < 20;

        if ($requiresOcr) {
            $warnings[] = 'OCR_REQUIRED';
        }

        $text = $this->sanitizeUtf8(trim(implode("\n\n", $textParts)));

        if ($text === '' && ! $requiresOcr) {
            return AiFileProcessingResult::failed('PDF_PARSE_FAILED');
        }

        return AiFileProcessingResult::ok(
            text: $text !== '' ? $text : null,
            metadata: [
                'page_count' => $pageCount,
                'title' => $details['Title'] ?? null,
                'author' => $details['Author'] ?? null,
                'creator' => $details['Creator'] ?? null,
                'producer' => $details['Producer'] ?? null,
                'created_at' => $details['CreationDate'] ?? null,
                'modified_at' => $details['ModDate'] ?? null,
                'requires_ocr' => $requiresOcr,
            ],
            blocks: $blocks,
            warnings: $warnings,
            documentType: 'pdf',
        );
    }

    /**
     * Honest degrade (see class docblock) - one whole-document block,
     * no fake page numbers, no fake scanned-PDF detection.
     */
    protected function processWithoutDependencies(string $absolutePath, string $mimeType): AiFileProcessingResult
    {
        $text = $this->extractor->extract($absolutePath, $mimeType);

        if ($text === null) {
            return AiFileProcessingResult::failed('PDF_PARSE_FAILED');
        }

        $blocks = array_map(
            fn (string $paragraph) => [
                'type' => 'paragraph',
                'text' => $paragraph,
                'source' => ['page' => null],
            ],
            $this->splitIntoParagraphs($text),
        );

        return AiFileProcessingResult::ok(
            text: $text,
            metadata: ['page_count' => null, 'requires_ocr' => null],
            blocks: $blocks,
            warnings: [
                'page_boundaries_unavailable_install_smalot_pdfparser',
                'scanned_pdf_detection_unavailable_install_smalot_pdfparser',
            ],
            documentType: 'pdf',
        );
    }

    /**
     * @return list<string>
     */
    protected function splitIntoParagraphs(string $text): array
    {
        $parts = preg_split('/\n{2,}/', $text) ?: [$text];

        return array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
    }

    protected function sanitizeUtf8(string $text): string
    {
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);

        if (! is_string($clean) || $clean === '') {
            $clean = @mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        return is_string($clean) ? $clean : '';
    }
}
