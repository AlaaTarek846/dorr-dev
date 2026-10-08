<?php

namespace Modules\AI\Services\FileProcessors;

use Modules\AI\Services\FileProcessors\Concerns\AnalyzesVideoData;

/**
 * Phase 7: the first real video support in this codebase's Universal AI
 * File Engine (Phases 1-6). Metadata/classification/security only -
 * like AudioFileProcessor (Phase 6), this processor NEVER calls any AI
 * provider and NEVER transcribes anything itself (doc architectural
 * rule 1/6/30). Audio extraction and multi-frame extraction are
 * implemented as separate, on-demand capabilities (see
 * AnalyzesVideoData::extractAudioToTempFile() and VideoFrameExtractor)
 * that this method deliberately does NOT call automatically (doc S8/S9:
 * "the processor should NOT automatically execute every expensive
 * operation" / "do NOT automatically transcribe every video") - only a
 * single bounded poster frame is generated on every upload, the video
 * equivalent of RasterImageFileProcessor's always-on thumbnail (doc
 * S18/S19).
 *
 * `video/webm` is a genuinely ambiguous container MIME shared with
 * audio-only WebM/Opus uploads (see AudioFileProcessor's own docblock -
 * that finding was made and verified in Phase 6, not re-investigated
 * here). Rather than duplicating that already-correct audio handling,
 * this processor is simply registered BEFORE AudioFileProcessor in
 * AiFileProcessorManager for this one MIME, and when its own ffprobe
 * inspection finds no genuine video stream, it DELEGATES to the real,
 * injected AudioFileProcessor instance instead of failing or
 * re-implementing any of its logic (see `delegateToAudioProcessor()`).
 *
 * Every field name and edge case this processor relies on (MOV and MP4
 * sharing the identical ffprobe `format.format_name`, `display_aspect_ratio`
 * only appearing for a non-square pixel aspect ratio, a corrupted/
 * zero-byte file producing ffprobe exit code 1 with literal `{}`
 * stdout, a real JPEG frame decodable via `-f mjpeg -` to stdout) was
 * verified against a REAL ffmpeg/ffprobe 4.4.2 in this session's
 * device-bridge shell - see this phase's Final Report for the exact
 * commands run and their real output.
 */
class VideoFileProcessor implements AiFileProcessorInterface
{
    use AnalyzesVideoData;

    /**
     * Doc S3: MP4/MOV/WEBM/AVI, matched by real, finfo-detected MIME
     * (AiFileEngine already replaces the client-claimed MIME with the
     * real one before routing here - doc S3/S27: "do not rely on
     * extension alone"). `video/webm` is deliberately included despite
     * the ambiguity explained above - the delegation branch is exactly
     * how that ambiguity is resolved per upload, not avoided by
     * excluding the MIME.
     */
    protected const SUPPORTED = [
        'video/mp4',
        'video/quicktime',
        'video/webm',
        'video/x-msvideo',
    ];

    public function __construct(protected AudioFileProcessor $audioProcessor) {}

    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, self::SUPPORTED, true);
    }

    public function process(string $absolutePath, string $mimeType): AiFileProcessingResult
    {
        if (! $this->supports($mimeType)) {
            return AiFileProcessingResult::failed('unsupported_mime_type');
        }

        $ffprobeBinary = (string) config('ai.files.video_ffprobe_path', 'ffprobe');
        $availability = $this->checkBinaryAvailable($ffprobeBinary);

        if (! $availability['available']) {
            return AiFileProcessingResult::failed('VIDEO_PROCESSOR_UNAVAILABLE');
        }

        $probeTimeout = (int) config('ai.files.video_probe_timeout_seconds', 20);
        $probe = $this->probeAudio($ffprobeBinary, $absolutePath, $probeTimeout);

        if ($probe === null) {
            // Doc S20: ffprobe itself could not read this file at all -
            // never exposes the raw ffprobe/shell output to the caller,
            // just the normalized error code.
            return AiFileProcessingResult::failed('VIDEO_UNREADABLE');
        }

        $streams = $probe['streams'];
        $format = $probe['format'];
        $videoStreams = $this->realVideoStreams($streams);
        $audioStreams = $this->audioStreams($streams);

        if ($videoStreams === []) {
            // Doc S7: no real (non-attached-pic) video stream at all -
            // this is not a video, however it was labeled.
            if ($audioStreams !== []) {
                $delegated = $this->delegateToAudioProcessor($absolutePath, $mimeType);

                if ($delegated !== null) {
                    return $delegated;
                }
            }

            return AiFileProcessingResult::failed('VIDEO_NO_VIDEO_STREAM');
        }

        $primaryVideo = $this->selectPrimaryStream($videoStreams);
        $warnings = [];

        $duration = isset($format['duration']) ? (float) $format['duration'] : null;

        if ($duration === null) {
            $warnings[] = 'VIDEO_METADATA_UNAVAILABLE';
        }

        $limits = $this->limits();

        if ($duration !== null && $duration > $limits['max_duration_seconds']) {
            return AiFileProcessingResult::failed('VIDEO_TOO_LONG');
        }

        $width = isset($primaryVideo['width']) ? (int) $primaryVideo['width'] : null;
        $height = isset($primaryVideo['height']) ? (int) $primaryVideo['height'] : null;

        if ($width !== null && $height !== null && ($width > $limits['max_width'] || $height > $limits['max_height'])) {
            return AiFileProcessingResult::failed('VIDEO_DIMENSIONS_TOO_LARGE');
        }

        [$displayWidth, $displayHeight] = $width !== null && $height !== null
            ? $this->displayDimensions($primaryVideo, $width, $height)
            : [null, null];

        $primaryAudio = $audioStreams !== [] ? $this->selectPrimaryStream($audioStreams) : null;
        $detectedMime = $this->detectedMimeMismatchWarning($mimeType, $format);

        if ($detectedMime !== null) {
            $warnings[] = $detectedMime;
        }

        $metadata = [
            'container' => $this->containerLabel($mimeType),
            'mime_type' => $mimeType,
            'classification' => 'video',
            'duration_seconds' => $duration,
            'width' => $width,
            'height' => $height,
            'display_width' => $displayWidth,
            'display_height' => $displayHeight,
            'frame_rate' => $this->parseFrameRate($primaryVideo['r_frame_rate'] ?? null),
            'avg_frame_rate' => $this->parseFrameRate($primaryVideo['avg_frame_rate'] ?? null),
            'video_codec' => $primaryVideo['codec_name'] ?? null,
            'video_codec_long_name' => $primaryVideo['codec_long_name'] ?? null,
            'pixel_format' => $primaryVideo['pix_fmt'] ?? null,
            'rotation' => $this->rotationDegrees($primaryVideo),
            'bitrate' => isset($format['bit_rate']) ? (int) $format['bit_rate'] : null,
            'file_size_bytes' => isset($format['size']) ? (int) $format['size'] : null,
            'stream_count' => count($streams),
            'video_streams' => $this->describeStreams($videoStreams, 'video'),
            'audio' => [
                'available' => $audioStreams !== [],
                'streams' => $this->describeStreams($audioStreams, 'audio'),
                'selected_index' => $primaryAudio['index'] ?? null,
            ],
            'subtitles' => [
                'available' => $this->subtitleStreams($streams) !== [],
                'count' => count($this->subtitleStreams($streams)),
            ],
            'tags' => $this->categorizeAudioTags(is_array($format['tags'] ?? null) ? $format['tags'] : []),
        ];

        $primaryVideoIndex = array_search($primaryVideo, $videoStreams, true);
        $poster = $this->maybeBuildPoster($absolutePath, $primaryVideoIndex !== false ? $primaryVideoIndex : 0, $duration, $warnings);

        if ($poster !== null) {
            $metadata['preview_assets'] = ['poster' => $poster];
        }

        return AiFileProcessingResult::ok(
            text: null,
            metadata: $metadata,
            warnings: $warnings,
            documentType: 'video',
        );
    }

    /**
     * Doc S7/S29 ("audio-only WebM" worked example): reuses the real,
     * already-correct AudioFileProcessor rather than re-implementing
     * any audio classification/metadata logic. Only attempted for the
     * two container families this phase actually verified carry a
     * video-ish MIME for audio-only content (WebM/Opus - Phase 6's own
     * finding - and the MOV/MP4/M4A family, which shares one ffprobe
     * container format). AVI has no such convention and is honestly
     * rejected as VIDEO_NO_VIDEO_STREAM instead of guessing an audio
     * MIME AudioFileProcessor was never verified to handle correctly.
     */
    protected function delegateToAudioProcessor(string $absolutePath, string $mimeType): ?AiFileProcessingResult
    {
        $audioMime = match ($mimeType) {
            'video/webm' => 'audio/webm',
            'video/mp4', 'video/quicktime' => 'audio/mp4',
            default => null,
        };

        if ($audioMime === null) {
            return null;
        }

        $result = $this->audioProcessor->process($absolutePath, $audioMime);

        return $result->success ? $result : null;
    }

    /**
     * @return array{max_duration_seconds: int, max_width: int, max_height: int}
     */
    protected function limits(): array
    {
        return [
            'max_duration_seconds' => (int) config('ai.files.video_max_duration_seconds', 3600),
            'max_width' => (int) config('ai.files.video_max_width', 7680),
            'max_height' => (int) config('ai.files.video_max_height', 4320),
        ];
    }

    /**
     * Doc S9's "distrust the container MIME" check, adapted for video:
     * unlike audio, there is no second independent decoder confirming
     * the real codec cheaply, so this only ever compares the real
     * `format.format_name` against the claimed MIME's own family -
     * a soft warning, never a hard rejection, for the same reason Phase
     * 5/6 kept this a warning (legitimate ambiguous containers exist).
     */
    protected function detectedMimeMismatchWarning(string $mimeType, array $format): ?string
    {
        $container = (string) ($format['format_name'] ?? '');

        $plausible = match ($mimeType) {
            'video/mp4', 'video/quicktime' => str_contains($container, 'mov') || str_contains($container, 'mp4'),
            'video/webm' => str_contains($container, 'webm') || str_contains($container, 'matroska'),
            'video/x-msvideo' => str_contains($container, 'avi'),
            default => true,
        };

        return $plausible ? null : 'VIDEO_MIME_MISMATCH';
    }

    /**
     * Doc S9: unlike AudioFileProcessor::containerLabel() (which can
     * safely read ffprobe's own `format_name`), MOV and MP4 share the
     * exact same `format_name` string - verified empirically - so the
     * real, finfo-detected MIME type this processor was called with is
     * the only reliable source for this label.
     */
    protected function containerLabel(string $mimeType): string
    {
        return match ($mimeType) {
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            'video/x-msvideo' => 'avi',
            default => 'unknown',
        };
    }

    /**
     * Doc S4 ("rotation/orientation... where useful") - checked from
     * both locations a real encoder may place it (a classic `rotate`
     * tag, or a modern `displaymatrix` side-data entry) and left null,
     * never guessed as 0, when neither is present - verified in this
     * phase that a typical encode carries neither by default.
     */
    protected function rotationDegrees(array $videoStream): ?int
    {
        $tagRotate = $videoStream['tags']['rotate'] ?? null;

        if ($tagRotate !== null && is_numeric($tagRotate)) {
            return (int) $tagRotate;
        }

        foreach ($videoStream['side_data_list'] ?? [] as $sideData) {
            if (is_array($sideData) && isset($sideData['rotation']) && is_numeric($sideData['rotation'])) {
                return (int) $sideData['rotation'];
            }
        }

        return null;
    }

    /**
     * Doc S13/S14 ("record index/codec/language/channels/sample
     * rate/bitrate/default disposition... for every stream - make the
     * selection visible in processing metadata").
     *
     * @param  list<array<string, mixed>>  $streams
     * @return list<array<string, mixed>>
     */
    protected function describeStreams(array $streams, string $type): array
    {
        return array_values(array_map(function (array $stream) use ($type) {
            $base = [
                'index' => $stream['index'] ?? null,
                'codec' => $stream['codec_name'] ?? null,
                'language' => $stream['tags']['language'] ?? null,
                'default' => (int) ($stream['disposition']['default'] ?? 0) === 1,
                'bitrate' => isset($stream['bit_rate']) ? (int) $stream['bit_rate'] : null,
            ];

            if ($type === 'video') {
                return [...$base,
                    'width' => $stream['width'] ?? null,
                    'height' => $stream['height'] ?? null,
                    'frame_rate' => $this->parseFrameRate($stream['r_frame_rate'] ?? null),
                ];
            }

            return [...$base,
                'channels' => $stream['channels'] ?? null,
                'sample_rate' => isset($stream['sample_rate']) ? (int) $stream['sample_rate'] : null,
            ];
        }, $streams));
    }

    /**
     * Doc S18/S19: always-on, single bounded poster frame - degrades to
     * "no poster" plus a warning on any failure (ffmpeg missing, an
     * unsupported/undecodable codec the container nonetheless claims to
     * carry - doc S21's "unsupported processing codec" case - or a
     * decode failure), never a fabricated one. Reuses the exact same
     * `metadata['preview_assets']` shape RasterImageFileProcessor
     * already produces, so ProcessAiFileJob persists it with zero
     * changes to that job (see its `persistPreviewAssets()`).
     */
    protected function maybeBuildPoster(string $absolutePath, int $primaryVideoIndex, ?float $duration, array &$warnings): ?array
    {
        $ffmpegBinary = (string) config('ai.files.video_ffmpeg_path', 'ffmpeg');
        $availability = $this->checkBinaryAvailable($ffmpegBinary);

        if (! $availability['available']) {
            $warnings[] = 'VIDEO_PREVIEW_UNAVAILABLE';

            return null;
        }

        $timestamp = $this->posterTimestamp($duration ?? 0.0);
        $maxDimension = (int) config('ai.files.video_poster_max_dimension', 480);
        $timeoutSeconds = (int) config('ai.files.video_poster_timeout_seconds', 20);

        // Doc S14: ffmpeg's `-map 0:v:N` indexes only among VIDEO
        // streams (0-based, in ffprobe's own reported order) - $primaryVideoIndex
        // is that stream's position within $videoStreams, so a non-first
        // "default" video stream (doc S14: "prefer it") is captured from
        // correctly instead of always grabbing the file's first video stream.
        $frame = $this->captureFrame($ffmpegBinary, $absolutePath, $primaryVideoIndex, $timestamp, $maxDimension, $timeoutSeconds);

        if ($frame === null) {
            $warnings[] = 'VIDEO_PREVIEW_UNAVAILABLE';

            return null;
        }

        return [
            'bytes' => $frame['bytes'],
            'extension' => 'jpg',
            'width' => $frame['width'],
            'height' => $frame['height'],
        ];
    }
}
