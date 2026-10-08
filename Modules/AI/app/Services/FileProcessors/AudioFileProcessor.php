<?php

namespace Modules\AI\Services\FileProcessors;

use Modules\AI\Services\FileProcessors\Concerns\AnalyzesAudioData;

/**
 * Phase 6: the first real audio support in this codebase's Universal AI
 * File Engine pipeline (Phases 1-5). Metadata/security/validation only -
 * this processor NEVER calls any AI provider, and never transcribes
 * anything itself (doc S1/S13/architectural rule 1/6/7). Transcription
 * already exists, fully working, in this codebase's OLDER, separate
 * attachment pipeline (`AiChatService::transcribeIncomingAudio()` ->
 * `AiGateway::transcribeAudio()` -> a real provider-agnostic connector
 * call, already correctly resolved through `AiModelResolver`/the
 * `speech_to_text` capability - no new transcription architecture is
 * added here, because a real one already exists and already works).
 *
 * No new Composer dependency. Metadata comes from `ffprobe`, waveform
 * generation from `ffmpeg` - both external system binaries, detected at
 * runtime (never assumed present), with configurable paths (never
 * hardcoded) and every invocation built as an array argument list
 * through Symfony\Process (never a shell string) - see
 * `Concerns\AnalyzesAudioData`'s own docblock for the exact safety
 * conventions, shared with the pre-existing `DockerSandboxDriver`.
 *
 * Every field name and edge case this processor relies on
 * (`format.duration`, a missing `channel_layout` on mono audio,
 * `format.tags.date` vs `.year`, embedded cover art showing up as a
 * `video`-typed stream, audio-only WebM/Opus reporting MIME
 * `video/webm`) was verified against a REAL ffmpeg/ffprobe 4.4.2 in this
 * session's device-bridge shell - not assumed from memory of their
 * documentation. See this phase's Final Report for the exact commands
 * run and their real output.
 */
class AudioFileProcessor implements AiFileProcessorInterface
{
    use AnalyzesAudioData;

    /**
     * Doc S2: every one of these was verified to actually decode with
     * THIS environment's ffmpeg/ffprobe build (libmp3lame, native aac,
     * libopus, libvorbis, flac, pcm all confirmed present via
     * `ffmpeg -version`'s `--enable-*` flags) - not claimed on the
     * strength of the file extension alone.
     *
     * `video/webm` is included deliberately: a real investigation (doc
     * S2) found that `file`/`finfo`-based real MIME detection reports an
     * audio-only WebM/Opus file (no video track at all) as `video/webm`,
     * never a distinct audio MIME - WebM's container format is shared
     * between audio and video and libmagic does not special-case the
     * audio-only case. Accepting it here and verifying real stream
     * composition inside process() (no genuine video stream - see
     * `realVideoStreams()`) is the only way to support audio-only WebM
     * uploads at all; a file that turns out to carry a real video stream
     * is honestly rejected as `AUDIO_CONTAINER_HAS_VIDEO`, never silently
     * treated as audio.
     */
    protected const SUPPORTED = [
        'audio/mpeg', 'audio/mp3',
        'audio/wav', 'audio/x-wav', 'audio/wave',
        'audio/mp4', 'audio/x-m4a', 'audio/m4a',
        'audio/aac',
        'audio/ogg', 'audio/opus',
        'audio/flac', 'audio/x-flac',
        'audio/webm',
        'video/webm',
    ];

    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, self::SUPPORTED, true);
    }

    public function process(string $absolutePath, string $mimeType): AiFileProcessingResult
    {
        if (! $this->supports($mimeType)) {
            return AiFileProcessingResult::failed('unsupported_mime_type');
        }

        $ffprobeBinary = (string) config('ai.files.audio_ffprobe_path', 'ffprobe');
        $availability = $this->checkBinaryAvailable($ffprobeBinary);

        if (! $availability['available']) {
            // Doc S5: never crash, never fake support - an honest,
            // specific failure that an admin can act on (install
            // ffmpeg/ffprobe, or set AI_AUDIO_FFPROBE_PATH).
            return AiFileProcessingResult::failed('AUDIO_PROCESSOR_UNAVAILABLE');
        }

        $probeTimeout = (int) config('ai.files.audio_probe_timeout_seconds', 15);
        $probe = $this->probeAudio($ffprobeBinary, $absolutePath, $probeTimeout);

        if ($probe === null) {
            // Doc S6/S9: distrust the MIME/extension entirely - a file
            // ffprobe itself cannot parse as media is not audio, however
            // it was labeled.
            return AiFileProcessingResult::failed('AUDIO_INVALID');
        }

        $streams = $probe['streams'];
        $audioStreams = $this->audioStreams($streams);

        if ($audioStreams === []) {
            return AiFileProcessingResult::failed('AUDIO_INVALID');
        }

        if ($this->realVideoStreams($streams) !== []) {
            // See SUPPORTED's own docblock - `video/webm` is accepted
            // provisionally for the audio-only case; a genuine video
            // track means this upload was never really audio.
            return AiFileProcessingResult::failed('AUDIO_CONTAINER_HAS_VIDEO');
        }

        $primaryStream = $audioStreams[0];
        $format = $probe['format'];
        $warnings = [];

        $duration = isset($format['duration']) ? (float) $format['duration'] : null;

        if ($duration === null) {
            // Doc S8: never fabricated - an honest, explicit "unknown",
            // not a guessed/zero value.
            $warnings[] = 'AUDIO_METADATA_UNAVAILABLE';
        }

        $limits = $this->limits();

        if ($duration !== null && $duration > $limits['max_duration_seconds']) {
            return AiFileProcessingResult::failed('AUDIO_DURATION_TOO_LONG');
        }

        $sampleRate = isset($primaryStream['sample_rate']) ? (int) $primaryStream['sample_rate'] : null;

        if ($sampleRate !== null && $sampleRate > $limits['max_sample_rate']) {
            return AiFileProcessingResult::failed('AUDIO_SAMPLE_RATE_TOO_HIGH');
        }

        $channels = isset($primaryStream['channels']) ? (int) $primaryStream['channels'] : null;

        if ($channels !== null && $channels > $limits['max_channels']) {
            return AiFileProcessingResult::failed('AUDIO_CHANNEL_LIMIT_EXCEEDED');
        }

        $bitrate = $primaryStream['bit_rate'] ?? $format['bit_rate'] ?? null;
        $detectedMime = $this->detectedMimeMismatchWarning($mimeType, $format, $primaryStream);

        if ($detectedMime !== null) {
            $warnings[] = $detectedMime;
        }

        $metadata = [
            'format' => $this->formatLabel($format, $primaryStream),
            'container' => $this->containerLabel($format),
            'mime_type' => $mimeType,
            'duration_seconds' => $duration,
            'sample_rate' => $sampleRate,
            'channels' => $channels,
            'channel_layout' => $primaryStream['channel_layout'] ?? null,
            'bitrate' => $bitrate !== null ? (int) $bitrate : null,
            'codec' => $primaryStream['codec_name'] ?? null,
            'codec_long_name' => $primaryStream['codec_long_name'] ?? null,
            'bits_per_sample' => isset($primaryStream['bits_per_sample']) && (int) $primaryStream['bits_per_sample'] > 0
                ? (int) $primaryStream['bits_per_sample']
                : null,
            'stream_count' => count($streams),
            'audio_stream_count' => count($audioStreams),
            'file_size_bytes' => isset($format['size']) ? (int) $format['size'] : null,
            'tags' => $this->categorizeAudioTags(array_merge(
                is_array($format['tags'] ?? null) ? $format['tags'] : [],
                is_array($primaryStream['tags'] ?? null) ? $primaryStream['tags'] : [],
            )),
        ];

        $waveform = $this->maybeBuildWaveform($absolutePath, $duration, $warnings);

        if ($waveform !== null) {
            $metadata['waveform'] = $waveform;
        }

        return AiFileProcessingResult::ok(
            text: null,
            metadata: $metadata,
            warnings: $warnings,
            documentType: 'audio',
        );
    }

    /**
     * @return array{max_duration_seconds: int, max_sample_rate: int, max_channels: int}
     */
    protected function limits(): array
    {
        return [
            'max_duration_seconds' => (int) config('ai.files.audio_max_duration_seconds', 3600),
            'max_sample_rate' => (int) config('ai.files.audio_max_sample_rate', 192000),
            'max_channels' => (int) config('ai.files.audio_max_channels', 8),
        ];
    }

    /**
     * Doc S9: a second, independent confirmation at the real-decoder
     * level, same soft-warning (not auto-reject) treatment Phase 5 uses
     * for images - some legitimate files genuinely have ambiguous/
     * overlapping container MIME strings (verified in this phase: WAV
     * commonly reports as `audio/x-wav`, not `audio/wav`; Opus-in-Ogg
     * reports as plain `audio/ogg`).
     */
    protected function detectedMimeMismatchWarning(string $mimeType, array $format, array $primaryStream): ?string
    {
        $container = (string) ($format['format_name'] ?? '');
        $codec = (string) ($primaryStream['codec_name'] ?? '');

        $plausible = match (true) {
            str_contains($mimeType, 'mpeg') || str_contains($mimeType, 'mp3') => $codec === 'mp3',
            str_contains($mimeType, 'wav') => str_contains($container, 'wav'),
            str_contains($mimeType, 'mp4') || str_contains($mimeType, 'm4a') => str_contains($container, 'mp4') || str_contains($container, 'm4a'),
            str_contains($mimeType, 'aac') => $codec === 'aac',
            str_contains($mimeType, 'ogg') || str_contains($mimeType, 'opus') => str_contains($container, 'ogg'),
            str_contains($mimeType, 'flac') => $codec === 'flac' || str_contains($container, 'flac'),
            str_contains($mimeType, 'webm') => str_contains($container, 'webm') || str_contains($container, 'matroska'),
            default => true,
        };

        return $plausible ? null : 'AUDIO_MIME_MISMATCH';
    }

    /**
     * Doc S9/S4: a friendly, verified-not-guessed format label - built
     * from the real decoded codec, not the container MIME (an Opus file
     * muxed in Ogg and a Vorbis file muxed in Ogg share the exact same
     * container MIME and `format_name`, but are not the same format).
     */
    protected function formatLabel(array $format, array $primaryStream): string
    {
        $codec = (string) ($primaryStream['codec_name'] ?? '');
        $container = (string) ($format['format_name'] ?? '');

        return match (true) {
            $codec === 'mp3' => 'mp3',
            str_starts_with($codec, 'pcm_') => 'wav',
            $codec === 'aac' => str_contains($container, 'mp4') || str_contains($container, 'm4a') ? 'm4a' : 'aac',
            $codec === 'opus' => 'opus',
            $codec === 'vorbis' => 'ogg_vorbis',
            $codec === 'flac' => 'flac',
            str_contains($container, 'webm') || str_contains($container, 'matroska') => 'webm',
            $codec !== '' => $codec,
            default => 'unknown',
        };
    }

    protected function containerLabel(array $format): string
    {
        // Doc S9: `format_name` is a comma-joined ffprobe guess list for
        // some containers (verified: M4A reports
        // "mov,mp4,m4a,3gp,3g2,mj2") - only the first, most specific
        // entry is kept.
        $name = (string) ($format['format_name'] ?? 'unknown');

        return explode(',', $name)[0] ?: 'unknown';
    }

    /**
     * Doc S12: optional, bounded, deterministic - degrades to "no
     * waveform" plus a warning on any failure (ffmpeg missing, decode
     * failure, disabled via config), never a fabricated one.
     */
    protected function maybeBuildWaveform(string $absolutePath, ?float $duration, array &$warnings): ?array
    {
        if (! (bool) config('ai.files.audio_waveform_enabled', true)) {
            return null;
        }

        $ffmpegBinary = (string) config('ai.files.audio_ffmpeg_path', 'ffmpeg');
        $availability = $this->checkBinaryAvailable($ffmpegBinary);

        if (! $availability['available']) {
            $warnings[] = 'AUDIO_WAVEFORM_UNAVAILABLE';

            return null;
        }

        $waveform = $this->buildWaveform(
            $ffmpegBinary,
            $absolutePath,
            $duration ?? (float) config('ai.files.audio_waveform_max_source_seconds', 120),
            (int) config('ai.files.audio_waveform_max_source_seconds', 120),
            (int) config('ai.files.audio_waveform_bars', 100),
            (int) config('ai.files.audio_waveform_timeout_seconds', 20),
        );

        if ($waveform === null) {
            $warnings[] = 'AUDIO_WAVEFORM_UNAVAILABLE';
        }

        return $waveform;
    }
}
