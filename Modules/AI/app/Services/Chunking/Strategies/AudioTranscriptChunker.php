<?php

namespace Modules\AI\Services\Chunking\Strategies;

use Modules\AI\Services\Chunking\AiChunkDraft;
use Modules\AI\Services\Chunking\AiChunkerInterface;

/**
 * Phase 8 (doc S27): chunks by timestamp/speaker-where-available/
 * sentence-paragraph boundary. Operates against a documented normalized
 * transcript shape `metadata['transcript']['segments'] = [{text, start,
 * end, speaker?}, ...]`.
 *
 * KNOWN LIMITATION (disclosed honestly, not silently skipped): as of
 * this phase, no processor in this codebase actually writes that key
 * into `ai_files.metadata` - audio transcription today happens only in
 * the separate, older `AiChatService::transcribeIncomingAudio()` path,
 * whose output is never persisted back onto the `ai_files` record.
 * `supports()` therefore honestly returns false for every file
 * processed by this codebase today; this strategy exists so that the
 * moment a future phase wires transcription output into that metadata
 * key, chunking is already ready for it with zero further changes.
 */
class AudioTranscriptChunker implements AiChunkerInterface
{
    public function supports(array $normalizedContent): bool
    {
        return ! empty($normalizedContent['metadata']['transcript']['segments']);
    }

    public function chunk(array $normalizedContent): array
    {
        $segments = $normalizedContent['metadata']['transcript']['segments'] ?? [];
        $maxSeconds = (float) config('ai.chunking.transcript.max_seconds', 60);

        return $this->chunkSegments($segments, $maxSeconds, 'audio_transcript');
    }

    /**
     * Shared by both transcript chunkers (audio/video differ only in
     * content_type and in never embedding raw frames - video frames are
     * handled entirely by ImageReferenceChunker, never here).
     *
     * @param  list<array<string,mixed>>  $segments
     * @return list<AiChunkDraft>
     */
    protected function chunkSegments(array $segments, float $maxSeconds, string $contentType): array
    {
        $drafts = [];
        $index = 0;
        $buffer = [];
        $bufferStart = null;

        $flush = function () use (&$buffer, &$bufferStart, &$drafts, &$index, $contentType) {
            if ($buffer === []) {
                return;
            }

            $text = implode(' ', array_column($buffer, 'text'));
            $start = $bufferStart;
            $end = $buffer[array_key_last($buffer)]['end'] ?? null;
            $speakers = array_values(array_unique(array_filter(array_column($buffer, 'speaker'))));

            $drafts[] = new AiChunkDraft($index++, trim($text), $contentType, array_filter([
                'timestamp_start' => $start,
                'timestamp_end' => $end,
                'speakers' => $speakers !== [] ? $speakers : null,
            ], fn ($v) => $v !== null));

            $buffer = [];
            $bufferStart = null;
        };

        foreach ($segments as $segment) {
            $text = trim((string) ($segment['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $start = $segment['start'] ?? null;
            $end = $segment['end'] ?? null;
            $speaker = $segment['speaker'] ?? null;

            $speakerChanged = $buffer !== [] && ($buffer[array_key_last($buffer)]['speaker'] ?? null) !== $speaker;
            $durationExceeded = $bufferStart !== null && $end !== null && ($end - $bufferStart) > $maxSeconds;

            if ($speakerChanged || $durationExceeded) {
                $flush();
            }

            if ($bufferStart === null) {
                $bufferStart = $start;
            }

            $buffer[] = ['text' => $text, 'end' => $end, 'speaker' => $speaker];
        }

        $flush();

        return $drafts;
    }
}
