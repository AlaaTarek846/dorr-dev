<?php

namespace Modules\AI\Tests\Unit\FileProcessors;

use Modules\AI\Services\FileProcessors\Concerns\AnalyzesImageData;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic coverage for the shared Phase 5 trait, independent of any
 * one processor and independent of the Laravel container (none of these
 * methods call config()).
 */
class AnalyzesImageDataTest extends TestCase
{
    protected object $subject;

    protected function setUp(): void
    {
        parent::setUp();
        // Widen the trait's protected helpers to public (same-name wrappers recurse forever).
        $this->subject = new class
        {
            use AnalyzesImageData {
                categorizeExif as public;
                countGifFrames as public;
                isAnimatedWebp as public;
            }
        };
    }

    public function test_categorizes_orientation_as_technical_and_ai_safe(): void
    {
        $result = $this->subject->categorizeExif(['Orientation' => 6]);

        $this->assertSame(6, $result['technical']['orientation']);
        $this->assertSame(6, $result['ai_safe']['orientation']);
        $this->assertArrayNotHasKey('orientation', $result['sensitive']);
    }

    public function test_categorizes_camera_make_and_model_as_technical_but_not_ai_safe(): void
    {
        // Doc S5: device identifiers can fingerprint a specific owner's
        // equipment - technical, but deliberately not promoted to
        // ai_safe (see AnalyzesImageData::categorizeExif's own
        // docblock for the reasoning).
        $result = $this->subject->categorizeExif([
            'Make' => 'Canon',
            'Model' => 'EOS R5',
            'Software' => 'Lightroom 13.0',
        ]);

        $this->assertSame('Canon', $result['technical']['make']);
        $this->assertSame('EOS R5', $result['technical']['model']);
        $this->assertSame('Lightroom 13.0', $result['technical']['software']);
        $this->assertArrayNotHasKey('make', $result['ai_safe']);
        $this->assertArrayNotHasKey('model', $result['ai_safe']);
    }

    public function test_categorizes_timestamps_as_sensitive(): void
    {
        $result = $this->subject->categorizeExif(['DateTimeOriginal' => '2026:01:15 10:30:00']);

        $this->assertSame('2026:01:15 10:30:00', $result['sensitive']['datetimeoriginal']);
        $this->assertArrayNotHasKey('datetimeoriginal', $result['technical']);
        $this->assertArrayNotHasKey('datetimeoriginal', $result['ai_safe']);
    }

    public function test_categorizes_gps_coordinates_as_sensitive_and_decodes_them_correctly(): void
    {
        // Doc S5: GPS must never be auto-sent to AI - this proves it
        // lands only in `sensitive`, never in `technical`/`ai_safe`,
        // and that the degrees/minutes/seconds -> decimal conversion
        // (including the southern/western-hemisphere sign flip) is
        // actually correct, not merely "doesn't crash".
        $result = $this->subject->categorizeExif([
            'GPSLatitude' => ['40/1', '26/1', '46/1'],
            'GPSLatitudeRef' => 'N',
            'GPSLongitude' => ['79/1', '58/1', '56/1'],
            'GPSLongitudeRef' => 'W',
        ]);

        $this->assertArrayHasKey('gps', $result['sensitive']);
        $this->assertArrayNotHasKey('gps', $result['technical']);
        $this->assertArrayNotHasKey('gps', $result['ai_safe']);

        $this->assertEqualsWithDelta(40.4461, $result['sensitive']['gps']['latitude'], 0.001);
        // West -> negative.
        $this->assertEqualsWithDelta(-79.9822, $result['sensitive']['gps']['longitude'], 0.001);
    }

    public function test_no_gps_data_produces_no_gps_key(): void
    {
        $result = $this->subject->categorizeExif(['Orientation' => 1]);

        $this->assertArrayNotHasKey('gps', $result['sensitive']);
    }

    public function test_counts_gif_graphic_control_extension_blocks_as_frames(): void
    {
        // Doc S10: a heuristic, documented as such - this proves the
        // counting mechanism itself (byte-signature occurrences), not
        // that it matches every encoder's exact frame count.
        $singleFrame = "GIF89a".str_repeat("\x00", 10);
        $this->assertSame(1, $this->subject->countGifFrames($singleFrame));

        $threeFrames = "GIF89a"."\x21\xF9\x04"."\x00\x00\x00\x00"."\x21\xF9\x04"."\x00\x00\x00\x00"."\x21\xF9\x04"."\x00\x00\x00\x00";
        $this->assertSame(3, $this->subject->countGifFrames($threeFrames));
    }

    public function test_a_static_gif_with_no_gce_blocks_still_reports_at_least_one_frame(): void
    {
        $this->assertSame(1, $this->subject->countGifFrames('not a real gif at all'));
    }

    public function test_detects_the_anim_riff_chunk_for_animated_webp(): void
    {
        $animated = "RIFF\x00\x00\x00\x00WEBPVP8X\x00\x00\x00\x00ANIM\x00\x00\x00\x00";
        $static = "RIFF\x00\x00\x00\x00WEBPVP8 \x00\x00\x00\x00";

        $this->assertTrue($this->subject->isAnimatedWebp($animated));
        $this->assertFalse($this->subject->isAnimatedWebp($static));
    }
}
