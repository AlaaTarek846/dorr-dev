<?php

namespace Modules\AI\Tests\Unit\FileProcessors;

use Illuminate\Support\Facades\Config;
use Modules\AI\Services\FileProcessors\RasterImageFileProcessor;
use Tests\TestCase;

/**
 * Extends the Laravel-booted Tests\TestCase because
 * RasterImageFileProcessor::process() reads several `ai.files.image_*`
 * config keys (dimension/pixel/animation-frame limits, preview
 * dimensions) - same reasoning as XmlFileProcessorTest/JsonFileProcessorTest.
 *
 * Every test that needs a real decodable image builds one with GD
 * itself at test-run time (gated by extension_loaded('gd')) rather than
 * shipping a binary fixture - this environment has no php binary to
 * generate or verify a fixture with, so the only honest way to test
 * against "a real JPEG/PNG/GIF" is to have the test build one using the
 * same GD the processor itself depends on, on whichever real machine
 * actually runs the suite.
 */
class RasterImageFileProcessorTest extends TestCase
{
    protected RasterImageFileProcessor $processor;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new RasterImageFileProcessor;
        $this->tempDir = sys_get_temp_dir().'/ai-image-processor-test-'.uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tempDir.'/*') ?: []);
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    protected function requireGd(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd is not loaded in this environment.');
        }
    }

    protected function makePng(int $width = 100, int $height = 50): string
    {
        $path = $this->tempDir.'/test-'.uniqid().'.png';
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 20, 30));
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    protected function makeJpeg(int $width = 100, int $height = 50): string
    {
        $path = $this->tempDir.'/test-'.uniqid().'.jpg';
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 100, 50));
        imagejpeg($image, $path);
        imagedestroy($image);

        return $path;
    }

    protected function makeGif(int $width = 20, int $height = 20): string
    {
        $path = $this->tempDir.'/test-'.uniqid().'.gif';
        $image = imagecreate($width, $height);
        imagecolorallocate($image, 255, 0, 0);
        imagegif($image, $path);
        imagedestroy($image);

        return $path;
    }

    public function test_supports_the_documented_raster_mime_types(): void
    {
        $this->assertTrue($this->processor->supports('image/jpeg'));
        $this->assertTrue($this->processor->supports('image/png'));
        $this->assertTrue($this->processor->supports('image/webp'));
        $this->assertTrue($this->processor->supports('image/gif'));
        $this->assertTrue($this->processor->supports('image/bmp'));
        $this->assertTrue($this->processor->supports('image/tiff'));
        $this->assertFalse($this->processor->supports('image/svg+xml'));
        $this->assertFalse($this->processor->supports('application/pdf'));
    }

    public function test_extracts_real_width_height_and_aspect_ratio_from_a_png(): void
    {
        $this->requireGd();
        $path = $this->makePng(200, 100);

        $result = $this->processor->process($path, 'image/png');

        $this->assertTrue($result->success);
        $this->assertSame(200, $result->metadata['width']);
        $this->assertSame(100, $result->metadata['height']);
        $this->assertSame(2.0, $result->metadata['aspect_ratio']);
        $this->assertSame('png', $result->metadata['format']);
        $this->assertSame('image', $result->documentType);
    }

    public function test_a_file_that_is_not_really_an_image_fails_honestly_instead_of_crashing(): void
    {
        // Doc S9: distrust the MIME/extension entirely - getimagesize()
        // on a text file claiming to be a PNG must fail cleanly.
        $path = $this->tempDir.'/fake.png';
        file_put_contents($path, 'this is just plain text, not a png');

        $result = $this->processor->process($path, 'image/png');

        $this->assertFalse($result->success);
        $this->assertSame('IMAGE_INVALID', $result->error);
    }

    public function test_dimensions_over_the_configured_limit_are_rejected(): void
    {
        $this->requireGd();
        Config::set('ai.files.image_max_width', 50);

        $path = $this->makePng(200, 50);

        $result = $this->processor->process($path, 'image/png');

        $this->assertFalse($result->success);
        $this->assertSame('IMAGE_DIMENSIONS_TOO_LARGE', $result->error);
    }

    public function test_pixel_count_over_the_configured_limit_is_rejected_even_within_width_height_bounds(): void
    {
        // Doc S6/S9: decompression-bomb protection - a 100x100 image is
        // within normal width/height bounds individually, but its pixel
        // COUNT can still be capped independently.
        $this->requireGd();
        Config::set('ai.files.image_max_pixels', 1000);

        $path = $this->makePng(100, 100);

        $result = $this->processor->process($path, 'image/png');

        $this->assertFalse($result->success);
        $this->assertSame('IMAGE_PIXEL_LIMIT_EXCEEDED', $result->error);
    }

    public function test_tiff_without_imagick_fails_honestly_rather_than_claiming_support(): void
    {
        if (extension_loaded('imagick') && class_exists(\Imagick::class)) {
            $this->markTestSkipped('Imagick is installed in this environment, so TIFF is genuinely supported here.');
        }

        $path = $this->tempDir.'/fake.tiff';
        file_put_contents($path, 'irrelevant - rejected before any decode attempt');

        $result = $this->processor->process($path, 'image/tiff');

        $this->assertFalse($result->success);
        $this->assertSame('IMAGE_TIFF_REQUIRES_IMAGICK', $result->error);
    }

    public function test_a_static_gif_is_not_flagged_as_animated(): void
    {
        $this->requireGd();
        $path = $this->makeGif();

        $result = $this->processor->process($path, 'image/gif');

        $this->assertTrue($result->success);
        $this->assertFalse($result->metadata['animated']);
        $this->assertSame(1, $result->metadata['frame_count']);
    }

    public function test_generates_a_thumbnail_and_preview_within_configured_bounds_and_preserves_aspect_ratio(): void
    {
        $this->requireGd();
        Config::set('ai.files.image_thumbnail_max_dimension', 10);
        Config::set('ai.files.image_preview_max_dimension', 40);

        $path = $this->makeJpeg(200, 100);

        $result = $this->processor->process($path, 'image/jpeg');

        $this->assertTrue($result->success);
        $this->assertArrayHasKey('preview_assets', $result->metadata);

        $thumb = $result->metadata['preview_assets']['thumbnail'];
        $preview = $result->metadata['preview_assets']['preview'];

        // Doc S8: aspect ratio preserved (2:1 source -> 2:1 output on
        // both the longest-side-bounded thumbnail and preview).
        $this->assertLessThanOrEqual(10, max($thumb['width'], $thumb['height']));
        $this->assertEqualsWithDelta(2.0, $thumb['width'] / $thumb['height'], 0.2);

        $this->assertLessThanOrEqual(40, max($preview['width'], $preview['height']));
        $this->assertEqualsWithDelta(2.0, $preview['width'] / $preview['height'], 0.2);

        // Doc S8: never upscaled past the source's own size.
        $this->assertLessThanOrEqual(200, $preview['width']);
        $this->assertLessThanOrEqual(100, $preview['height']);

        $this->assertNotEmpty($thumb['bytes']);
        $this->assertNotEmpty($preview['bytes']);
    }

    public function test_the_original_file_on_disk_is_never_modified_by_preview_generation(): void
    {
        $this->requireGd();
        $path = $this->makeJpeg(60, 40);
        $original = file_get_contents($path);

        $this->processor->process($path, 'image/jpeg');

        $this->assertSame($original, file_get_contents($path));
    }

    public function test_exif_is_categorized_when_the_exif_extension_is_available(): void
    {
        if (! extension_loaded('exif') || ! function_exists('exif_read_data')) {
            $this->markTestSkipped('ext-exif is not loaded in this environment.');
        }

        $this->requireGd();
        $path = $this->makeJpeg();

        $result = $this->processor->process($path, 'image/jpeg');

        $this->assertTrue($result->success);
        $this->assertArrayHasKey('exif', $result->metadata);
        $this->assertArrayHasKey('technical', $result->metadata['exif']);
        $this->assertArrayHasKey('ai_safe', $result->metadata['exif']);
        $this->assertArrayHasKey('sensitive', $result->metadata['exif']);
    }

    public function test_a_mime_declared_as_bmp_but_decoded_as_png_produces_a_mismatch_warning_not_a_hard_failure(): void
    {
        // Doc S9: the AiFileEngine real-MIME check already ran before
        // this processor is reached - this is a second, independent
        // confirmation at the pixel-decoder level, and it is
        // intentionally a soft warning rather than an automatic
        // rejection (see the processor's own docblock for why).
        $this->requireGd();
        $path = $this->makePng();

        $result = $this->processor->process($path, 'image/bmp');

        $this->assertTrue($result->success);
        $this->assertContains('IMAGE_MIME_MISMATCH_WARNING', $result->warnings);
    }
}
