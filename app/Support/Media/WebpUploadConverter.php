<?php

namespace App\Support\Media;

use Illuminate\Http\UploadedFile;

/**
 * Replaces a raster upload with a WebP copy so the original JPEG/PNG is not stored.
 * GIF, SVG, ICO, already-WebP, and non-images are left unchanged.
 */
final class WebpUploadConverter
{
    private const QUALITY = 80;

    /** @var list<string> */
    private const CONVERTIBLE = ['jpg', 'jpeg', 'png', 'bmp'];

    public static function convert(UploadedFile $file): UploadedFile
    {
        if (! self::shouldConvert($file)) {
            return $file;
        }

        $source = $file->getRealPath();

        if ($source === false || ! is_readable($source)) {
            return $file;
        }

        $image = @imagecreatefromstring((string) file_get_contents($source));

        if ($image === false) {
            return $file;
        }

        if (function_exists('imagepalettetotruecolor') && ! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        imagealphablending($image, true);
        imagesavealpha($image, true);

        $temp = tempnam(sys_get_temp_dir(), 'dorr-webp-');

        if ($temp === false) {
            imagedestroy($image);

            return $file;
        }

        $ok = imagewebp($image, $temp, self::QUALITY);
        imagedestroy($image);

        if ($ok !== true || ! is_file($temp) || filesize($temp) === 0) {
            @unlink($temp);

            return $file;
        }

        register_shutdown_function(static function () use ($temp): void {
            if (is_file($temp)) {
                @unlink($temp);
            }
        });

        $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'image';

        return new UploadedFile($temp, $name.'.webp', 'image/webp', null, true);
    }

    public static function shouldConvert(UploadedFile $file): bool
    {
        if (! function_exists('imagewebp') || ! function_exists('imagecreatefromstring')) {
            return false;
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');

        return in_array($extension, self::CONVERTIBLE, true);
    }
}
