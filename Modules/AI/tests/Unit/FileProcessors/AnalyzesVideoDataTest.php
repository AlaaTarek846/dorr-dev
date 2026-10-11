<?php

namespace Modules\AI\Tests\Unit\FileProcessors;

use Modules\AI\Services\FileProcessors\Concerns\AnalyzesVideoData;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Plain PHPUnit\Framework\TestCase (no `config()` dependency) - same
 * convention as AnalyzesAudioDataTest. Wraps the trait in an anonymous
 * class so its `protected` methods are directly testable.
 */
class AnalyzesVideoDataTest extends TestCase
{
    protected object $subject;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new class
        {
            use AnalyzesVideoData;

            public function callSubtitleStreams(array $s): array
            {
                return $this->subtitleStreams($s);
            }

            public function callSelectPrimaryStream(array $s): ?array
            {
                return $this->selectPrimaryStream($s);
            }

            public function callParseFrameRate(?string $r): ?float
            {
                return $this->parseFrameRate($r);
            }

            public function callDisplayDimensions(array $s, int $w, int $h): array
            {
                return $this->displayDimensions($s, $w, $h);
            }

            public function callPosterTimestamp(float $d): float
            {
                return $this->posterTimestamp($d);
            }

            public function callCaptureFrame(string $bin, string $path, int $idx, float $at, int $maxDim, int $timeout): ?array
            {
                return $this->captureFrame($bin, $path, $idx, $at, $maxDim, $timeout);
            }

            public function callExtractAudioToTempFile(string $bin, string $path, int $idx, float $dur, int $maxSec, int $timeout): ?string
            {
                return $this->extractAudioToTempFile($bin, $path, $idx, $dur, $maxSec, $timeout);
            }
        };

        $this->tempDir = sys_get_temp_dir().'/ai-video-data-test-'.uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tempDir.'/*') ?: []);
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    protected function binaryAvailable(string $binary): bool
    {
        try {
            $process = new Process([$binary, '-version']);
            $process->setTimeout(5);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    protected function requireFfmpeg(): void
    {
        if (! $this->binaryAvailable('ffmpeg')) {
            $this->markTestSkipped('ffmpeg is not reachable in this environment.');
        }
    }

    public function test_subtitle_streams_filters_by_codec_type(): void
    {
        $streams = [
            ['codec_type' => 'video'],
            ['codec_type' => 'subtitle'],
            ['codec_type' => 'audio'],
            ['codec_type' => 'subtitle'],
        ];

        $this->assertCount(2, $this->subject->callSubtitleStreams($streams));
    }

    public function test_select_primary_stream_prefers_the_default_disposition(): void
    {
        $streams = [
            ['index' => 0, 'disposition' => ['default' => 0]],
            ['index' => 1, 'disposition' => ['default' => 1]],
            ['index' => 2, 'disposition' => ['default' => 0]],
        ];

        $this->assertSame(1, $this->subject->callSelectPrimaryStream($streams)['index']);
    }

    public function test_select_primary_stream_falls_back_to_the_first_stream_deterministically(): void
    {
        $streams = [
            ['index' => 5, 'disposition' => ['default' => 0]],
            ['index' => 9, 'disposition' => ['default' => 0]],
        ];

        $this->assertSame(5, $this->subject->callSelectPrimaryStream($streams)['index']);
    }

    public function test_select_primary_stream_returns_null_for_an_empty_list(): void
    {
        $this->assertNull($this->subject->callSelectPrimaryStream([]));
    }

    public function test_parse_frame_rate_handles_a_real_fraction(): void
    {
        $this->assertSame(15.0, $this->subject->callParseFrameRate('15/1'));
        $this->assertSame(29.97, $this->subject->callParseFrameRate('30000/1001'));
    }

    public function test_parse_frame_rate_never_fabricates_an_unknown_rate(): void
    {
        $this->assertNull($this->subject->callParseFrameRate('0/0'));
        $this->assertNull($this->subject->callParseFrameRate(null));
        $this->assertNull($this->subject->callParseFrameRate('not-a-fraction'));
    }

    public function test_display_dimensions_uses_coded_size_when_pixel_aspect_ratio_is_square(): void
    {
        [$w, $h] = $this->subject->callDisplayDimensions(['display_aspect_ratio' => ''], 320, 240);
        $this->assertSame([320, 240], [$w, $h]);
    }

    public function test_display_dimensions_recomputes_width_for_a_non_square_pixel_aspect_ratio(): void
    {
        // Verified against real ffprobe output in this phase: setsar=2/1
        // on a 320x240 source reports display_aspect_ratio "8:3".
        [$w, $h] = $this->subject->callDisplayDimensions(['display_aspect_ratio' => '8:3'], 320, 240);
        $this->assertSame(640, $w);
        $this->assertSame(240, $h);
    }

    public function test_poster_timestamp_is_the_clamped_midpoint(): void
    {
        $this->assertSame(5.0, $this->subject->callPosterTimestamp(10.0));
        $this->assertSame(0.0, $this->subject->callPosterTimestamp(0.1));
    }

    public function test_poster_timestamp_never_reaches_or_passes_the_real_end_of_a_short_clip(): void
    {
        $this->assertLessThan(0.5, $this->subject->callPosterTimestamp(0.5));
    }

    public function test_capture_frame_produces_a_real_decodable_jpeg(): void
    {
        $this->requireFfmpeg();

        $source = $this->tempDir.'/src.mp4';
        $this->makeTestVideo($source, 2.0);

        $frame = $this->subject->callCaptureFrame('ffmpeg', $source, 0, 1.0, 480, 15);

        $this->assertNotNull($frame);
        $this->assertGreaterThan(0, strlen($frame['bytes']));
        $this->assertGreaterThan(0, $frame['width']);
        $this->assertGreaterThan(0, $frame['height']);
        $this->assertLessThanOrEqual(480, $frame['width']);
    }

    public function test_capture_frame_returns_null_for_a_binary_that_does_not_exist(): void
    {
        $source = $this->tempDir.'/src.mp4';
        touch($source);

        $this->assertNull($this->subject->callCaptureFrame('/no/such/ffmpeg-binary', $source, 0, 1.0, 480, 5));
    }

    public function test_extract_audio_to_temp_file_produces_a_real_wav_and_never_touches_the_original(): void
    {
        $this->requireFfmpeg();

        $source = $this->tempDir.'/src_with_audio.mp4';
        $this->makeTestVideo($source, 2.0, withAudio: true);
        $originalBytes = file_get_contents($source);

        $audioPath = $this->subject->callExtractAudioToTempFile('ffmpeg', $source, 0, 2.0, 60, 15);

        $this->assertNotNull($audioPath);
        $this->assertFileExists($audioPath);
        $this->assertGreaterThan(44, filesize($audioPath)); // more than just a WAV header
        $this->assertSame($originalBytes, file_get_contents($source));

        @unlink($audioPath);
    }

    public function test_extract_audio_to_temp_file_returns_null_when_there_is_no_audio_stream(): void
    {
        $this->requireFfmpeg();

        $source = $this->tempDir.'/src_no_audio.mp4';
        $this->makeTestVideo($source, 1.0, withAudio: false);

        $this->assertNull($this->subject->callExtractAudioToTempFile('ffmpeg', $source, 0, 1.0, 60, 15));
    }

    protected function makeTestVideo(string $path, float $duration, bool $withAudio = false): void
    {
        $args = ['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', "testsrc=size=320x240:rate=15:duration={$duration}"];

        if ($withAudio) {
            $args = [...$args, '-f', 'lavfi', '-i', "sine=frequency=440:duration={$duration}",
                '-c:v', 'libx264', '-c:a', 'aac', '-shortest'];
        } else {
            $args = [...$args, '-c:v', 'libx264', '-an'];
        }

        $args[] = $path;

        $process = new Process($args);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($path)) {
            $this->fail('Failed to generate a real test video fixture with ffmpeg: '.$process->getErrorOutput());
        }
    }
}
