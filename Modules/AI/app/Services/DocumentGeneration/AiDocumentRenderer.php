<?php

namespace Modules\AI\Services\DocumentGeneration;

use InvalidArgumentException;

/**
 * Single entry point AiChatService calls to turn parsed document blocks
 * (see AiDocumentContentParser) into real file bytes, dispatching to the
 * format-specific renderer. Replaces the old generateDownloadableFile()
 * behaviour of always writing the raw chat reply as a .md file.
 */
class AiDocumentRenderer
{
    public function __construct(
        protected AiPdfDocumentRenderer $pdf,
        protected AiDocxDocumentRenderer $docx,
        protected AiXlsxDocumentRenderer $xlsx,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return array{bytes: string, extension: string, mime: string}
     */
    public function render(string $format, string $title, array $blocks): array
    {
        return match ($format) {
            'pdf' => [
                'bytes' => $this->pdf->render($title, $blocks),
                'extension' => 'pdf',
                'mime' => 'application/pdf',
            ],
            'docx' => [
                'bytes' => $this->docx->render($title, $blocks),
                'extension' => 'docx',
                'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
            'xlsx' => [
                'bytes' => $this->xlsx->render($title, $blocks),
                'extension' => 'xlsx',
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
            default => throw new InvalidArgumentException("Unsupported document format: {$format}"),
        };
    }
}
