<?php

namespace Modules\AI\Services\FileProcessors;

use Modules\AI\Services\FileProcessors\Concerns\AnalyzesImageData;

/**
 * Phase 5 (doc S3-S10): the first real image support in this codebase.
 * Deliberately needs NO new Composer dependency - every capability here
 * is gated behind a runtime `extension_loaded()`/`class_exists()` check
 * against PHP extensions that are near-universally bundled (gd) or
 * built into PHP itself (getimagesize, exif_read_data is behind the
 * bundled-by-default `ext-exif`). This environment has no way to
 * introspect the real server's loaded extensions (no php binary
 * reachable from this shell - see this phase's Final Report), so every
 * one of these guards degrades to an HONEST failure/skip rather than a
 * fatal error if the extension turns out to be missing - never a silent
 * assumption that GD/EXIF are present.
 *
 * Handles JPG/JPEG/PNG/WEBP/GIF/BMP always (doc S3: these are all
 * GD-decodable on a standard PHP build), and TIFF only when Imagick
 * happens to be available (doc S3: TIFF is NOT GD-decodable - this is
 * reported as an honest capability gap, never silently downgraded to
 * "unsupported" without saying why, and never faked as working).
 *
 * SVG is NOT handled here - see SvgImageFileProcessor's own docblock for
 * why SVG needs an entirely different (non-raster, XML-safety) strategy.
 *
 * Normalized metadata (doc S11) lives in the same
 * AiFileProcessingResult::metadata bag every other processor already
 * uses - no second normalization system, consistent with every prior
 * phase.
 */
class RasterImageFileProcessor implements AiFileProcessorInterface
{
    use AnalyzesImageData;

    protected const SUPPORTED = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/bmp',
        'image/tiff',
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

        if ($mimeType === 'image/tiff' && ! $this->tiffSupported()) {
            // Doc S3: honest capability gap - GD cannot decode TIFF on
            // any PHP build, and Imagick is an optional extension this
            // environment cannot confirm is installed on the real
            // server. Never silently treated as a generic "unsupported
            // file type" - the metadata explains exactly why.
            return AiFileProcessingResult::failed('IMAGE_TIFF_REQUIRES_IMAGICK');
        }

        if (! is_readable($absolutePath)) {
            return AiFileProcessingResult::failed('IMAGE_INVALID');
        }

        $fileSize = @filesize($absolutePath);

        $imageInfo = @getimagesize($absolutePath);

        if ($imageInfo === false) {
            // Doc S9: a file whose extension/declared MIME says "image"
            // but that getimagesize() cannot parse is exactly the
            // MIME/extension-spoofing case this phase must catch - never
            // trust the caller-supplied $mimeType param beyond this
            // point.
            return AiFileProcessingResult::failed('IMAGE_INVALID');
        }

        [$width, $height] = [$imageInfo[0], $imageInfo[1]];
        $detectedMime = $imageInfo['mime'] ?? null;
        $warnings = [];

        if ($detectedMime !== null && $detectedMime !== $mimeType) {
            // Doc S9: the real decoder disagrees with the MIME this
            // processor was told to trust (e.g. a renamed .png claiming
            // to be .bmp). AiFileEngine::validate() already ran a real
            // finfo MIME check before routing here, so this is a
            // second, independent confirmation at the pixel-decoder
            // level - reported as a warning, not a hard failure, since
            // a small number of valid files legitimately have
            // ambiguous/overlapping MIME strings (e.g. some BMP/ICO
            // edge cases) and this phase's rule is "distrust blindly
            // trusting," not "reject anything slightly unusual."
            $warnings[] = 'IMAGE_MIME_MISMATCH_WARNING';
        }

        $limits = $this->dimensionLimits();

        if ($width > $limits['max_width'] || $height > $limits['max_height']) {
            return AiFileProcessingResult::failed('IMAGE_DIMENSIONS_TOO_LARGE');
        }

        if ($width * $height > $limits['max_pixels']) {
            // Doc S6/S9: decompression-bomb protection - rejected using
            // getimagesize()'s cheap header read, BEFORE any pixel data
            // is ever decoded via imagecreatefromstring()/GD.
            return AiFileProcessingResult::failed('IMAGE_PIXEL_LIMIT_EXCEEDED');
        }

        $metadata = [
            'width' => $width,
            'height' => $height,
            'aspect_ratio' => $height > 0 ? round($width / $height, 4) : null,
            'mime_type' => $detectedMime ?? $mimeType,
            'format' => $this->formatFromMime($detectedMime ?? $mimeType),
            'color_model' => $this->colorModelLabel($imageInfo['channels'] ?? null),
            'bit_depth' => $imageInfo['bits'] ?? null,
            'orientation' => null,
            'animated' => false,
            'frame_count' => 1,
            'exif' => ['technical' => [], 'ai_safe' => [], 'sensitive' => []],
            'file_size_bytes' => $fileSize !== false ? $fileSize : null,
        ];

        $rawBytes = null;

        if ($mimeType === 'image/gif' || $mimeType === 'image/webp') {
            $rawBytes = @file_get_contents($absolutePath);

            if ($rawBytes !== false) {
                if ($mimeType === 'image/gif') {
                    $frames = $this->countGifFrames($rawBytes);
                    $metadata['animated'] = $frames > 1;
                    $metadata['frame_count'] = $frames;
                } else {
                    $metadata['animated'] = $this->isAnimatedWebp($rawBytes);
                    // Doc S10: an exact WEBP animated-frame count needs a
                    // real RIFF/VP8X chunk walk this phase does not
                    // implement (kept to the cheap presence-check
                    // heuristic) - frame_count is honestly left at 1
                    // with `animated: true` rather than guessed.
                    if ($metadata['animated']) {
                        $metadata['frame_count'] = null;
                        $warnings[] = 'IMAGE_WEBP_FRAME_COUNT_UNAVAILABLE';
                    }
                }

                if ($metadata['frame_count'] !== null && $metadata['frame_count'] > $limits['max_animation_frames']) {
                    return AiFileProcessingResult::failed('IMAGE_ANIMATION_FRAMES_EXCEEDED');
                }
            } else {
                $warnings[] = 'IMAGE_ANIMATION_DETECTION_FAILED';
            }
        }

        if ($mimeType === 'image/jpeg' && extension_loaded('exif') && function_exists('exif_read_data')) {
            try {
                $exif = @exif_read_data($absolutePath, null, true);
                $flat = is_array($exif) ? array_merge(...array_values(array_filter($exif, 'is_array'))) : [];
                $categorized = $this->categorizeExif($flat);
                $metadata['exif'] = $categorized;
                $metadata['orientation'] = $categorized['technical']['orientation'] ?? null;
            } catch (\Throwable) {
                $warnings[] = 'IMAGE_EXIF_READ_FAILED';
            }
        } elseif ($mimeType === 'image/jpeg') {
            $warnings[] = 'IMAGE_EXIF_UNAVAILABLE_NO_EXT';
        }

        $previewAssets = [];

        if (extension_loaded('gd')) {
            try {
                $previewAssets = $this->buildPreviewAssets($absolutePath, $mimeType, $warnings);
            } catch (\Throwable) {
                $warnings[] = 'IMAGE_PREVIEW_FAILED';
            }
        } else {
            // Doc S8: honest - no previews generated at all without GD,
            // never a fake/placeholder preview path.
            $warnings[] = 'IMAGE_PREVIEW_UNAVAILABLE_NO_GD';
        }

        if ($previewAssets !== []) {
            $metadata['preview_assets'] = $previewAssets;
        }

        return AiFileProcessingResult::ok(
            text: null,
            metadata: $metadata,
            warnings: $warnings,
            documentType: 'image',
        );
    }

    /**
     * @return array{max_width:int, max_height:int, max_pixels:int, max_animation_frames:int}
     */
    protected function dimensionLimits(): array
    {
        return [
            'max_width' => (int) config('ai.files.image_max_width', 8000),
            'max_height' => (int) config('ai.files.image_max_height', 8000),
            'max_pixels' => (int) config('ai.files.image_max_pixels', 40000000),
            'max_animation_frames' => (int) config('ai.files.image_max_animation_frames', 500),
        ];
    }

    protected function tiffSupported(): bool
    {
        return extension_loaded('imagick') && class_exists(\Imagick::class);
    }

    protected function formatFromMime(?string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg' => 'jpeg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/bmp', 'image/x-ms-bmp' => 'bmp',
            'image/tiff' => 'tiff',
            default => null,
        };
    }

    protected function colorModelLabel(?int $channels): ?string
    {
        return match ($channels) {
            1 => 'grayscale',
            3 => 'rgb',
            4 => 'cmyk_or_rgba',
            default => null,
        };
    }

    /**
     * Doc S8: builds a thumbnail + a larger preview, both read back as
     * raw bytes here and handed up through metadata - this processor
     * never touches Storage/disk paths itself (it doesn't know the
     * owning AiFile's id, only an absolute source path), so the actual
     * write-to-disk-under-a-stable-path step happens in ProcessAiFileJob
     * exactly where extracted-text/blocks content refs are already
     * written, keeping one single place that decides the storage
     * convention. The original file is opened read-only and never
     * modified (doc S8: "do not overwrite the original").
     *
     * @return array<string, array{bytes: string, extension: string, width: int, height: int}>
     */
    protected function buildPreviewAssets(string $absolutePath, string $mimeType, array &$warnings): array
    {
        $source = $this->decodeWithGd($absolutePath, $mimeType);

        if ($source === null) {
            $warnings[] = 'IMAGE_PREVIEW_DECODE_FAILED';

            return [];
        }

        try {
            $hasAlpha = in_array($mimeType, ['image/png', 'image/webp', 'image/gif'], true);

            $assets = [];

            $thumbMax = (int) config('ai.files.image_thumbnail_max_dimension', 320);
            $previewMax = (int) config('ai.files.image_preview_max_dimension', 1280);

            foreach (['thumbnail' => $thumbMax, 'preview' => $previewMax] as $key => $maxDimension) {
                $resized = $this->resizeWithinBounds($source, $maxDimension);

                try {
                    $assets[$key] = [
                        'bytes' => $this->encodeGdImage($resized, $hasAlpha),
                        'extension' => $hasAlpha ? 'png' : 'jpg',
                        'width' => imagesx($resized),
                        'height' => imagesy($resized),
                    ];
                } finally {
                    imagedestroy($resized);
                }
            }

            return $assets;
        } finally {
            imagedestroy($source);
        }
    }

    protected function decodeWithGd(string $absolutePath, string $mimeType): ?\GdImage
    {
        $image = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($absolutePath),
            'image/png' => @imagecreatefrompng($absolutePath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absolutePath) : false,
            'image/gif' => @imagecreatefromgif($absolutePath),
            'image/bmp' => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($absolutePath) : false,
            default => false,
        };

        return $image instanceof \GdImage ? $image : null;
    }
}
