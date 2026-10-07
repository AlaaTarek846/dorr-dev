<?php

namespace Modules\AI\Services\FileProcessors;

use Modules\AI\Services\FileProcessors\Concerns\AnalyzesVideoData;

/**
 * Phase 7 (doc S15/S16/S17): an extensible, bounded frame-extraction
 * service, deliberately kept SEPARATE from VideoFileProcessor rather
 * than folded into it - VideoFileProcessor::process() runs
 * automatically for every upload (doc S8: "the processor should NOT
 * automatically execute every expensive operation"), while multi-frame
 * extraction is a materially more expensive, on-demand operation this
 * phase does not wire into automatic processing at all (same
 * "implemented and tested, not auto-invoked" stance as
 * AnalyzesVideoData::extractAudioToTempFile() - see its own docblock).
 * A future AI-request-driven caller (doc S39: "إيه اللي حصل في
 * الفيديو؟" needing `video_frames`) is expected to call this directly.
 *
 * Every frame capture reuses AnalyzesVideoData::captureFrame() (shared
 * with VideoFileProcessor's own poster generation) - no second ffmpeg-
 * invocation implementation.
 */
class VideoFrameExtractor
{
    use AnalyzesVideoData;

    public const STRATEGY_FIRST_FRAME = 'first_frame';

    public const STRATEGY_MIDDLE_FRAME = 'middle_frame';

    public const STRATEGY_LAST_FRAME = 'last_frame';

    public const STRATEGY_UNIFORM_INTERVAL = 'uniform_interval';

    public const STRATEGIES = [
        self::STRATEGY_FIRST_FRAME,
        self::STRATEGY_MIDDLE_FRAME,
        self::STRATEGY_LAST_FRAME,
        self::STRATEGY_UNIFORM_INTERVAL,
    ];

    /**
     * Doc S17: every call is bounded by $maxFrames - "never allow a
     * request to extract thousands of frames unintentionally" - and
     * each individual ffmpeg capture has its own wall-clock timeout, so
     * a pathological/adversarial file can only ever cost
     * `$maxFrames * $timeoutSeconds` at the very worst, never unbounded
     * time.
     *
     * @return list<array{bytes: string, width: int, height: int, timestamp_seconds: float}>
     */
    public function extract(
        string $ffmpegBinary,
        string $absolutePath,
        int $videoStreamIndex,
        float $durationSeconds,
        string $strategy,
        int $maxFrames,
        int $maxDimension,
        int $timeoutSeconds,
    ): array {
        if (! in_array($strategy, self::STRATEGIES, true) || $maxFrames < 1 || $durationSeconds <= 0) {
            return [];
        }

        $timestamps = $this->timestampsFor($strategy, $durationSeconds, $maxFrames);
        $frames = [];

        foreach ($timestamps as $timestamp) {
            $frame = $this->captureFrame($ffmpegBinary, $absolutePath, $videoStreamIndex, $timestamp, $maxDimension, $timeoutSeconds);

            if ($frame !== null) {
                $frames[] = [...$frame, 'timestamp_seconds' => round($timestamp, 2)];
            }

            // Doc S17's bound is on frames actually captured too - a
            // string of decode failures must never turn into silently
            // trying every remaining candidate timestamp one by one.
            if (count($frames) >= $maxFrames) {
                break;
            }
        }

        return $frames;
    }

    /**
     * Doc S16: a deterministic distribution per strategy - same input,
     * same timestamps, every time. UNIFORM_INTERVAL spaces frames
     * evenly across the clip (including both near-start and near-end,
     * matching the master prompt's own worked example of a 10-minute
     * clip at ~54s intervals for 12 frames) rather than naively
     * dividing by $maxFrames and leaving a gap at the very end.
     *
     * SCENE_SAMPLING_READY (doc S16) is intentionally NOT implemented as
     * a real strategy - true scene-change detection is a materially
     * larger, unverified feature this phase does not claim; only the
     * four strategies this phase actually built and verified are
     * listed in self::STRATEGIES.
     *
     * @return list<float>
     */
    protected function timestampsFor(string $strategy, float $durationSeconds, int $maxFrames): array
    {
        $safeDuration = max(0.0, $durationSeconds - 0.05);

        return match ($strategy) {
            self::STRATEGY_FIRST_FRAME => [0.0],
            self::STRATEGY_MIDDLE_FRAME => [round($safeDuration / 2, 3)],
            self::STRATEGY_LAST_FRAME => [$safeDuration],
            self::STRATEGY_UNIFORM_INTERVAL => $this->uniformTimestamps($safeDuration, $maxFrames),
        };
    }

    /**
     * @return list<float>
     */
    protected function uniformTimestamps(float $safeDuration, int $maxFrames): array
    {
        if ($maxFrames === 1) {
            return [round($safeDuration / 2, 3)];
        }

        $timestamps = [];

        for ($i = 0; $i < $maxFrames; $i++) {
            $timestamps[] = round(($safeDuration / ($maxFrames - 1)) * $i, 3);
        }

        return $timestamps;
    }
}
