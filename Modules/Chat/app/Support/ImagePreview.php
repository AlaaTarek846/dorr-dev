<?php

namespace Modules\Chat\Support;

use Illuminate\Http\UploadedFile;

/**
 * A chat photo's size and a tiny preview of it (a ~40px JPEG, base64, under 1KB): what the app
 * shows while data saver holds the photo back (spec 82), and the bubble's real shape.
 */
final class ImagePreview
{
    private const SIDE = 40;

    /** Bigger than this isn't decoded just for a preview (memory). */
    private const MAX_PIXELS = 40_000_000;

    /**
     * @return array{width?: int, height?: int, preview?: string}
     */
    public static function of(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if ($path === false || ! str_starts_with((string) $file->getMimeType(), 'image/')) {
            return [];
        }

        $size = @getimagesize($path);
        if ($size === false || $size[0] <= 0 || $size[1] <= 0) {
            return [];
        }
        $props = ['width' => (int) $size[0], 'height' => (int) $size[1]];

        if ($size[0] * $size[1] > self::MAX_PIXELS || ! function_exists('imagecreatefromstring')) {
            return $props;
        }

        $image = @imagecreatefromstring((string) file_get_contents($path));
        if ($image === false) {
            return $props;
        }
        $scale = self::SIDE / max($size[0], $size[1]);
        $small = imagescale($image, max(1, (int) round($size[0] * $scale)), max(1, (int) round($size[1] * $scale)));
        imagedestroy($image);
        if ($small === false) {
            return $props;
        }

        ob_start();
        imagejpeg($small, null, 50);
        $jpeg = ob_get_clean();
        imagedestroy($small);

        if (is_string($jpeg) && $jpeg !== '') {
            $props['preview'] = base64_encode($jpeg);
        }

        return $props;
    }
}
