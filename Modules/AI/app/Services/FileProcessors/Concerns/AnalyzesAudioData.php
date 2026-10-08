<?php

namespace Modules\AI\Services\FileProcessors\Concerns;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Phase 6: shared ffprobe/ffmpeg plumbing used only by AudioFileProcessor.
 * Follows the exact same external-binary conventions already established
 * by Sandbox\Drivers\DockerSandboxDriver (see its own docblock): every
 * command is built as an array passed straight to Symfony\Process -
 * never a shell string - so there is no command-injection surface
 * regardless of what the uploaded filename contains; every call has an
 * explicit timeout; and a binary that cannot be reached is reported as
 * an honest "unavailable" result, never a thrown exception the caller
 * has to guess about.
 *
 * Verified empirically against a real ffmpeg/ffprobe 4.4.2 in this
 * session's device-bridge shell (see this phase's Final Report for the
 * exact commands run) - every field name this trait reads
 * (`format.duration`, `streams[].channel_layout` being ABSENT on mono
 * audio, `format.tags.date` vs `.year`, attached-cover-art showing up as
 * a second `codec_type: "video"` stream with `disposition.attached_pic:
 * 1`, audio-only WebM/Opus reporting MIME `video/webm`...) was confirmed
 * against real ffprobe JSON output, not assumed from memory of its docs.
 */
trait AnalyzesAudioData
{
    /**
     * @return array{available: bool, reason?: string}
     */
    protected function checkBinaryAvailable(string $binary): array
    {
        try {
            $process = new Process([$binary, '-version']);
            $process->setTimeout(5);
            $process->run();

            if (! $process->isSuccessful()) {
                return ['available' => false, 'reason' => 'binary_unreachable'];
            }

            return ['available' => true];
        } catch (\Throwable) {
            return ['available' => false, 'reason' => 'binary_not_found'];
        }
    }

    /**
     * Runs ffprobe and returns the decoded `-show_format -show_streams`
     * JSON, or null on ANY failure (non-zero exit, timeout, malformed
     * JSON, or a well-formed-but-empty `{}` response - confirmed this is
     * exactly what real ffprobe prints for a non-media file fed a media
     * extension: exit code 1, stdout `{}`, no `format`/`streams` keys at
     * all). Never a partial/guessed result.
     *
     * @return array{format: array<string, mixed>, streams: list<array<string, mixed>>}|null
     */
    protected function probeAudio(string $binary, string $absolutePath, int $timeoutSeconds): ?array
    {
        try {
            $process = new Process([
                $binary, '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', $absolutePath,
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

        $decoded = json_decode($process->getOutput(), true);

        if (! is_array($decoded) || ! isset($decoded['format']) || ! isset($decoded['streams']) || $decoded['streams'] === []) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param  list<array<string, mixed>>  $streams
     * @return list<array<string, mixed>>
     */
    protected function audioStreams(array $streams): array
    {
        return array_values(array_filter($streams, fn (array $s) => ($s['codec_type'] ?? null) === 'audio'));
    }

    /**
     * Real video streams only - excludes an embedded cover-art image
     * (MP3/FLAC/M4A commonly carry one as a second stream with
     * `codec_type: "video"`, `codec_name: "mjpeg"/"png"`, and
     * `disposition.attached_pic: 1`) - verified against a real MP3 with
     * an attached cover image in this session. A file whose only
     * "video" stream is attached cover art is still genuinely audio.
     *
     * @param  list<array<string, mixed>>  $streams
     * @return list<array<string, mixed>>
     */
    protected function realVideoStreams(array $streams): array
    {
        return array_values(array_filter(
            $streams,
            fn (array $s) => ($s['codec_type'] ?? null) === 'video' && (int) ($s['disposition']['attached_pic'] ?? 0) !== 1
        ));
    }

    /**
     * Doc S10: audio tags are user-provided and untrusted - split into
     * the same technical/ai_safe/sensitive shape Phase 5 uses for EXIF,
     * never exposed to AI wholesale.
     *
     * - `technical`: `encoder` - a tool/software identifier, useful,
     *   not personal.
     * - `ai_safe`: `language` only - genuinely useful for a downstream
     *   transcription/AI step (same reasoning AiChatService's own
     *   `languageHint` already uses) and carries no personal content.
     * - `sensitive`: `title`/`artist`/`album`/`genre`/`year` - free-text
     *   fields the uploader (or whatever app/device recorded the file)
     *   wrote themselves; a personal voice memo's "title" can trivially
     *   contain a real name or other personal content, so - same
     *   conservative stance as Phase 5's camera make/model - these are
     *   never promoted to ai_safe even though none of them are identity
     *   data on their own.
     *
     * Verified against real ffmpeg-written tags in this session: the
     * year is stored under the key `date`, not `year` (ffmpeg's own
     * convention) - both are checked so either an ffmpeg-written file or
     * one tagged by another tool still categorizes correctly.
     *
     * @param  array<string, mixed>  $tags
     * @return array{technical: array<string, mixed>, ai_safe: array<string, mixed>, sensitive: array<string, mixed>}
     */
    protected function categorizeAudioTags(array $tags): array
    {
        $lower = [];

        foreach ($tags as $key => $value) {
            $lower[strtolower((string) $key)] = $value;
        }

        $technical = [];
        $aiSafe = [];
        $sensitive = [];

        if (! empty($lower['encoder'])) {
            $technical['encoder'] = (string) $lower['encoder'];
        }

        if (! empty($lower['language'])) {
            $aiSafe['language'] = (string) $lower['language'];
        }

        foreach (['title', 'artist', 'album', 'genre'] as $key) {
            if (! empty($lower[$key])) {
                $sensitive[$key] = (string) $lower[$key];
            }
        }

        $year = $lower['year'] ?? $lower['date'] ?? null;

        if (! empty($year)) {
            $sensitive['year'] = (string) $year;
        }

        return ['technical' => $technical, 'ai_safe' => $aiSafe, 'sensitive' => $sensitive];
    }

    /**
     * Doc S12: a lightweight, bounded waveform - never the full-
     * resolution sample data. Decodes to mono, low-sample-rate
     * (deliberately 8kHz - far more resolution than a bar-chart
     * visualization needs) raw PCM via ffmpeg, capped at
     * $maxSourceSeconds regardless of the file's real duration (bounded
     * memory: at 8kHz/16-bit/mono this is at most
     * `$maxSourceSeconds * 16000` bytes - e.g. ~1.9MB for a 120s cap, on
     * a multi-hour recording exactly as much as on a 2-minute one), then
     * bucketed into $barCount bars by peak absolute amplitude,
     * normalized to 0..1. Deterministic (same input -> same bars) and
     * read-only (the original file is never opened for writing).
     *
     * Returns null on any failure (binary unavailable, decode failure,
     * timeout, or literally zero decoded samples) - the caller treats
     * that as "no waveform" plus a warning, never a fabricated flat line.
     *
     * @return array{bars: list<float>, bar_count: int, source_seconds_analyzed: float, sample_rate_used: int}|null
     */
    protected function buildWaveform(string $ffmpegBinary, string $absolutePath, float $durationSeconds, int $maxSourceSeconds, int $barCount, int $timeoutSeconds): ?array
    {
        if ($barCount < 1) {
            return null;
        }

        $sampleRate = 8000;
        $secondsToDecode = $durationSeconds > 0 ? min($durationSeconds, (float) $maxSourceSeconds) : (float) $maxSourceSeconds;

        try {
            $process = new Process([
                $ffmpegBinary, '-hide_banner', '-loglevel', 'error',
                '-i', $absolutePath,
                '-map', '0:a:0',
                '-ac', '1', '-ar', (string) $sampleRate,
                '-t', (string) $secondsToDecode,
                '-f', 's16le', '-',
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

        $raw = $process->getOutput();
        $sampleCount = intdiv(strlen($raw), 2);

        if ($sampleCount < 1) {
            return null;
        }

        $samples = array_values(unpack('v*', $raw) ?: []);
        // Doc S10's unsigned->signed 16-bit conversion - unpack('v*')
        // reads unsigned shorts; audio PCM is signed.
        $samples = array_map(fn ($s) => $s >= 32768 ? $s - 65536 : $s, $samples);

        $samplesPerBar = max(1, intdiv(count($samples), $barCount));
        $bars = [];

        for ($i = 0; $i < $barCount; $i++) {
            $slice = array_slice($samples, $i * $samplesPerBar, $samplesPerBar);

            if ($slice === []) {
                $bars[] = 0.0;

                continue;
            }

            $peak = max(array_map('abs', $slice));
            $bars[] = round(min(1.0, $peak / 32768), 4);
        }

        return [
            'bars' => $bars,
            'bar_count' => count($bars),
            'source_seconds_analyzed' => round($secondsToDecode, 2),
            'sample_rate_used' => $sampleRate,
        ];
    }
}
