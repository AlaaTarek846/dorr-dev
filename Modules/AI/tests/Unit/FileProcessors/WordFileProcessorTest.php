<?php

namespace Modules\AI\Tests\Unit\FileProcessors;

use Modules\AI\Services\FileProcessors\WordFileProcessor;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\TestCase;

/**
 * Proves WordFileProcessor genuinely extracts structured content (not
 * just raw text) from a real DOCX file built with PhpWord itself - this
 * is the real replacement for the old hand-rolled ZipArchive/
 * word-document.xml reader, which only ever produced a flat text dump.
 */
class WordFileProcessorTest extends TestCase
{
    protected WordFileProcessor $processor;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new WordFileProcessor;
        $this->tempDir = sys_get_temp_dir().'/ai-word-processor-test-'.uniqid();
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
        $this->assertTrue($this->processor->supports('application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
        $this->assertTrue($this->processor->supports('application/msword'));
        $this->assertFalse($this->processor->supports('application/pdf'));
    }

    /**
     * Phase 2 doc S15: legacy .doc must use its own dedicated reader
     * (phpword's bundled MsDoc), never silently pretend the DOCX reader
     * can open it - and a file that is not a real legacy .doc binary
     * must fail cleanly, not throw.
     */
    public function test_a_file_that_is_not_a_real_legacy_doc_binary_fails_cleanly(): void
    {
        $path = $this->tempDir.'/broken.doc';
        file_put_contents($path, 'not a real legacy doc file at all');

        $result = $this->processor->process($path, 'application/msword');

        $this->assertFalse($result->success);
        $this->assertSame('DOC_PROCESSOR_UNAVAILABLE', $result->error);
    }

    public function test_extracts_headings_paragraphs_and_tables_from_a_real_docx(): void
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        // Real Word files mark headings with a paragraph style id ("Heading1"); PhpWord's own addTitle()
        // writes no style at all, so the fixture uses the style like Word does.
        $phpWord->addParagraphStyle('Heading1', ['spaceAfter' => 100]);
        $phpWord->addParagraphStyle('Heading2', ['spaceAfter' => 100]);
        $section->addText('Company Policy', null, 'Heading1');
        $section->addText('All employees must read this policy.');
        $section->addText('Scope', null, 'Heading2');

        $table = $section->addTable();
        $table->addRow();
        $table->addCell(2000)->addText('Name');
        $table->addCell(2000)->addText('Role');
        $table->addRow();
        $table->addCell(2000)->addText('Ali');
        $table->addCell(2000)->addText('Developer');

        $path = $this->tempDir.'/policy.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        $result = $this->processor->process($path, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->assertTrue($result->success);
        $this->assertStringContainsString('# Company Policy', $result->text);
        $this->assertStringContainsString('## Scope', $result->text);
        $this->assertStringContainsString('All employees must read this policy.', $result->text);
        $this->assertStringContainsString('Name | Role', $result->text);
        $this->assertStringContainsString('Ali | Developer', $result->text);
        $this->assertSame(1, $result->metadata['table_count']);
    }

    public function test_returns_failure_for_an_unsupported_mime_type(): void
    {
        $path = $this->tempDir.'/irrelevant.txt';
        file_put_contents($path, 'irrelevant');

        $result = $this->processor->process($path, 'text/plain');

        $this->assertFalse($result->success);
    }

    public function test_returns_failure_instead_of_throwing_for_a_corrupt_file(): void
    {
        $path = $this->tempDir.'/broken.docx';
        file_put_contents($path, 'not a real docx file at all');

        $result = $this->processor->process($path, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->assertFalse($result->success);
    }
}
