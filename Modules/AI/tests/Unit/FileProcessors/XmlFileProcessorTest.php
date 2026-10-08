<?php

namespace Modules\AI\Tests\Unit\FileProcessors;

use Modules\AI\Services\FileProcessors\XmlFileProcessor;
use Tests\TestCase;

/**
 * Extends the Laravel-booted Tests\TestCase because
 * XmlFileProcessor::process() reads config('ai.files.xml_max_nodes'),
 * same reasoning as JsonFileProcessorTest.
 *
 * Doc S22/S38: these tests exist specifically to prove XXE/entity
 * payloads are rejected outright, not merely that parsing "works".
 */
class XmlFileProcessorTest extends TestCase
{
    protected XmlFileProcessor $processor;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new XmlFileProcessor;
        $this->tempDir = sys_get_temp_dir().'/ai-xml-processor-test-'.uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tempDir.'/*') ?: []);
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    public function test_supports_the_documented_mime_type(): void
    {
        $this->assertTrue($this->processor->supports('application/xml'));
        $this->assertFalse($this->processor->supports('text/plain'));
    }

    public function test_preserves_hierarchy_as_path_value_blocks(): void
    {
        $xml = '<products><product><name>Phone</name><price>15000</price></product></products>';
        $path = $this->tempDir.'/products.xml';
        file_put_contents($path, $xml);

        $result = $this->processor->process($path, 'application/xml');

        $this->assertTrue($result->success);
        $paths = array_column($result->blocks, 'path');

        $this->assertContains('/products/product/name', $paths);
        $this->assertContains('/products/product/price', $paths);
    }

    public function test_an_xxe_payload_is_rejected_outright(): void
    {
        $xxe = '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><foo>&xxe;</foo>';
        $path = $this->tempDir.'/xxe.xml';
        file_put_contents($path, $xxe);

        $result = $this->processor->process($path, 'application/xml');

        $this->assertFalse($result->success);
        $this->assertSame('XML_SECURITY_VIOLATION', $result->error);
    }

    public function test_a_billion_laughs_style_payload_is_rejected_outright(): void
    {
        $payload = '<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;">]><lolz>&lol2;</lolz>';
        $path = $this->tempDir.'/lolz.xml';
        file_put_contents($path, $payload);

        $result = $this->processor->process($path, 'application/xml');

        $this->assertFalse($result->success);
        $this->assertSame('XML_SECURITY_VIOLATION', $result->error);
    }

    public function test_malformed_xml_fails_cleanly_not_a_crash(): void
    {
        $path = $this->tempDir.'/broken.xml';
        file_put_contents($path, '<products><product>');

        $result = $this->processor->process($path, 'application/xml');

        $this->assertFalse($result->success);
        $this->assertSame('XML_INVALID', $result->error);
    }
}
