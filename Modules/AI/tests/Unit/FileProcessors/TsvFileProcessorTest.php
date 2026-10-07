<?php

namespace Modules\AI\Tests\Unit\FileProcessors;

use Modules\AI\Services\FileProcessors\TsvFileProcessor;
use Tests\TestCase;

/**
 * TsvFileProcessor shares all of CsvFileProcessor's parsing/typing logic
 * (same trait, same base class) - these tests cover only what's actually
 * different: the delimiter is tab by contract, but doc S18 still
 * requires that to be validated against the real content rather than
 * blindly trusted.
 */
class TsvFileProcessorTest extends TestCase
{
    protected TsvFileProcessor $processor;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new TsvFileProcessor;
        $this->tempDir = sys_get_temp_dir().'/ai-tsv-processor-test-'.uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tempDir.'/*') ?: []);
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    protected function write(string $name, string $content): string
    {
        $path = $this->tempDir.'/'.$name;
        file_put_contents($path, $content);

        return $path;
    }

    public function test_supports_only_tsv(): void
    {
        $this->assertTrue($this->processor->supports('text/tab-separated-values'));
        $this->assertFalse($this->processor->supports('text/csv'));
    }

    public function test_extracts_rows_using_a_real_tab_delimiter(): void
    {
        $path = $this->write('people.tsv', "Name\tAge\nAli\t30\nSara\t25\n");

        $result = $this->processor->process($path, 'text/tab-separated-values');

        $this->assertTrue($result->success);
        $this->assertSame(['Name', 'Age'], $result->metadata['sheets'][0]['headers']);
        $this->assertSame('Ali', $result->metadata['sheets'][0]['rows'][0]['Name']);
        $this->assertSame('tab', $result->metadata['sheets'][0]['delimiter']);
        $this->assertSame('tsv', $result->documentType);
    }

    public function test_a_file_with_no_tabs_at_all_gets_a_low_confidence_warning_instead_of_being_trusted_blindly(): void
    {
        $path = $this->write('actually-csv.tsv', "Name,Age\nAli,30\n");

        $result = $this->processor->process($path, 'text/tab-separated-values');

        $this->assertTrue($result->success);
        $this->assertContains('DELIMITER_DETECTION_LOW_CONFIDENCE', $result->warnings);
        // Still parsed as tab-delimited (format contract), so the whole
        // line lands in a single column rather than being silently
        // re-split on comma behind the caller's back.
        $this->assertSame(['Name,Age'], $result->metadata['sheets'][0]['headers']);
    }

    public function test_arabic_text_round_trips_in_tsv_too(): void
    {
        $content = "\xEF\xBB\xBFName\tCity\nمحمد\tالقاهرة\n";
        $path = $this->write('arabic.tsv', $content);

        $result = $this->processor->process($path, 'text/tab-separated-values');

        $this->assertTrue($result->success);
        $this->assertSame('محمد', $result->metadata['sheets'][0]['rows'][0]['Name']);
        $this->assertSame('القاهرة', $result->metadata['sheets'][0]['rows'][0]['City']);
    }
}
