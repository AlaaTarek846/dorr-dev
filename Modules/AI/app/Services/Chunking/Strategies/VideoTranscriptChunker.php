<?php

namespace Modules\AI\Services\Chunking\Strategies;

use Modules\AI\Services\Chunking\AiChunkerInterface;

/**
 * Phase 8 (doc S28): same conceptual strategy as AudioTranscriptChunker
 * (start/end timestamp + speaker-where-available), reusing its segment-
 * grouping logic rather than duplicating it. Never stores raw video
 * frames inside a chunk record - frames remain external
 * assets/references only (see Phase 7's `preview_assets`), handled by
 * ImageReferenceChunker if/when a frame needs an indexable record at
 * all.
 *
 * Same honest limitation as AudioTranscriptChunker: `supports()` keys
 * off `metadata['transcript']['segments']`, a shape nothing in this
 * codebase populates for video today.
 */
class VideoTranscriptChunker extends AudioTranscriptChunker implements AiChunkerInterface
{
    public function supports(array $normalizedContent): bool
    {
        return ($normalizedContent['document_type'] ?? null) === 'video'
            && ! empty($normalizedContent['metadata']['transcript']['segments']);
    }

    public function chunk(array $normalizedContent): array
    {
        $segments = $normalizedContent['metadata']['transcript']['segments'] ?? [];
        $maxSeconds = (float) config('ai.chunking.transcript.max_seconds', 60);

        return $this->chunkSegments($segments, $maxSeconds, 'video_transcript');
    }
}
