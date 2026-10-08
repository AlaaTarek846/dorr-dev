<?php

namespace Modules\AI\Tests\Unit\Chunking;

use Modules\AI\Services\Chunking\Strategies\AudioTranscriptChunker;
use Modules\AI\Services\Chunking\Strategies\VideoTranscriptChunker;
use Tests\TestCase;

/**
 * Phase 8's own disclosed limitation: `metadata['transcript']['segments']`
 * is a documented shape nothing in this codebase currently populates for
 * either audio or video files, so `supports()` honestly returns false for
 * any real file processed today. These tests exercise the logic against
 * the documented shape directly (as a forward-compatibility guarantee),
 * and separately pin the honest "does not fire today" behavior so that
 * fact cannot silently regress into a false claim of support.
 */
class AudioTranscriptChunkerTest extends TestCase
{
    public function test_does_not_support_a_real_audio_file_today(): void
    {
        $chunker = new AudioTranscriptChunker;

        $this->assertFalse($chunker->supports([
            'document_type' => 'audio',
            'metadata' => ['duration_seconds' => 30],
        ]));
    }

    public function test_supports_the_documented_transcript_shape_when_present(): void
    {
        $chunker = new AudioTranscriptChunker;

        $this->assertTrue($chunker->supports([
            'metadata' => ['transcript' => ['segments' => [['text' => 'hi', 'start' => 0, 'end' => 1]]]],
        ]));
    }

    public function test_groups_segments_by_speaker_and_preserves_timestamps(): void
    {
        config(['ai.chunking.transcript.max_seconds' => 60]);
        $chunker = new AudioTranscriptChunker;

        $drafts = $chunker->chunk([
            'metadata' => ['transcript' => ['segments' => [
                ['text' => 'Hello there.', 'start' => 0.0, 'end' => 2.0, 'speaker' => 'A'],
                ['text' => 'How are you?', 'start' => 2.0, 'end' => 4.0, 'speaker' => 'A'],
                ['text' => 'I am good.', 'start' => 4.0, 'end' => 6.0, 'speaker' => 'B'],
            ]]],
        ]);

        $this->assertCount(2, $drafts);
        $this->assertSame(0.0, $drafts[0]->metadata['timestamp_start']);
        $this->assertSame(4.0, $drafts[0]->metadata['timestamp_end']);
        $this->assertSame(['A'], $drafts[0]->metadata['speakers']);
        $this->assertSame(['B'], $drafts[1]->metadata['speakers']);
    }

    public function test_long_duration_without_speaker_change_still_splits(): void
    {
        config(['ai.chunking.transcript.max_seconds' => 5]);
        $chunker = new AudioTranscriptChunker;

        $drafts = $chunker->chunk([
            'metadata' => ['transcript' => ['segments' => [
                ['text' => 'Part one.', 'start' => 0.0, 'end' => 3.0],
                ['text' => 'Part two.', 'start' => 3.0, 'end' => 7.0],
                ['text' => 'Part three.', 'start' => 7.0, 'end' => 10.0],
            ]]],
        ]);

        $this->assertGreaterThan(1, count($drafts));
    }

    public function test_video_transcript_chunker_requires_video_document_type(): void
    {
        $chunker = new VideoTranscriptChunker;

        $this->assertFalse($chunker->supports([
            'document_type' => 'audio',
            'metadata' => ['transcript' => ['segments' => [['text' => 'hi']]]],
        ]));

        $this->assertTrue($chunker->supports([
            'document_type' => 'video',
            'metadata' => ['transcript' => ['segments' => [['text' => 'hi']]]],
        ]));
    }

    public function test_video_transcript_chunks_use_video_content_type(): void
    {
        $chunker = new VideoTranscriptChunker;

        $drafts = $chunker->chunk([
            'document_type' => 'video',
            'metadata' => ['transcript' => ['segments' => [['text' => 'Scene one.', 'start' => 0.0, 'end' => 5.0]]]],
        ]);

        $this->assertSame('video_transcript', $drafts[0]->contentType);
    }
}
