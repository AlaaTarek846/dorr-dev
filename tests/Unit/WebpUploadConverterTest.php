<?php

namespace Tests\Unit;

use App\Support\Media\WebpUploadConverter;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class WebpUploadConverterTest extends TestCase
{
    public function test_jpeg_and_png_become_webp_when_gd_supports_it(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('ext-gd was built without WebP');
        }

        $jpeg = WebpUploadConverter::convert(UploadedFile::fake()->image('photo.jpg', 120, 80));
        $this->assertSame('image/webp', $jpeg->getMimeType());
        $this->assertSame('photo.webp', $jpeg->getClientOriginalName());

        $png = WebpUploadConverter::convert(UploadedFile::fake()->image('logo.png', 80, 80));
        $this->assertSame('logo.webp', $png->getClientOriginalName());
    }

    public function test_gif_svg_and_webp_are_not_converted(): void
    {
        $gif = UploadedFile::fake()->create('anim.gif', 20, 'image/gif');
        $this->assertSame($gif, WebpUploadConverter::convert($gif));

        $svg = UploadedFile::fake()->create('logo.svg', 20, 'image/svg+xml');
        $this->assertSame($svg, WebpUploadConverter::convert($svg));

        $webp = UploadedFile::fake()->create('already.webp', 20, 'image/webp');
        $this->assertSame($webp, WebpUploadConverter::convert($webp));
    }
}
