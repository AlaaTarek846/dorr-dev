<?php

namespace Modules\AI\Tests\Unit\FileProcessors;

use Illuminate\Support\Facades\Config;
use Modules\AI\Services\FileProcessors\AudioFileProcessor;
use Modules\AI\Services\FileProcessors\VideoFileProcessor;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Extends the Laravel-booted Tests\TestCase - VideoFileProcessor reads
 * several `ai.files.video_*` config keys, same reasoning as
 * AudioFileProcessorTest. Every fixture is a REAL video built with a
 * real ffmpeg at test-run time (gated by requireFfmpeg()/requireFfprobe()),
 * mirroring AudioFileProcessorTest's own "test the real external tool,
 * skip honestly if absent" approach - every exact field/behavior these
 * tests assert on (real stream JSON shape, MOV/MP4 sharing one
 * `format_name`, a corrupted file's ffprobe behavior, a real decodable
 * JPEG poster) was independently verified against a real
 * ffmpeg/ffprobe 4.4.2 in this session's device-bridge shell before
 * this processor was written - see this phase's Final Report.
 */
class VideoFileProcessorTest extends TestCase
{
    protected VideoFileProcessor $processor;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new VideoFileProcessor(new AudioFileProcessor);
        $this->tempDir = sys_get_temp_dir().'/ai-video-processor-test-'.uniqid();
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

    protected function requireFfprobe(): void
    {
        if (! $this->binaryAvailable('ffprobe')) {
            $this->markTestSkipped('ffprobe is not reachable in this environment.');
        }
    }

    protected function makeVideo(string $extension, array $videoCodecArgs, bool $withAudio, float $duration = 1.0): string
    {
        $path = $this->tempDir.'/test-'.uniqid().'.'.$extension;
        $args = ['ffmpeg', '-hide_banner', '-loglevel', 'error',
            '-f', 'lavfi', '-i', "testsrc=size=320x240:rate=15:duration={$duration}"];

        if ($withAudio) {
            $args = [...$args, '-f', 'lavfi', '-i', "sine=frequency=440:duration={$duration}", ...$videoCodecArgs, '-c:a', 'aac', '-shortest'];
        } else {
            $args = [...$args, ...$videoCodecArgs, '-an'];
        }

        $args = [...$args, $path, '-y'];

        $process = new Process($args);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($path)) {
            $this->fail('Failed to generate a real test video fixture with ffmpeg: '.$process->getErrorOutput());
        }

        return $path;
    }

    protected function makeMp4(bool $withAudio = true, float $duration = 1.0): string
    {
        return $this->makeVideo('mp4', ['-c:v', 'libx264'], $withAudio, $duration);
    }

    public function test_supports_the_documented_video_mime_types(): void
    {
        $this->assertTrue($this->processor->supports('video/mp4'));
        $this->assertTrue($this->processor->supports('video/quicktime'));
        $this->assertTrue($this->processor->supports('video/webm'));
        $this->assertTrue($this->processor->supports('video/x-msvideo'));
        $this->assertFalse($this->processor->supports('audio/mpeg'));
        $this->assertFalse($this->processor->supports('application/pdf'));
    }

    public function test_without_ffprobe_reachable_it_fails_honestly_instead_of_crashing(): void
    {
        Config::set('ai.files.video_ffprobe_path', '/this/binary/does/not/exist/ffprobe');

        $result = $this->processor->process('/irrelevant/path.mp4', 'video/mp4');

        $this->assertFalse($result->success);
        $this->assertSame('VIDEO_PROCESSOR_UNAVAILABLE', $result->error);
    }

    public function test_a_corrupted_file_fails_honestly(): void
    {
        $this->requireFfprobe();

        $path = $this->tempDir.'/fake.mp4';
        file_put_contents($path, 'this is not a real video at all');

        $result = $this->processor->process($path, 'video/mp4');

        $this->assertFalse($result->success);
        $this->assertSame('VIDEO_UNREADABLE', $result->error);
    }

    public function test_a_zero_byte_file_fails_honestly(): void
    {
        $this->requireFfprobe();

        $path = $this->tempDir.'/zero.mp4';
        touch($path);

        $result = $this->processor->process($path, 'video/mp4');

        $this->assertFalse($result->success);
        $this->assertSame('VIDEO_UNREADABLE', $result->error);
    }

    public function test_extracts_real_dimensions_duration_codec_and_frame_rate_from_an_mp4(): void
    {
        $this->requireFfmpeg();
        $this->requireFfprobe();

        $path = $this->makeMp4(withAudio: true, duration: 2.0);

        $result = $this->processor->process($path, 'video/mp4');

        $this->assertTrue($result->success);
        $this->assertSame('video', $result->documentType);
        $this->assertSame('mp4', $result->metadata['container']);
        $this->assertSame(320, $result->metadata['width']);
        $this->assertSame(240, $result->metadata['height']);
        $this->assertSame('h264', $result->metadata['video_codec']);
        $this->assertSame(15.0, $result->metadata['frame_rate']);
        $this->assertEqualsWithDelta(2.0, $result->metadata['duration_seconds'], 0.2);
        $this->assertTrue($result->metadata['audio']['available']);
        $this->assertSame('aac', $result->metadata['audio']['streams'][0]['codec']);
    }

    public function test_a_video_without_any_audio_stream_is_still_processed_successfully(): void
    {
        $this->requireFfmpeg();
        $this->requireFfprobe();

        $path = $this->makeMp4(withAudio: false, duration: 1.0);

        $result = $this->processor->process($path, 'video/mp4');

        $this->assertTrue($result->success);
        $this->assertFalse($result->metadata['audio']['available']);
        $this->assertSame([], $result->metadata['audio']['streams']);
    }

    public function test_mov_and_mp4_share_ffprobes_format_name_but_are_labeled_from_the_real_mime(): void
    {
        // Verified in this phase: MOV and MP4 both report ffprobe
        // format.format_name "mov,mp4,m4a,3gp,3g2,mj2" - the container
        // label must come from the real MIME, never that string.
        $this->requireFfmpeg();
        $this->requireFfprobe();

        $movPath = $this->makeVideo('mov', ['-c:v', 'libx264'], withAudio: false, duration: 1.0);

        $result = $this->processor->process($movPath, 'video/quicktime');

        $this->assertTrue($result->success);
        $this->assertSame('mov', $result->metadata['container']);
    }

    public function test_an_avi_file_is_processed_successfully(): void
    {
        $this->requireFfmpeg();
        $this->requireFfprobe();

        $path = $this->makeVideo('avi', ['-c:v', 'mpeg4'], withAudio: false, duration: 1.0);

        $result = $this->processor->process($path, 'video/x-msvideo');

        $this->assertTrue($result->success);
        $this->assertSame('avi', $result->metadata['container']);
    }

    public function test_a_video_stream_whose_only_video_is_attached_cover_art_is_not_treated_as_real_video(): void
    {
        $this->requireFfmpeg();
        $this->requireFfprobe();

        // Build an MP4 with ONLY an audio stream, then attach a cover
        // image the same way AudioFileProcessorTest's own cover-art test
        // does - the result must go through the audio-delegation branch,
        // not be treated as a real video.
        $audioOnly = $this->tempDir.'/audio-only.mp4';
        (new Process(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1', '-c:a', 'aac', $audioOnly, '-y']))
            ->setTimeout(15)->mustRun();
        $coverPath = $this->tempDir.'/cover.png';
        (new Process(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', 'color=c=blue:s=16x16:d=1', '-frames:v', '1', $coverPath, '-y']))
            ->setTimeout(15)->mustRun();
        $withCover = $this->tempDir.'/with-cover.mp4';
        (new Process(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-i', $audioOnly, '-i', $coverPath, '-map', '0:0', '-map', '1:0', '-c:a', 'copy', '-c:v', 'mjpeg', '-disposition:v:0', 'attached_pic', $withCover, '-y']))
            ->setTimeout(15)->mustRun();

        $result = $this->processor->process($withCover, 'video/mp4');

        $this->assertTrue($result->success);
        $this->assertSame('audio', $result->documentType);
    }

    public function test_an_audio_only_webm_delegates_to_the_real_audio_processor(): void
    {
        $this->requireFfmpeg();
        $this->requireFfprobe();

        $path = $this->tempDir.'/audio-only.webm';
        (new Process(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1', '-c:a', 'libopus', $path, '-y']))
            ->setTimeout(15)->mustRun();

        $result = $this->processor->process($path, 'video/webm');

        $this->assertTrue($result->success);
        $this->assertSame('audio', $result->documentType);
        $this->assertSame('opus', $result->metadata['codec']);
    }

    public function test_a_genuinely_real_video_webm_is_processed_as_video_not_delegated(): void
    {
        $this->requireFfmpeg();
        $this->requireFfprobe();

        $path = $this->tempDir.'/real-video.webm';
        (new Process([
            'ffmpeg', '-hide_banner', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'testsrc=duration=1:size=32x32:rate=5',
            '-c:v', 'libvpx',
            $path, '-y',
        ]))->setTimeout(30)->mustRun();

        $result = $this->processor->process($path, 'video/webm');

        $this->assertTrue($result->success);
        $this->assertSame('video', $result->documentType);
        $this->assertSame('webm', $result->metadata['container']);
    }

    public function test_duration_over_the_configured_limit_is_rejected(): void
    {
        $this->requireFfmpeg();
        $this->requireFfprobe();

        Config::set('ai.files.video_max_duration_seconds', 1);
        $path = $this->makeMp4(withAudio: false, duration: 3.0);

        $result = $this->processor->process($path, 'video/mp4');

        $this->assertFalse($result->success);
        $this->assertSame('VIDEO_TOO_LONG', $result->error);
    }

    public function test_dimensions_over_the_configured_limit_are_rejected(): void
    {
        $this->requireFfmpeg();
        $this->requireFfprobe();

        Config::set('ai.files.video_max_width', 100);
        $path = $this->makeMp4(withAudio: false, duration: 1.0); // generated at 320x240

        $result = $this->processor->process($path, 'video/mp4');

        $this->assertFalse($result->success);
        $this->assertSame('VIDEO_DIMENSIONS_TOO_LARGE', $result->error);
    }

    public function test_a_poster_frame_is_generated_and_shaped_like_an_image_preview_asset(): void
    {
        $this->requireFfmpeg();
        $this->requireFfprobe();

        $path = $this->makeMp4(withAudio: false, duration: 2.0);

        $result = $this->processor->process($path, 'video/mp4');

        $this->assertTrue($result->success);
        $this->assertArrayHasKey('preview_assets', $result->metadata);
        $poster = $result->metadata['preview_assets']['poster'];
        $this->assertSame('jpg', $poster['extension']);
        $this->assertGreaterThan(0, strlen($poster['bytes']));
        $this->assertGreaterThan(0, $poster['width']);
    }

    public function test_without_ffmpeg_reachable_the_poster_degrades_to_a_warning_not_a_failure(): void
    {
        $this->requireFfprobe();

        Config::set('ai.files.video_ffmpeg_path', '/this/binary/does/not/exist/ffmpeg');
        $this->requireFfmpeg(); // skip if there genuinely is no ffmpeg at all to build the source fixture with
        Config::set('ai.files.video_ffprobe_path', 'ffprobe');

        $path = $this->makeVideo('mp4', ['-c:v', 'libx264'], withAudio: false, duration: 1.0);
        Config::set('ai.files.video_ffmpeg_path', '/this/binary/does/not/exist/ffmpeg');

        $result = $this->processor->process($path, 'video/mp4');

        $this->assertTrue($result->success);
        $this->assertArrayNotHasKey('preview_assets', $result->metadata);
        $this->assertContains('VIDEO_PREVIEW_UNAVAILABLE', $result->warnings);
    }

    public function test_unsupported_mime_type_fails_without_touching_ffprobe_at_all(): void
    {
        $result = $this->processor->process('/irrelevant/path.avi', 'video/x-flv');

        $this->assertFalse($result->success);
        $this->assertSame('unsupported_mime_type', $result->error);
    }
}
