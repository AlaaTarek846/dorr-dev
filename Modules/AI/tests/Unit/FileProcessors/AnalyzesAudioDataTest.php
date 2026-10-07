<?php

namespace Modules\AI\Tests\Unit\FileProcessors;

use Modules\AI\Services\FileProcessors\Concerns\AnalyzesAudioData;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic coverage for the tag-categorization part of the shared
 * Phase 6 trait - independent of ffmpeg/ffprobe and of the Laravel
 * container.
 */
class AnalyzesAudioDataTest extends TestCase
{
    protected object $subject;

    protected function setUp(): void
    {
        parent::setUp();
        // Widen the trait's protected helpers to public. (Wrapper methods with the same names would
        // call themselves forever and exhaust memory.)
        $this->subject = new class
        {
            use AnalyzesAudioData {
                categorizeAudioTags as public;
                audioStreams as public;
                realVideoStreams as public;
            }
        };
    }

    public function test_categorizes_encoder_as_technical(): void
    {
        $result = $this->subject->categorizeAudioTags(['encoder' => 'Lavf58.76.100']);

        $this->assertSame('Lavf58.76.100', $result['technical']['encoder']);
        $this->assertArrayNotHasKey('encoder', $result['ai_safe']);
        $this->assertArrayNotHasKey('encoder', $result['sensitive']);
    }

    public function test_categorizes_language_as_ai_safe(): void
    {
        $result = $this->subject->categorizeAudioTags(['language' => 'ar']);

        $this->assertSame('ar', $result['ai_safe']['language']);
        $this->assertArrayNotHasKey('language', $result['sensitive']);
    }

    public function test_categorizes_title_artist_album_genre_as_sensitive(): void
    {
        // Doc S10: free-text, user-supplied fields - never promoted to
        // ai_safe, same conservative stance as Phase 5's camera
        // make/model, since a personal voice memo's title can trivially
        // contain a real name.
        $result = $this->subject->categorizeAudioTags([
            'title' => 'My Voice Memo',
            'artist' => 'Ali',
            'album' => 'Notes',
            'genre' => 'Speech',
        ]);

        $this->assertSame('My Voice Memo', $result['sensitive']['title']);
        $this->assertSame('Ali', $result['sensitive']['artist']);
        $this->assertSame('Notes', $result['sensitive']['album']);
        $this->assertSame('Speech', $result['sensitive']['genre']);
        $this->assertArrayNotHasKey('title', $result['ai_safe']);
        $this->assertArrayNotHasKey('title', $result['technical']);
    }

    public function test_year_is_read_from_either_year_or_date_key(): void
    {
        // Verified against real ffmpeg output in this session: ffmpeg
        // itself writes the year under the `date` tag key, not `year`.
        $viaDate = $this->subject->categorizeAudioTags(['date' => '2026']);
        $viaYear = $this->subject->categorizeAudioTags(['year' => '2025']);

        $this->assertSame('2026', $viaDate['sensitive']['year']);
        $this->assertSame('2025', $viaYear['sensitive']['year']);
    }

    public function test_tag_keys_are_matched_case_insensitively(): void
    {
        $result = $this->subject->categorizeAudioTags(['Title' => 'Hi', 'ENCODER' => 'x']);

        $this->assertSame('Hi', $result['sensitive']['title']);
        $this->assertSame('x', $result['technical']['encoder']);
    }

    public function test_empty_tags_produce_empty_buckets(): void
    {
        $result = $this->subject->categorizeAudioTags([]);

        $this->assertSame([], $result['technical']);
        $this->assertSame([], $result['ai_safe']);
        $this->assertSame([], $result['sensitive']);
    }

    public function test_audio_streams_filters_by_codec_type(): void
    {
        $streams = [
            ['codec_type' => 'audio', 'codec_name' => 'mp3'],
            ['codec_type' => 'video', 'codec_name' => 'mjpeg', 'disposition' => ['attached_pic' => 1]],
        ];

        $audio = $this->subject->audioStreams($streams);

        $this->assertCount(1, $audio);
        $this->assertSame('mp3', $audio[0]['codec_name']);
    }

    public function test_attached_cover_art_is_not_a_real_video_stream(): void
    {
        // Verified against a real MP3 with an embedded cover image in
        // this session: ffprobe reports it as a second stream with
        // codec_type "video" and disposition.attached_pic = 1.
        $streams = [
            ['codec_type' => 'audio', 'codec_name' => 'mp3'],
            ['codec_type' => 'video', 'codec_name' => 'mjpeg', 'disposition' => ['attached_pic' => 1]],
        ];

        $this->assertSame([], $this->subject->realVideoStreams($streams));
    }

    public function test_a_genuine_video_stream_is_detected_as_real_video(): void
    {
        $streams = [
            ['codec_type' => 'audio', 'codec_name' => 'opus'],
            ['codec_type' => 'video', 'codec_name' => 'vp9', 'disposition' => ['attached_pic' => 0]],
        ];

        $this->assertCount(1, $this->subject->realVideoStreams($streams));
    }
}
