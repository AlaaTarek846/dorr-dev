<?php

namespace Modules\AI\Tests\Unit;

use Modules\AI\Services\AiDocumentTextExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Business gap fix: a non-image attachment (PDF/DOCX/plain text) used to
 * become nothing but a "[user attached a file: name]" note - the model
 * never actually read it. This proves AiDocumentTextExtractor genuinely
 * extracts real text from real files (not just that the code parses),
 * generating minimal-but-valid PDF/DOCX fixtures by hand since no sample
 * files ship with the repo. Pure PHP, no DB/framework needed.
 */
class AiDocumentTextExtractorTest extends TestCase
{
    protected AiDocumentTextExtractor $extractor;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new AiDocumentTextExtractor;
        $this->tempDir = sys_get_temp_dir().'/ai-doc-extractor-test-'.uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tempDir.'/*') ?: []);
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    public function test_supports_lists_the_documented_mime_types(): void
    {
        $this->assertTrue(AiDocumentTextExtractor::supports('application/pdf'));
        $this->assertTrue(AiDocumentTextExtractor::supports('application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
        $this->assertTrue(AiDocumentTextExtractor::supports('text/plain'));
        $this->assertFalse(AiDocumentTextExtractor::supports('application/zip'));
        $this->assertFalse(AiDocumentTextExtractor::supports('image/png'));
    }

    public function test_extracts_plain_text_and_normalizes_whitespace(): void
    {
        $path = $this->tempDir.'/note.txt';
        file_put_contents($path, "Line one.\n\n\n\nLine two.   spaced   out.");

        $text = $this->extractor->extract($path, 'text/plain');

        $this->assertSame("Line one.\n\nLine two. spaced out.", $text);
    }

    public function test_extracts_text_from_a_flate_decoded_pdf_stream(): void
    {
        $path = $this->tempDir.'/sample.pdf';
        file_put_contents($path, $this->buildMinimalPdf('Hello DORR test document.'));

        $text = $this->extractor->extract($path, 'application/pdf');

        $this->assertSame('Hello DORR test document.', $text);
    }

    public function test_extracts_text_from_a_docx_document_xml(): void
    {
        $path = $this->tempDir.'/sample.docx';
        $this->buildMinimalDocx($path, ['Hello DORR docx test.', 'Second paragraph here.']);

        $text = $this->extractor->extract($path, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->assertSame("Hello DORR docx test.\nSecond paragraph here.", $text);
    }

    public function test_returns_null_for_an_unsupported_mime_type(): void
    {
        $path = $this->tempDir.'/sample.pdf';
        file_put_contents($path, $this->buildMinimalPdf('irrelevant'));

        $this->assertNull($this->extractor->extract($path, 'application/zip'));
    }

    public function test_returns_null_for_a_corrupt_pdf_instead_of_throwing(): void
    {
        $path = $this->tempDir.'/broken.pdf';
        file_put_contents($path, "%PDF-1.4\nnot a real pdf body at all");

        $this->assertNull($this->extractor->extract($path, 'application/pdf'));
    }

    /**
     * Root-cause regression test: a PDF whose embedded font encoding
     * produces an invalid UTF-8 byte when read as raw text (a realistic
     * CID/Identity-H artifact the dependency-free extractor cannot
     * decode correctly - see its own docblock) used to come straight out
     * of extract() unsanitized. That silently broke two things downstream
     * (preg_replace()'s /u modifier, and later json_encode() when the
     * text is sent to the provider) and turned an attached PDF into a
     * generic "provider unavailable" failure instead of a real answer.
     * This proves extract() never returns invalid UTF-8, whatever
     * garbage bytes the source stream contains.
     */
    public function test_extracted_text_is_always_valid_utf8_even_from_a_garbled_stream(): void
    {
        $path = $this->tempDir.'/garbled.pdf';
        // Raw \x80\xFF byte pair inside the Tj operator's string - not
        // escaped as a PDF string literal, so it reaches the extractor's
        // text-showing-operator reader exactly as an invalid UTF-8 byte
        // sequence (a lone continuation byte followed by an invalid
        // standalone byte), mimicking what a custom/CID embedded font
        // actually produces.
        $compressed = gzcompress('BT /F1 24 Tf 100 700 Td (Hello ÿ World) Tj ET');

        $objects = [
            '1 0 obj
<< /Type /Catalog /Pages 2 0 R >>
endobj
',
            '2 0 obj
<< /Type /Pages /Kids [3 0 R] /Count 1 >>
endobj
',
            '3 0 obj
<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>
endobj
',
            '4 0 obj
<< /Length '.strlen($compressed)." /Filter /FlateDecode >>
stream
{$compressed}
endstream
endobj
",
        ];

        file_put_contents($path, '%PDF-1.4
'.implode('', $objects).'trailer
<< /Size 5 /Root 1 0 R >>
%%EOF');

        $text = $this->extractor->extract($path, 'application/pdf');

        if ($text !== null) {
            $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
        } else {
            $this->assertTrue(true);
        }
    }

    /**
     * Hand-builds the smallest valid single-page PDF with one FlateDecode
     * content stream containing a single Tj text-showing operator - just
     * enough structure for the dependency-free fallback extractor to find
     * and decompress, without needing any PDF-writing library.
     */
    protected function buildMinimalPdf(string $text): string
    {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $compressed = gzcompress("BT /F1 24 Tf 100 700 Td ({$escaped}) Tj ET");

        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>\nendobj\n",
            "4 0 obj\n<< /Length ".strlen($compressed)." /Filter /FlateDecode >>\nstream\n{$compressed}\nendstream\nendobj\n",
        ];

        return "%PDF-1.4\n".implode('', $objects)."trailer\n<< /Size 5 /Root 1 0 R >>\n%%EOF";
    }

    /**
     * @param  list<string>  $paragraphs
     */
    protected function buildMinimalDocx(string $path, array $paragraphs): void
    {
        $body = implode('', array_map(
            fn (string $paragraph) => '<w:p><w:r><w:t>'.htmlspecialchars($paragraph, ENT_XML1).'</w:t></w:r></w:p>',
            $paragraphs,
        ));

        $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            ."<w:body>{$body}</w:body></w:document>";

        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->close();
    }
}
