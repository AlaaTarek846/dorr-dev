<?php

namespace Modules\AI\Tests\Unit\FileProcessors;

use Modules\AI\Services\FileProcessors\SvgImageFileProcessor;
use PHPUnit\Framework\TestCase;

/**
 * Plain PHPUnit\Framework\TestCase - SvgImageFileProcessor::process()
 * never calls config(), unlike RasterImageFileProcessor.
 *
 * Doc S7/S22/S38: these tests exist specifically to prove the security
 * scan actually detects script/event-handler/external-reference/XXE
 * payloads - not merely that a well-formed SVG "works".
 */
class SvgImageFileProcessorTest extends TestCase
{
    protected SvgImageFileProcessor $processor;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new SvgImageFileProcessor;
        $this->tempDir = sys_get_temp_dir().'/ai-svg-processor-test-'.uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tempDir.'/*') ?: []);
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    protected function writeSvg(string $contents): string
    {
        $path = $this->tempDir.'/test-'.uniqid().'.svg';
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_supports_only_svg(): void
    {
        $this->assertTrue($this->processor->supports('image/svg+xml'));
        $this->assertFalse($this->processor->supports('image/png'));
    }

    public function test_a_benign_svg_is_classified_safe_with_correct_dimensions(): void
    {
        $path = $this->writeSvg('<svg xmlns="http://www.w3.org/2000/svg" width="120" height="80"><circle cx="60" cy="40" r="30" fill="blue"/></svg>');

        $result = $this->processor->process($path, 'image/svg+xml');

        $this->assertTrue($result->success);
        $this->assertSame(120.0, $result->metadata['width']);
        $this->assertSame(80.0, $result->metadata['height']);
        $this->assertTrue($result->metadata['security']['safe']);
        $this->assertFalse($result->metadata['security']['has_script']);
        $this->assertFalse($result->metadata['security']['has_event_handlers']);
        $this->assertFalse($result->metadata['security']['has_external_references']);

        // Doc S7: SVG is never rasterized - this must be explicit, never
        // silently absent.
        $this->assertFalse($result->metadata['preview_available']);
        $this->assertContains('IMAGE_SVG_NOT_RASTERIZED', $result->warnings);
    }

    public function test_an_svg_with_an_inline_script_tag_is_flagged_unsafe(): void
    {
        $path = $this->writeSvg('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>');

        $result = $this->processor->process($path, 'image/svg+xml');

        $this->assertTrue($result->success);
        $this->assertFalse($result->metadata['security']['safe']);
        $this->assertTrue($result->metadata['security']['has_script']);
        $this->assertContains('IMAGE_SVG_UNSAFE', $result->warnings);
    }

    public function test_an_svg_with_an_onload_event_handler_is_flagged_unsafe(): void
    {
        $path = $this->writeSvg('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="10" height="10"/></svg>');

        $result = $this->processor->process($path, 'image/svg+xml');

        $this->assertTrue($result->success);
        $this->assertFalse($result->metadata['security']['safe']);
        $this->assertTrue($result->metadata['security']['has_event_handlers']);
        $this->assertContains('onload', $result->metadata['security']['event_attributes_found']);
    }

    public function test_an_svg_referencing_an_external_http_resource_is_flagged_unsafe(): void
    {
        // Doc S7: SSRF/external-resource-loading concern.
        $path = $this->writeSvg('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><image xlink:href="http://attacker.example/pixel.png" width="1" height="1"/></svg>');

        $result = $this->processor->process($path, 'image/svg+xml');

        $this->assertTrue($result->success);
        $this->assertFalse($result->metadata['security']['safe']);
        $this->assertTrue($result->metadata['security']['has_external_references']);
        $this->assertContains('http://attacker.example/pixel.png', $result->metadata['security']['external_references']);
    }

    public function test_an_svg_referencing_a_same_document_fragment_is_not_flagged_as_external(): void
    {
        $path = $this->writeSvg('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><defs><circle id="dot" r="5"/></defs><use xlink:href="#dot"/></svg>');

        $result = $this->processor->process($path, 'image/svg+xml');

        $this->assertTrue($result->success);
        $this->assertTrue($result->metadata['security']['safe']);
        $this->assertFalse($result->metadata['security']['has_external_references']);
    }

    public function test_an_svg_with_a_foreignobject_embedding_html_is_flagged_unsafe(): void
    {
        $path = $this->writeSvg('<svg xmlns="http://www.w3.org/2000/svg"><foreignObject width="100" height="100"><div xmlns="http://www.w3.org/1999/xhtml">hi</div></foreignObject></svg>');

        $result = $this->processor->process($path, 'image/svg+xml');

        $this->assertTrue($result->success);
        $this->assertFalse($result->metadata['security']['safe']);
        $this->assertTrue($result->metadata['security']['has_embedded_html']);
    }

    public function test_an_svg_with_an_entity_declaration_is_rejected_outright_as_an_xxe_attempt(): void
    {
        // Same XXE/entity-expansion rejection as XmlFileProcessor - doc
        // S22: this must never reach libxml at all.
        $path = $this->writeSvg(
            '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            .'<svg xmlns="http://www.w3.org/2000/svg"><title>&xxe;</title></svg>'
        );

        $result = $this->processor->process($path, 'image/svg+xml');

        $this->assertFalse($result->success);
        $this->assertSame('IMAGE_SVG_UNSAFE', $result->error);
    }

    public function test_a_file_that_is_not_valid_xml_at_all_fails_cleanly(): void
    {
        $path = $this->writeSvg('this is not xml at all { } < >');

        $result = $this->processor->process($path, 'image/svg+xml');

        $this->assertFalse($result->success);
        $this->assertSame('IMAGE_INVALID', $result->error);
    }

    public function test_well_formed_xml_that_is_not_an_svg_root_element_is_rejected(): void
    {
        $path = $this->writeSvg('<?xml version="1.0"?><notsvg><child/></notsvg>');

        $result = $this->processor->process($path, 'image/svg+xml');

        $this->assertFalse($result->success);
        $this->assertSame('IMAGE_INVALID', $result->error);
    }

    public function test_never_produces_preview_assets_for_any_svg(): void
    {
        $path = $this->writeSvg('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>');

        $result = $this->processor->process($path, 'image/svg+xml');

        $this->assertTrue($result->success);
        $this->assertArrayNotHasKey('preview_assets', $result->metadata);
    }
}
