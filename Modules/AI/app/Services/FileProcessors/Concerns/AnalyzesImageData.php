<?php

namespace Modules\AI\Services\FileProcessors\Concerns;

/**
 * Phase 5: shared, format-agnostic image analysis - EXIF
 * categorization, animation-frame heuristics, and GD-based resizing -
 * used by RasterImageFileProcessor. SvgImageFileProcessor does not use
 * this trait at all (SVG has none of GD's raster concerns - see that
 * processor's own docblock).
 */
trait AnalyzesImageData
{
    /**
     * Doc S5: never exposes raw EXIF wholesale. Splits it into three
     * buckets so a caller (and, eventually, whatever assembles AI
     * context) can make an explicit choice about what crosses into a
     * model prompt, rather than everything riding along by default:
     *
     * - `technical`: dimension/orientation/camera-model-class facts that
     *   are useful and not meaningfully private on their own.
     * - `ai_safe`: a conservative subset of `technical` this phase is
     *   comfortable saying is fine for an AI context if one is ever
     *   built (orientation only, for now - see docblock for why camera
     *   make/model/software are NOT included here even though they are
     *   not GPS/personal-identity data: they can still fingerprint a
     *   specific device/owner, which is a privacy-relevant fact a file
     *   parser should not casually decide is "safe for AI" on its own).
     * - `sensitive`: GPS coordinates, timestamps, device/software
     *   identifiers - present in the normalized metadata for the
     *   record, but doc S5 explicitly says these must never be
     *   automatically sent to an AI model.
     *
     * @param  array<string, mixed>  $exif  Raw output of exif_read_data().
     * @return array{technical: array<string, mixed>, ai_safe: array<string, mixed>, sensitive: array<string, mixed>}
     */
    protected function categorizeExif(array $exif): array
    {
        $technical = [];
        $aiSafe = [];
        $sensitive = [];

        if (isset($exif['Orientation'])) {
            $technical['orientation'] = (int) $exif['Orientation'];
            $aiSafe['orientation'] = (int) $exif['Orientation'];
        }

        foreach (['Make', 'Model', 'Software'] as $key) {
            if (! empty($exif[$key])) {
                // Device/software identifiers can fingerprint a specific
                // owner's equipment - technical, but deliberately not
                // promoted to ai_safe (see docblock above).
                $technical[strtolower($key)] = (string) $exif[$key];
            }
        }

        foreach (['DateTimeOriginal', 'DateTimeDigitized', 'DateTime'] as $key) {
            if (! empty($exif[$key])) {
                $sensitive[strtolower($key)] = (string) $exif[$key];
            }
        }

        $gps = $this->extractGpsCoordinates($exif);

        if ($gps !== null) {
            $sensitive['gps'] = $gps;
        }

        return ['technical' => $technical, 'ai_safe' => $aiSafe, 'sensitive' => $sensitive];
    }

    /**
     * @param  array<string, mixed>  $exif
     * @return array{latitude: float, longitude: float}|null
     */
    protected function extractGpsCoordinates(array $exif): ?array
    {
        if (! isset($exif['GPSLatitude'], $exif['GPSLongitude'], $exif['GPSLatitudeRef'], $exif['GPSLongitudeRef'])) {
            return null;
        }

        try {
            $lat = $this->gpsToDecimal((array) $exif['GPSLatitude'], (string) $exif['GPSLatitudeRef']);
            $lon = $this->gpsToDecimal((array) $exif['GPSLongitude'], (string) $exif['GPSLongitudeRef']);

            return ['latitude' => $lat, 'longitude' => $lon];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, mixed>  $coordinate  [degrees, minutes, seconds], each as an EXIF rational "n/d" string.
     */
    protected function gpsToDecimal(array $coordinate, string $hemisphere): float
    {
        $toFloat = function (mixed $part): float {
            if (is_string($part) && str_contains($part, '/')) {
                [$num, $den] = array_map('floatval', explode('/', $part, 2));

                return $den != 0.0 ? $num / $den : 0.0;
            }

            return (float) $part;
        };

        $degrees = $toFloat($coordinate[0] ?? 0);
        $minutes = $toFloat($coordinate[1] ?? 0);
        $seconds = $toFloat($coordinate[2] ?? 0);

        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

        return in_array(strtoupper($hemisphere), ['S', 'W'], true) ? -$decimal : $decimal;
    }

    /**
     * Doc S10: GIF has no single "frame count" field - this counts
     * Graphic Control Extension blocks (byte signature `21 F9 04`),
     * which real-world GIF encoders emit once per animation frame. This
     * is a well-known heuristic, not a full GIF89a parser, and is
     * documented as such - a hand-crafted GIF that omits these blocks
     * would under-count, but would also not animate in a normal viewer
     * either.
     */
    protected function countGifFrames(string $bytes): int
    {
        $count = substr_count($bytes, "\x21\xF9\x04");

        return max($count, 1);
    }

    /**
     * Doc S10: an animated WEBP's RIFF container includes an `ANIM`
     * chunk (the VP8X extended-format header's animation flag plus a
     * dedicated ANIM chunk) that a plain static WEBP never has - a
     * simple, reliable substring check against the chunk FourCC.
     */
    protected function isAnimatedWebp(string $bytes): bool
    {
        return str_contains(substr($bytes, 0, 4096), 'ANIM');
    }

    /**
     * Doc S8: resize preserving aspect ratio, never upscaling past the
     * source's own size. Returns a NEW GD image resource/object the
     * caller is responsible for destroying - the source image is left
     * untouched (doc S8: "do not overwrite the original").
     */
    protected function resizeWithinBounds(\GdImage $source, int $maxDimension): \GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $longestSide = max($width, $height);

        if ($longestSide <= $maxDimension) {
            $copy = imagecreatetruecolor($width, $height);
            imagealphablending($copy, false);
            imagesavealpha($copy, true);
            imagecopy($copy, $source, 0, 0, 0, 0, $width, $height);

            return $copy;
        }

        $scale = $maxDimension / $longestSide;
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $resized;
    }

    /**
     * Doc S8: PNG for anything that may carry transparency (preserves
     * alpha losslessly), JPEG otherwise (smaller, no alpha to lose).
     */
    protected function encodeGdImage(\GdImage $image, bool $preserveAlpha): string
    {
        ob_start();

        if ($preserveAlpha) {
            imagepng($image, null, 6);
        } else {
            imagejpeg($image, null, 85);
        }

        return (string) ob_get_clean();
    }
}
