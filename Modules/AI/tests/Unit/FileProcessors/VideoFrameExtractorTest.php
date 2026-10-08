<?php

namespace Modules\AI\Tests\Unit\FileProcessors;

use Modules\AI\Services\FileProcessors\VideoFrameExtractor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Plain PHPUnit\Framework\TestCase - VideoFrameExtractor reads no
 * `config()` values itself (its caller is responsible for passing
 * already-resolved limits - see VideoFileProcessor's own docblock on
 * why this service is never auto-invoked from the processor).
 */
class VideoFrameExtractorTest extends TestCase
{
    protected VideoFrameExtractor $extractor;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new VideoFrameExtractor;
        $this->tempDir = sys_get_temp_dir().'/ai-frame-extractor-test-'.uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tempDir.'/*') ?: []);
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    protected function requireFfmpeg(): void
    {
        try {
            $process = new Process(['ffmpeg', '-version']);
            $process->setTimeout(5);
            $process->run();

            if ($process->isSuccessful()) {
                return;
            }
        } catch (\Throwable) {
        }

        $this->markTestSkipped('ffmpeg is not reachable in this environment.');
    }

    protected function makeVideo(float $duration): string
    {
        $path = $this->tempDir.'/src-'.uniqid().'.mp4';
        $process = new Process([
            'ffmpeg', '-hide_banner', '-loglevel', 'error',
            '-f', 'lavfi', '-i', "testsrc=size=320x240:rate=15:duration={$duration}",
            '-c:v', 'libx264', '-an', $path, '-y',
        ]);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->fail('Failed to generate a real test video fixture: '.$process->getErrorOutput());
        }

        return $path;
    }

    public function test_an_unknown_strategy_returns_no_frames(): void
    {
        $this->assertSame([], $this->extractor->extract('ffmpeg', '/irrelevant.mp4', 0, 10.0, 'not_a_real_strategy', 5, 480, 10));
    }

    public function test_zero_duration_returns_no_frames(): void
    {
        $this->assertSame([], $this->extractor->extract('ffmpeg', '/irrelevant.mp4', 0, 0.0, VideoFrameExtractor::STRATEGY_FIRST_FRAME, 5, 480, 10));
    }

    public function test_first_frame_strategy_returns_exactly_one_real_frame(): void
    {
        $this->requireFfmpeg();
        $path = $this->makeVideo(3.0);

        $frames = $this->extractor->extract('ffmpeg', $path, 0, 3.0, VideoFrameExtractor::STRATEGY_FIRST_FRAME, 5, 320, 15);

        $this->assertCount(1, $frames);
        $this->assertSame(0.0, $frames[0]['timestamp_seconds']);
        $this->assertGreaterThan(0, strlen($frames[0]['bytes']));
    }

    public function test_uniform_interval_strategy_is_bounded_by_max_frames_and_deterministic(): void
    {
        $this->requireFfmpeg();
        $path = $this->makeVideo(4.0);

        $frames = $this->extractor->extract('ffmpeg', $path, 0, 4.0, VideoFrameExtractor::STRATEGY_UNIFORM_INTERVAL, 4, 320, 15);
        $frames2 = $this->extractor->extract('ffmpeg', $path, 0, 4.0, VideoFrameExtractor::STRATEGY_UNIFORM_INTERVAL, 4, 320, 15);

        $this->assertLessThanOrEqual(4, count($frames));
        $this->assertGreaterThan(0, count($frames));

        $timestamps1 = array_column($frames, 'timestamp_seconds');
        $timestamps2 = array_column($frames2, 'timestamp_seconds');
        $this->assertSame($timestamps1, $timestamps2, 'Same input must always produce the same timestamps.');

        // Spans near-start to near-end, not bunched at the beginning.
        $this->assertLessThan(0.5, $timestamps1[0]);
        $this->assertGreaterThan(2.5, end($timestamps1));
    }

    public function test_middle_and_last_frame_strategies_each_return_one_frame_near_the_expected_point(): void
    {
        $this->requireFfmpeg();
        $path = $this->makeVideo(4.0);

        $middle = $this->extractor->extract('ffmpeg', $path, 0, 4.0, VideoFrameExtractor::STRATEGY_MIDDLE_FRAME, 5, 320, 15);
        $last = $this->extractor->extract('ffmpeg', $path, 0, 4.0, VideoFrameExtractor::STRATEGY_LAST_FRAME, 5, 320, 15);

        $this->assertCount(1, $middle);
        $this->assertEqualsWithDelta(2.0, $middle[0]['timestamp_seconds'], 0.2);

        $this->assertCount(1, $last);
        $this->assertGreaterThan(3.0, $last[0]['timestamp_seconds']);
    }

    public function test_capture_failures_never_exceed_max_frames_worth_of_attempts_silently(): void
    {
        // A nonexistent ffmpeg binary means every capture attempt fails -
        // the result must simply be an empty list, never a thrown error.
        $frames = $this->extractor->extract('/no/such/ffmpeg', '/irrelevant.mp4', 0, 10.0, VideoFrameExtractor::STRATEGY_UNIFORM_INTERVAL, 5, 320, 5);

        $this->assertSame([], $frames);
    }
}
