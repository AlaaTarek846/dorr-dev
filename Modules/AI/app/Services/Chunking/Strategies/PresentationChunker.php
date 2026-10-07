<?php

namespace Modules\AI\Services\Chunking\Strategies;

use Modules\AI\Services\Chunking\AiChunkDraft;
use Modules\AI\Services\Chunking\AiChunkerInterface;
use Modules\AI\Services\Chunking\Concerns\ChunksNormalizedBlocks;

/**
 * Phase 8 (doc S26): chunks by slide, reusing PptxFileProcessor's
 * already-normalized `metadata['slides']` shape (title/title_source/
 * blocks[same shape as document blocks]/notes/hidden/source.slide -
 * confirmed by inspection). An oversized slide is split while still
 * preserving its slide_number on every resulting chunk.
 */
class PresentationChunker implements AiChunkerInterface
{
    use ChunksNormalizedBlocks;

    private const SUPPORTED_TYPES = ['pptx', 'ppt'];

    public function supports(array $normalizedContent): bool
    {
        if (in_array($normalizedContent['document_type'] ?? null, self::SUPPORTED_TYPES, true)) {
            return true;
        }

        return ! empty($normalizedContent['metadata']['slides']);
    }

    public function chunk(array $normalizedContent): array
    {
        $slides = $normalizedContent['metadata']['slides'] ?? [];
        $maxCharacters = (int) config('ai.chunking.presentation.max_characters', 1500);
        $slidesPerChunk = max(1, (int) config('ai.chunking.presentation.slides_per_chunk', 1));

        $drafts = [];
        $index = 0;

        foreach (array_chunk($slides, $slidesPerChunk) as $slideGroup) {
            $lines = [];
            $slideNumbers = [];

            foreach ($slideGroup as $slide) {
                $slideNumber = $slide['source']['slide'] ?? null;
                $slideNumbers[] = $slideNumber;

                $title = $slide['title'] ?? null;
                $header = $title ? "Slide {$slideNumber}: {$title}" : "Slide {$slideNumber}";
                $lines[] = $header;

                foreach ((array) ($slide['blocks'] ?? []) as $block) {
                    $text = $this->blockToPlainText($block);

                    if ($text !== '') {
                        $lines[] = $text;
                    }
                }

                if (! empty($slide['notes'])) {
                    $lines[] = 'Notes: '.$slide['notes'];
                }
            }

            $text = implode("\n", $lines);

            if (mb_strlen($text) <= $maxCharacters * 2) {
                $drafts[] = new AiChunkDraft($index++, $text, 'presentation', [
                    'slide_number' => $slideNumbers[0] ?? null,
                    'slide_numbers' => $slideNumbers,
                ]);

                continue;
            }

            // Oversized single slide (or group): hard-split via the
            // shared text chunker while every resulting piece still
            // carries the originating slide_number(s) (doc S26).
            foreach (app(\Modules\AI\Services\AiTextChunker::class)->chunk($text, $maxCharacters, 0) as $piece) {
                $drafts[] = new AiChunkDraft($index++, $piece, 'presentation', [
                    'slide_number' => $slideNumbers[0] ?? null,
                    'slide_numbers' => $slideNumbers,
                ]);
            }
        }

        return $drafts;
    }
}
