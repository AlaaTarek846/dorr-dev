<?php

namespace Modules\AI\Tests\Unit\FileProcessors;

use Modules\AI\Services\FileProcessors\MarkdownFileProcessor;
use PHPUnit\Framework\TestCase;

class MarkdownFileProcessorTest extends TestCase
{
    protected MarkdownFileProcessor $processor;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new MarkdownFileProcessor;
        $this->tempDir = sys_get_temp_dir().'/ai-md-processor-test-'.uniqid();
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
        $this->assertTrue($this->processor->supports('text/markdown'));
        $this->assertFalse($this->processor->supports('text/plain'));
    }

    public function test_extracts_headings_lists_links_and_code_blocks(): void
    {
        $markdown = <<<'MD'
        # Introduction

        Some content here.

        - Item 1
        - Item 2

        ```php
        echo "hi";
        ```

        [DORR](https://dorr.example)
        MD;

        $path = $this->tempDir.'/doc.md';
        file_put_contents($path, $markdown);

        $result = $this->processor->process($path, 'text/markdown');

        $this->assertTrue($result->success);
        $types = array_column($result->blocks, 'type');

        $this->assertContains('heading', $types);
        $this->assertContains('list', $types);
        $this->assertContains('code', $types);
        $this->assertContains('link', $types);

        $heading = $result->blocks[array_search('heading', $types, true)];
        $this->assertSame(1, $heading['level']);
        $this->assertSame('Introduction', $heading['text']);
    }

    public function test_an_empty_file_fails_cleanly(): void
    {
        $path = $this->tempDir.'/empty.md';
        file_put_contents($path, '');

        $result = $this->processor->process($path, 'text/markdown');

        $this->assertFalse($result->success);
    }
}
