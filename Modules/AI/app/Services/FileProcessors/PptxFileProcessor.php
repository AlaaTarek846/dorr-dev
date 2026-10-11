<?php

namespace Modules\AI\Services\FileProcessors;

use Modules\AI\Services\FileProcessors\Concerns\ExtractsPresentationSlides;
use PhpOffice\PhpPresentation\IOFactory;
use PhpOffice\PhpPresentation\PhpPresentation;

/**
 * Phase 4 (doc S3/S5): first real PPTX support in this codebase, via
 * `phpoffice/phppresentation` - NOT yet in composer.json (see this
 * phase's Final Report §4 for the exact command to run; this processor
 * degrades to an honest `PPTX_PROCESSOR_UNAVAILABLE` failure, never a
 * fake success, until it's installed).
 *
 * Keeps the presentation slide-aware throughout (doc S2: "DO NOT flatten
 * the entire presentation into one plain text string") - the real,
 * structured output is `AiFileProcessingResult::metadata['slides']`
 * (same place ExcelFileProcessor puts its per-sheet structure);
 * `::text` is only a flat preview for callers that still want plain
 * text, same convention as every other structured processor in this
 * module.
 */
class PptxFileProcessor implements AiFileProcessorInterface
{
    use ExtractsPresentationSlides;

    protected const SUPPORTED = ['application/vnd.openxmlformats-officedocument.presentationml.presentation'];

    protected const READER_NAME = 'PowerPoint2007';

    protected const DOCUMENT_TYPE = 'pptx';

    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, static::SUPPORTED, true);
    }

    public function process(string $absolutePath, string $mimeType): AiFileProcessingResult
    {
        if (! $this->supports($mimeType)) {
            return AiFileProcessingResult::failed('unsupported_mime_type');
        }

        if (! class_exists(IOFactory::class)) {
            return AiFileProcessingResult::failed(
                static::DOCUMENT_TYPE === 'ppt' ? 'PPT_PROCESSOR_UNAVAILABLE' : 'PPTX_PROCESSOR_UNAVAILABLE'
            );
        }

        try {
            $presentation = IOFactory::createReader(static::READER_NAME)->load($absolutePath);
        } catch (\Throwable) {
            return AiFileProcessingResult::failed(static::DOCUMENT_TYPE === 'ppt' ? 'PPT_PARSE_FAILED' : 'PPTX_PARSE_FAILED');
        }

        $warnings = [];
        $slides = $this->extractSlides($presentation, $warnings);

        if ($slides === []) {
            return AiFileProcessingResult::failed('no_data_found');
        }

        $metadata = $this->documentMetadata($presentation, $slides);
        $text = $this->buildPreviewText($slides);

        return AiFileProcessingResult::ok(
            text: $text !== '' ? $text : null,
            metadata: $metadata,
            warnings: $warnings,
            documentType: static::DOCUMENT_TYPE,
        );
    }

    /**
     * Doc S18: presentation-level metadata, never failing processing
     * when an optional field is unavailable.
     *
     * @param  list<array<string, mixed>>  $slides
     * @return array<string, mixed>
     */
    protected function documentMetadata(PhpPresentation $presentation, array $slides): array
    {
        $metadata = [
            'slide_count' => count($slides),
            'slides' => $slides,
        ];

        try {
            $props = $presentation->getDocumentProperties();
            $metadata += [
                'title' => $props->getTitle() ?: null,
                'subject' => $props->getSubject() ?: null,
                'author' => $props->getCreator() ?: null,
                'company' => $props->getCompany() ?: null,
                'created_at' => $props->getCreated() ? date('c', $props->getCreated()) : null,
                'modified_at' => $props->getModified() ? date('c', $props->getModified()) : null,
            ];
        } catch (\Throwable) {
            // Doc S18: do not fail processing if optional metadata is unavailable.
        }

        return $metadata;
    }

    /**
     * @param  list<array<string, mixed>>  $slides
     */
    protected function buildPreviewText(array $slides): string
    {
        $lines = [];

        foreach ($slides as $slide) {
            if ($slide['hidden']) {
                continue;
            }

            $lines[] = '## Slide '.$slide['number'].($slide['title'] ? ': '.$slide['title'] : '');

            foreach ($slide['blocks'] as $block) {
                $lines[] = match ($block['type']) {
                    'paragraph' => $block['text'],
                    'list' => implode("\n", array_map(fn ($item) => '- '.$item, $block['items'])),
                    'table' => implode(' | ', $block['headers']),
                    'link' => $block['text'].' ('.$block['url'].')',
                    default => null,
                };
            }
        }

        return trim(implode("\n", array_filter($lines, fn ($l) => $l !== null)));
    }
}
