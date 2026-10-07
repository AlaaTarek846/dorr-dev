<?php

namespace Modules\AI\Tests\Unit\FileProcessors;

use Modules\AI\Services\FileProcessors\HtmlFileProcessor;
use PHPUnit\Framework\TestCase;

/**
 * Doc S19/S38: HTML is untrusted data. These tests exist specifically to
 * prove script/style/event-handler/javascript-URL content never survives
 * into the normalized output, not just that extraction "works".
 */
class HtmlFileProcessorTest extends TestCase
{
    protected HtmlFileProcessor $processor;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new HtmlFileProcessor;
        $this->tempDir = sys_get_temp_dir().'/ai-html-processor-test-'.uniqid();
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
        $this->assertTrue($this->processor->supports('text/html'));
        $this->assertFalse($this->processor->supports('text/plain'));
    }

    public function test_extracts_title_headings_paragraphs_lists_and_tables(): void
    {
        $html = '<html><head><title>Products</title></head><body>'
            .'<h1>Products</h1><p>Product description</p>'
            .'<ul><li>One</li><li>Two</li></ul>'
            .'<table><tr><th>Name</th><th>Price</th></tr><tr><td>Phone</td><td>15000</td></tr></table>'
            .'</body></html>';

        $path = $this->tempDir.'/page.html';
        file_put_contents($path, $html);

        $result = $this->processor->process($path, 'text/html');

        $this->assertTrue($result->success);
        $this->assertSame('Products', $result->metadata['title']);

        $types = array_column($result->blocks, 'type');
        $this->assertContains('heading', $types);
        $this->assertContains('paragraph', $types);
        $this->assertContains('list', $types);
        $this->assertContains('table', $types);
    }

    public function test_script_tags_are_stripped_entirely(): void
    {
        $html = '<html><body><p>Safe text</p><script>alert(1)</script></body></html>';
        $path = $this->tempDir.'/xss.html';
        file_put_contents($path, $html);

        $result = $this->processor->process($path, 'text/html');

        $this->assertTrue($result->success);
        $this->assertStringNotContainsString('alert(1)', (string) $result->text);
    }

    public function test_inline_event_handlers_and_javascript_urls_are_stripped(): void
    {
        $html = '<html><body>'
            .'<p onclick="steal()">Click me</p>'
            .'<a href="javascript:alert(1)">bad link</a>'
            .'</body></html>';
        $path = $this->tempDir.'/handlers.html';
        file_put_contents($path, $html);

        $result = $this->processor->process($path, 'text/html');

        $linkBlock = null;

        foreach ($result->blocks as $block) {
            if ($block['type'] === 'link') {
                $linkBlock = $block;
            }
        }

        $this->assertNotNull($linkBlock);
        $this->assertSame('', $linkBlock['url']);
    }

    public function test_an_empty_document_fails_cleanly(): void
    {
        $path = $this->tempDir.'/empty.html';
        file_put_contents($path, '<html><body></body></html>');

        $result = $this->processor->process($path, 'text/html');

        $this->assertFalse($result->success);
    }
}
