<?php

namespace Modules\AI\Services\Chunking\Strategies;

use Modules\AI\Services\Chunking\AiChunkDraft;
use Modules\AI\Services\Chunking\AiChunkerInterface;

/**
 * Phase 8 (doc S29): images must NOT become arbitrary text chunks.
 * Produces exactly one non-text-split record per image carrying
 * dimensions/format/any ALREADY-present OCR text or caption - this
 * strategy never triggers OCR or vision itself, it only packages
 * whatever the processor already attached to metadata, preparing the
 * architecture for future multimodal retrieval (Phase 9+) without
 * implementing it.
 */
class ImageReferenceChunker implements AiChunkerInterface
{
    public function supports(array $normalizedContent): bool
    {
        if (($normalizedContent['document_type'] ?? null) === 'image') {
            return true;
        }

        $blocks = $normalizedContent['blocks'] ?? [];

        return $blocks !== [] && ($blocks[0]['type'] ?? null) === 'image_reference';
    }

    public function chunk(array $normalizedContent): array
    {
        $metadata = $normalizedContent['metadata'] ?? [];
        $blocks = $normalizedContent['blocks'] ?? [];

        // Single-image file (document_type === 'image'): one record for
        // the whole file's own metadata.
        if (($normalizedContent['document_type'] ?? null) === 'image') {
            return [$this->draft(0, $metadata)];
        }

        // Embedded image_reference blocks inside a larger document: one
        // record per reference, never merged into surrounding prose.
        $drafts = [];
        $index = 0;

        foreach ($blocks as $block) {
            if (($block['type'] ?? null) !== 'image_reference') {
                continue;
            }

            $drafts[] = $this->draft($index++, array_merge($metadata, $block));
        }

        return $drafts;
    }

    private function draft(int $index, array $imageMetadata): AiChunkDraft
    {
        $caption = $imageMetadata['caption'] ?? $imageMetadata['description'] ?? null;
        $ocrText = $imageMetadata['ocr_text'] ?? null;

        // The "content" of an image chunk is whatever text is already
        // available (caption/OCR) - never invented, never triggering
        // generation. An image with neither still gets a record (so it
        // remains discoverable by its metadata alone).
        $content = trim(implode("\n", array_filter([$caption, $ocrText])));

        return new AiChunkDraft($index, $content, 'image_reference', array_filter([
            'width' => $imageMetadata['width'] ?? null,
            'height' => $imageMetadata['height'] ?? null,
            'format' => $imageMetadata['format'] ?? null,
            'has_ocr_text' => $ocrText !== null,
            'has_caption' => $caption !== null,
            'index' => $imageMetadata['index'] ?? null,
        ], fn ($v) => $v !== null));
    }
}
