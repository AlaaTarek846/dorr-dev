<?php

namespace Modules\AI\Services\FileProcessors\Concerns;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Phase 7: shared ffprobe/ffmpeg plumbing for video, on top of
 * AnalyzesAudioData (`checkBinaryAvailable()`, `probeAudio()` - a
 * generic `-show_format -show_streams` JSON probe despite its name,
 * `audioStreams()`, `realVideoStreams()`, `categorizeAudioTags()` are
 * all stream-shape-generic, not audio-specific, and are reused here
 * verbatim rather than re-implemented - doc architectural rule
 * "reuse the existing Phase 6 implementation if it already solved
 * this").
 *
 * Verified empirically against a real ffmpeg/ffprobe 4.4.2 in this
 * session's device-bridge shell before any of this was written (see
 * this phase's Final Report for the exact commands/output): MOV and
 * MP4 share the identical ffprobe `format.format_name`
 * ("mov,mp4,m4a,3gp,3g2,mj2") - container labelling here uses the real,
 * finfo-detected MIME type, never `format_name`, for exactly that
 * reason; `display_aspect_ratio` is only present when the pixel aspect
 * ratio is non-square; a corrupted or zero-byte file fed to ffprobe
 * behaves identically to Phase 6's audio finding (exit code 1, stdout
 * literally `{}`); extracting a single JPEG frame via
 * `-f mjpeg -` to stdout works and produces real, decodable JPEG bytes.
 */
trait AnalyzesVideoData
{
    use AnalyzesAudioData;

    /**
     * @param  list<array<string, mixed>>  $streams
     * @return list<array<string, mixed>>
     */
    protected function subtitleStreams(array $streams): array
    {
        return array_values(array_filter($streams, fn (array $s) => ($s['codec_type'] ?? null) === 'subtitle'));
    }

    /**
     * Doc S13/S14 ("if a default ... stream exists, prefer it;
     * otherwise use a deterministic selection strategy") - the
     * deterministic fallback is simply "the first stream of that type
     * ffprobe reported", which is itself deterministic (ffprobe always
     * lists streams in the same order for the same file).
     *
     * @param  list<array<string, mixed>>  $streams
     * @return array<string, mixed>|null
     */
    protected function selectPrimaryStream(array $streams): ?array
    {
        if ($streams === []) {
            return null;
        }

        foreach ($streams as $stream) {
            if ((int) ($stream['disposition']['default'] ?? 0) === 1) {
                return $stream;
            }
        }

        return $streams[0];
    }

    /**
     * ffprobe reports frame rates as a fraction string ("15/1", "30000/1001",
     * or occasionally the honestly-unknown "0/0") - never fabricated past
     * that: a genuinely undetectable rate (0/0, or a malformed string)
     * returns null rather than 0 or a guessed default.
     */
    protected function parseFrameRate(?string $rate): ?float
    {
        if ($rate === null || ! str_contains($rate, '/')) {
            return null;
        }

        [$num, $den] = array_map('floatval', explode('/', $rate, 2));

        if ($den <= 0.0) {
            return null;
        }

        return round($num / $den, 3);
    }

    /**
     * Doc S5 ("display width/height"): only computed when ffprobe itself
     * reports a non-square `display_aspect_ratio` (verified: entirely
     * absent when the pixel aspect ratio is 1:1) - the coded
     * width/height is returned unchanged otherwise, never a fabricated
     * recalculation.
     *
     * @return array{0: int, 1: int}
     */
    protected function displayDimensions(array $videoStream, int $width, int $height): array
    {
        $dar = (string) ($videoStream['display_aspect_ratio'] ?? '');

        if ($dar === '' || ! str_contains($dar, ':') || $height <= 0) {
            return [$width, $height];
        }

        [$darW, $darH] = array_map('floatval', explode(':', $dar, 2));

        if ($darW <= 0.0 || $darH <= 0.0) {
            return [$width, $height];
        }

        $displayWidth = (int) round($height * ($darW / $darH));

        return [$displayWidth > 0 ? $displayWidth : $width, $height];
    }

    /**
     * Doc S20 ("avoid a black/blank/technical-slate frame if a better
     * deterministic frame is available without excessive processing") -
     * deliberately NOT a scene-detection algorithm (doc S19 explicitly
     * rules that out): a fixed, cheap heuristic - the real midpoint of
     * the video, which for virtually any genuine recording is far more
     * likely to show real content than frame zero (often a fade-in or a
     * black starting frame), clamped so a very short clip never asks for
     * a timestamp at or past its own end.
     */
    protected function posterTimestamp(float $durationSeconds): float
    {
        if ($durationSeconds <= 0.2) {
            return 0.0;
        }

        return min($durationSeconds / 2, max(0.0, $durationSeconds - 0.1));
    }

    /**
     * Single-frame capture shared by the processor's poster and
     * VideoFrameExtractor's multi-frame strategies - one real ffmpeg
     * invocation (array-form argv, never a shell string - doc
     * S27/S28), decoded back with `getimagesize()` on the actual bytes
     * (same "measure, never trust the requested scale" approach
     * RasterImageFileProcessor already uses) rather than trusting the
     * requested max-dimension to know the real output size.
     *
     * @return array{bytes: string, width: int, height: int}|null
     */
    protected function captureFrame(string $ffmpegBinary, string $absolutePath, int $videoStreamIndex, float $atSeconds, int $maxDimension, int $timeoutSeconds): ?array
    {
        $scale = "scale='min({$maxDimension},iw)':-2";

        try {
            $process = new Process([
                $ffmpegBinary, '-hide_banner', '-loglevel', 'error',
                '-ss', (string) max(0.0, $atSeconds),
                '-i', $absolutePath,
                '-map', '0:v:'.$videoStreamIndex,
                '-vframes', '1',
                '-vf', $scale,
                '-f', 'mjpeg', '-',
            ]);
            $process->setTimeout($timeoutSeconds);
            $process->run();
        } catch (ProcessTimedOutException) {
            return null;
        } catch (\Throwable) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        $bytes = $process->getOutput();

        if ($bytes === '') {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ai-video-frame-');

        if ($tmp === false) {
            return null;
        }

        try {
            file_put_contents($tmp, $bytes);
            $info = @getimagesize($tmp);
        } finally {
            @unlink($tmp);
        }

        if ($info === false) {
            // Doc S9: never trust the ffmpeg call "succeeding" alone -
            // the bytes must actually decode as a real image.
            return null;
        }

        return ['bytes' => $bytes, 'width' => $info[0], 'height' => $info[1]];
    }

    /**
     * Doc S12 ("extract audio into a temporary working file... use
     * temporary storage... cleanup after processing") - this is
     * infrastructure for a FUTURE on-demand caller (doc S9: audio
     * extraction must not run automatically for every upload), so it is
     * implemented and tested here but deliberately never invoked from
     * VideoFileProcessor::process() itself. The caller owns the
     * returned path and is responsible for deleting it - this method
     * never writes outside `sys_get_temp_dir()` and never touches the
     * original video file.
     *
     * Capped at $maxSeconds regardless of the video's real duration -
     * same bounded-cost reasoning as AnalyzesAudioData::buildWaveform().
     * Output format is WAV (uncompressed PCM) deliberately: it is what
     * every downstream consumer can decode, and re-encoding to a lossy
     * format here would just be throwing away fidelity before the
     * actual transcription call (which already picks its own upload
     * format - see AiGateway::transcribeAudio()) ever sees it.
     */
    protected function extractAudioToTempFile(string $ffmpegBinary, string $absolutePath, int $audioStreamIndex, float $durationSeconds, int $maxSeconds, int $timeoutSeconds): ?string
    {
        $seconds = $durationSeconds > 0 ? min($durationSeconds, (float) $maxSeconds) : (float) $maxSeconds;
        $outputPath = sys_get_temp_dir().'/ai-video-audio-'.bin2hex(random_bytes(8)).'.wav';

        try {
            $process = new Process([
                $ffmpegBinary, '-hide_banner', '-loglevel', 'error', '-y',
                '-i', $absolutePath,
                '-map', '0:a:'.$audioStreamIndex,
                '-t', (string) $seconds,
                '-ac', '1', '-ar', '16000',
                '-f', 'wav', $outputPath,
            ]);
            $process->setTimeout($timeoutSeconds);
            $process->run();
        } catch (ProcessTimedOutException) {
            @unlink($outputPath);

            return null;
        } catch (\Throwable) {
            @unlink($outputPath);

            return null;
        }

        if (! $process->isSuccessful() || ! is_file($outputPath) || filesize($outputPath) === 0) {
            @unlink($outputPath);

            return null;
        }

        return $outputPath;
    }
}
