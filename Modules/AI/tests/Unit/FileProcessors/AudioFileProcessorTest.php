<?php

namespace Modules\AI\Tests\Unit\FileProcessors;

use Illuminate\Support\Facades\Config;
use Modules\AI\Services\FileProcessors\AudioFileProcessor;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Extends the Laravel-booted Tests\TestCase because
 * AudioFileProcessor::process() reads several `ai.files.audio_*` config
 * keys - same reasoning as RasterImageFileProcessorTest.
 *
 * Every test that needs a real decodable audio file builds one with a
 * REAL ffmpeg at test-run time (gated by requireFfmpeg()/requireFfprobe())
 * rather than shipping a binary fixture - this is the same "test against
 * the real external tool, mark honestly unavailable otherwise" approach
 * DockerSandboxDriverTest already established for `docker`. Every exact
 * field/behavior these tests assert on (ffprobe's JSON shape, the
 * missing `channel_layout` on mono audio, attached-cover-art-as-video-
 * stream, `format.tags.date`) was independently verified against a real
 * ffmpeg/ffprobe 4.4.2 in this session's device-bridge shell before this
 * processor was written - see this phase's Final Report.
 */
class AudioFileProcessorTest extends TestCase
{
    protected AudioFileProcessor $processor;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new AudioFileProcessor;
        $this->tempDir = sys_get_temp_dir().'/ai-audio-processor-test-'.uniqid();
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

    /**
     * @param  list<string>  $extraArgs
     */
    protected function makeAudio(string $extension, array $extraArgs, float $duration = 1.0): string
    {
        $path = $this->tempDir.'/test-'.uniqid().'.'.$extension;

        $process = new Process(array_merge(
            ['ffmpeg', '-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', "sine=frequency=440:duration={$duration}"],
            $extraArgs,
            [$path, '-y'],
        ));
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->fail('Failed to generate test fixture with ffmpeg: '.$process->getErrorOutput());
        }

        return $path;
    }

    protected function makeMp3(float $duration = 1.0): string
    {
        return $this->makeAudio('mp3', ['-ar', '44100', '-ac', '2', '-b:a', '128k'], $duration);
    }

    protected function makeWavMono(float $duration = 1.0): string
    {
        return $this->makeAudio('wav', ['-ar', '44100', '-ac', '1'], $duration);
    }

    public function test_supports_the_documented_audio_mime_types(): void
    {
        $this->assertTrue($this->processor->supports('audio/mpeg'));
        $this->assertTrue($this->processor->supports('audio/wav'));
        $this->assertTrue($this->processor->supports('audio/x-wav'));
        $this->assertTrue($this->processor->supports('audio/mp4'));
        $this->assertTrue($this->processor->supports('audio/aac'));
        $this->assertTrue($this->processor->supports('audio/ogg'));
        $this->assertTrue($this->processor->supports('audio/flac'));
        $this->assertTrue($this->processor->supports('audio/webm'));
        // Doc S2 finding: audio-only WebM/Opus genuinely reports as
        // video/webm from real MIME detection - see this processor's own
        // docblock.
        $this->assertTrue($this->processor->supports('video/webm'));
        $this->assertFalse($this->processor->supports('video/mp4'));
        $this->assertFalse($this->processor->supports('application/pdf'));
    }

    public function test_without_ffprobe_reachable_it_fails_honestly_instead_of_crashing(): void
    {
        Config::set('ai.files.audio_ffprobe_path', '/this/binary/does/not/exist/ffprobe');

        $result = $this->processor->process('/irrelevant/path.mp3', 'audio/mpeg');

        $this->assertFalse($result->success);
        $this->assertSame('AUDIO_PROCESSOR_UNAVAILABLE', $result->error);
    }

    public function test_a_file_that_is_not_really_audio_fails_honestly(): void
    {
        $this->requireFfprobe();

        $path = $this->tempDir.'/fake.mp3';
        file_put_contents($path, 'this is just plain text, not an mp3 at all');

        $result = $this->processor->process($path, 'audio/mpeg');

        $this->assertFalse($result->success);
        $this->assertSame('AUDIO_INVALID', $result->error);
    }

    public function test_extracts_real_duration_sample_rate_channels_and_codec_from_an_mp3(): void
    {
        $this->requireFfmpeg();
        $this->requireFfprobe();

        $path = $this->makeMp3(2.0);

        $result = $this->processor->process($path, 'audio/mpeg');

        $this->assertTrue($result->success);
        $this->assertSame('audio', $result->documentType);
        $this->assertSame('mp3', $result->metadata['format']);
        $this->assertSame('mp3', $result->metadata['codec']);
        $this->assertSame(44100, $result->metadata['sample_rate']);
        $this->assertSame(2, $result->metadata['channels']);
        $this->assertEqualsWithDelta(2.0, $result->metadata['duration_seconds'], 0.2);
        $this->assertNotNull($result->metadata['bitrate']);
    }

    public function test_a_mono_wav_has_no_channel_layout_key_and_does_not_crash(): void
    {
        // Verified against real ffprobe output: a mono WAV's stream JSON
        // has no `channel_layout` key at all (not an empty string) -
        // this must not throw, and must report null, not fabricate one.
        $this->requireFfmpeg();
        $this->requireFfprobe();

        $path = $this->makeWavMono(1.0);

        $result = $this->processor->process($path, 'audio/wav');

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->metadata['channels']);
        $this->assertNull($result->metadata['channel_layout']);
    }

    public function test_duration_over_the_configured_limit_is_rejected(): void
    {
        $this->requireFfmpeg();
        $this->requireFfprobe();

        Config::set('ai.files.audio_max_duration_seconds', 1);
        $path = $this->makeMp3(3.0);

        $result = $this->processor->process($path, 'audio/mpeg');

        $this->assertFalse($result->success);
        $this->assertSame('AUDIO_DURATION_TOO_LONG', $result->error);
    }

    public function test_sample_rate_over_the_configured_limit_is_rejected(): void
    {
        $this->requireFfmpeg();
        $this->requireFfprobe();

        Config::set('ai.files.audio_max_sample_rate', 22050);
        $path = $this->makeMp3(1.0);

        $result = $this->processor->process($path, 'audio/mpeg');

        $this->assertFalse($result->success);
        $this->assertSame('AUDIO_SAMPLE_RATE_TOO_HIGH', $result->error);
    }

    public function test_channel_count_over_the_configured_limit_is_rejected(): void
    {
        $this->requireFfmpeg();
        $this->requireFfprobe();

        Config::set('ai.files.audio_max_channels', 1);
        $path = $this->makeMp3(1.0); // generated as stereo (2 channels)

        $result = $this->processor->process($path, 'audio/mpeg');

        $this->assertFalse($result->success);
        $this->assertSame('AUDIO_CHANNEL_LIMIT_EXCEEDED', $result->error);
    }

    public function test_embedded_cover_art_does_not_make_the_file_look_like_video(): void
    {
        $this->requireFfmpeg();
        $this->requireFfprobe();

        $audioPath = $this->makeMp3(1.0);
        $coverPath = $this->tempDir.'/cover.png';
        $withCoverPath = $this->tempDir.'/with-cover.mp3';

        (new Process(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', 'color=c=blue:s=16x16:d=1', '-frames:v', '1', $coverPath, '-y']))
            ->setTimeout(15)->mustRun();
        (new Process(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-i', $audioPath, '-i', $coverPath, '-map', '0:0', '-map', '1:0', '-c', 'copy', '-id3v2_version', '3', $withCoverPath, '-y']))
            ->setTimeout(15)->mustRun();

        $result = $this->processor->process($withCoverPath, 'audio/mpeg');

        $this->assertTrue($result->success);
        $this->assertSame('mp3', $result->metadata['format']);
    }

    public function test_a_webm_file_with_a_genuine_video_stream_is_rejected_as_not_audio(): void
    {
        $this->requireFfmpeg();
        $this->requireFfprobe();

        $path = $this->tempDir.'/real-video.webm';
        $process = new Process([
            'ffmpeg', '-hide_banner', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'testsrc=duration=1:size=32x32:rate=5',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1',
            '-c:v', 'libvpx', '-c:a', 'libopus',
            $path, '-y',
        ]);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->markTestSkipped('This ffmpeg build could not encode a real video+audio WebM fixture: '.$process->getErrorOutput());
        }

        $result = $this->processor->process($path, 'video/webm');

        $this->assertFalse($result->success);
        $this->assertSame('AUDIO_CONTAINER_HAS_VIDEO', $result->error);
    }

    public function test_an_audio_only_webm_file_is_accepted_and_processed_as_audio(): void
    {
        // Doc S2's real finding: an audio-only WebM/Opus file reports
        // MIME video/webm from real detection - this proves it is still
        // accepted and correctly processed as audio, not rejected.
        $this->requireFfmpeg();
        $this->requireFfprobe();

        $path = $this->tempDir.'/audio-only.webm';
        $process = new Process([
            'ffmpeg', '-hide_banner', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1',
            '-c:a', 'libopus', $path, '-y',
        ]);
        $process->setTimeout(15);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->markTestSkipped('This ffmpeg build could not encode an Opus/WebM fixture.');
        }

        $result = $this->processor->process($path, 'video/webm');

        $this->assertTrue($result->success);
        $this->assertSame('opus', $result->metadata['codec']);
    }

    public function test_tags_are_extracted_and_categorized(): void
    {
        $this->requireFfmpeg();
        $this->requireFfprobe();

        $path = $this->tempDir.'/tagged.mp3';
        $process = new Process([
            'ffmpeg', '-hide_banner', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1',
            '-metadata', 'title=My Voice Memo',
            '-metadata', 'language=ar',
            $path, '-y',
        ]);
        $process->setTimeout(15)->mustRun();

        $result = $this->processor->process($path, 'audio/mpeg');

        $this->assertTrue($result->success);
        $this->assertSame('My Voice Memo', $result->metadata['tags']['sensitive']['title']);
        $this->assertSame('ar', $result->metadata['tags']['ai_safe']['language']);
    }

    public function test_generates_a_bounded_deterministic_waveform(): void
    {
        $this->requireFfmpeg();
        $this->requireFfprobe();

        Config::set('ai.files.audio_waveform_bars', 20);
        $path = $this->makeMp3(2.0);

        $result = $this->processor->process($path, 'audio/mpeg');

        $this->assertTrue($result->success);
        $this->assertArrayHasKey('waveform', $result->metadata);
        $this->assertCount(20, $result->metadata['waveform']['bars']);

        foreach ($result->metadata['waveform']['bars'] as $bar) {
            $this->assertGreaterThanOrEqual(0.0, $bar);
            $this->assertLessThanOrEqual(1.0, $bar);
        }

        // Doc S12: a real 440Hz sine tone is not silent - at least one
        // bar must show real signal, never an all-zero fabricated line.
        $this->assertGreaterThan(0.0, max($result->metadata['waveform']['bars']));
    }

    public function test_waveform_can_be_disabled_via_config(): void
    {
        $this->requireFfmpeg();
        $this->requireFfprobe();

        Config::set('ai.files.audio_waveform_enabled', false);
        $path = $this->makeMp3(1.0);

        $result = $this->processor->process($path, 'audio/mpeg');

        $this->assertTrue($result->success);
        $this->assertArrayNotHasKey('waveform', $result->metadata);
        $this->assertNotContains('AUDIO_WAVEFORM_UNAVAILABLE', $result->warnings);
    }

    public function test_the_original_file_is_never_modified_by_processing(): void
    {
        $this->requireFfmpeg();
        $this->requireFfprobe();

        $path = $this->makeMp3(1.0);
        $original = file_get_contents($path);

        $this->processor->process($path, 'audio/mpeg');

        $this->assertSame($original, file_get_contents($path));
    }
}
