<?php

namespace Modules\AI\Tests\Unit\FileProcessors;

use Modules\AI\Services\AiDocumentTextExtractor;
use Modules\AI\Services\FileProcessors\PdfFileProcessor;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2: proves the honest degrade path (see PdfFileProcessor's own
 * docblock) when smalot/pdfparser is not installed - which is this
 * project's actual current state. The real page-aware/metadata/
 * scanned-detection path (processWithSmalot()) is only exercised once
 * that dependency is actually added - this test skips itself rather
 * than faking that dependency's presence, consistent with this phase's
 * own "never fake file support" rule.
 */
class PdfFileProcessorTest extends TestCase
{
    protected PdfFileProcessor $processor;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new PdfFileProcessor(new AiDocumentTextExtractor);
        $this->tempDir = sys_get_temp_dir().'/ai-pdf-processor-test-'.uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tempDir.'/*') ?: []);
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    public function test_supports_only_pdf(): void
    {
        $this->assertTrue($this->processor->supports('application/pdf'));
        $this->assertFalse($this->processor->supports('text/plain'));
    }

    public function test_without_smalot_a_real_pdf_degrades_to_one_whole_document_block_with_honest_warnings(): void
    {
        if (class_exists(\Smalot\PdfParser\Parser::class)) {
            $this->markTestSkipped('smalot/pdfparser is installed - the page-aware path is covered by its own test instead.');
        }

        $path = $this->tempDir.'/note.pdf';
        file_put_contents($path, $this->minimalPdfWithText('Contract terms and conditions.'));

        $result = $this->processor->process($path, 'application/pdf');

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Contract terms and conditions.', (string) $result->text);
        $this->assertCount(1, $result->blocks);
        $this->assertNull($result->blocks[0]['source']['page']);
        $this->assertContains('page_boundaries_unavailable_install_smalot_pdfparser', $result->warnings);
    }

    public function test_a_corrupted_pdf_fails_cleanly_instead_of_throwing(): void
    {
        $path = $this->tempDir.'/broken.pdf';
        file_put_contents($path, 'not a pdf at all');

        $result = $this->processor->process($path, 'application/pdf');

        $this->assertFalse($result->success);
    }

    protected function minimalPdfWithText(string $text): string
    {
        $stream = "BT /F1 12 Tf 72 712 Td ({$text}) Tj ET";

        return "%PDF-1.4\n"
            ."1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
            ."2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
            ."3 0 obj << /Type /Page /Parent 2 0 R /Contents 4 0 R >> endobj\n"
            ."4 0 obj << /Length ".strlen($stream)." >> stream\n{$stream}\nendstream endobj\n"
            ."trailer << /Root 1 0 R >>\n%%EOF";
    }
}
