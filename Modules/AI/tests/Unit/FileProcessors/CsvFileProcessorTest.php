<?php

namespace Modules\AI\Tests\Unit\FileProcessors;

use Modules\AI\Services\FileProcessors\CsvFileProcessor;
use Tests\TestCase;

/**
 * First real test of dedicated CSV support (doc S4/S18-S21) - delimiter
 * detection, encoding/BOM handling with Arabic text verified to round-
 * trip, malformed-row warnings, duplicate headers, and real statistics -
 * none of which the old ExcelFileProcessor-via-PhpSpreadsheet path
 * exercised.
 *
 * Extends the Laravel-booted TestCase (not a plain PHPUnit one) because
 * CsvFileProcessor::process() calls config() - the same distinction that
 * caused real bugs earlier in this file engine (see JsonFileProcessorTest/
 * XmlFileProcessorTest).
 */
class CsvFileProcessorTest extends TestCase
{
    protected CsvFileProcessor $processor;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new CsvFileProcessor;
        $this->tempDir = sys_get_temp_dir().'/ai-csv-processor-test-'.uniqid();
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

    public function test_supports_only_csv(): void
    {
        $this->assertTrue($this->processor->supports('text/csv'));
        $this->assertFalse($this->processor->supports('text/tab-separated-values'));
        $this->assertFalse($this->processor->supports('application/pdf'));
    }

    public function test_detects_comma_delimiter_and_extracts_rows(): void
    {
        $path = $this->write('people.csv', "Name,Age\nAli,30\nSara,25\n");

        $result = $this->processor->process($path, 'text/csv');

        $this->assertTrue($result->success);
        $this->assertSame(['Name', 'Age'], $result->metadata['sheets'][0]['headers']);
        $this->assertSame('Ali', $result->metadata['sheets'][0]['rows'][0]['Name']);
        $this->assertSame('integer', $result->metadata['sheets'][0]['columns']['Age']['type']);
        $this->assertSame(',', $result->metadata['sheets'][0]['delimiter']);
    }

    public function test_detects_semicolon_delimiter(): void
    {
        $path = $this->write('eu.csv', "Name;Amount\nAli;100\n");

        $result = $this->processor->process($path, 'text/csv');

        $this->assertTrue($result->success);
        $this->assertSame(';', $result->metadata['sheets'][0]['delimiter']);
        $this->assertSame('100', $result->metadata['sheets'][0]['rows'][0]['Amount']);
    }

    public function test_arabic_text_round_trips_correctly_through_utf8_with_bom(): void
    {
        $content = "\xEF\xBB\xBFName,City\nأحمد,القاهرة\nمحمد,المبيعات\n";
        $path = $this->write('arabic.csv', $content);

        $result = $this->processor->process($path, 'text/csv');

        $this->assertTrue($result->success);
        $this->assertSame('أحمد', $result->metadata['sheets'][0]['rows'][0]['Name']);
        $this->assertSame('القاهرة', $result->metadata['sheets'][0]['rows'][0]['City']);
        $this->assertSame('UTF-8 (BOM)', $result->metadata['encoding']);
    }

    public function test_arabic_text_round_trips_from_windows_1256(): void
    {
        $utf8 = "Name,City\nأحمد,القاهرة\n";
        $windows1256 = @iconv('UTF-8', 'Windows-1256', $utf8);
        $this->assertIsString($windows1256, 'iconv must support Windows-1256 in this environment for this test to be meaningful');

        $path = $this->write('arabic-legacy.csv', $windows1256);

        $result = $this->processor->process($path, 'text/csv');

        $this->assertTrue($result->success);
        $this->assertSame('أحمد', $result->metadata['sheets'][0]['rows'][0]['Name']);
        $this->assertSame('القاهرة', $result->metadata['sheets'][0]['rows'][0]['City']);
    }

    public function test_leading_zero_codes_stay_strings(): void
    {
        $path = $this->write('codes.csv', "Code,Label\n00123,A\n000456,B\n");

        $result = $this->processor->process($path, 'text/csv');

        $this->assertTrue($result->success);
        $this->assertSame('string', $result->metadata['sheets'][0]['columns']['Code']['type']);
        $this->assertSame('00123', $result->metadata['sheets'][0]['rows'][0]['Code']);
    }

    public function test_column_count_mismatch_is_warned_with_the_row_number(): void
    {
        $path = $this->write('malformed.csv', "Name,Age,City\nAli,30\nSara,25,Cairo\n");

        $result = $this->processor->process($path, 'text/csv');

        $this->assertTrue($result->success);
        $this->assertNotEmpty(array_filter(
            $result->warnings,
            fn ($w) => str_starts_with($w, 'COLUMN_COUNT_MISMATCH:row_2:')
        ));
    }

    public function test_duplicate_headers_are_renamed(): void
    {
        $path = $this->write('dupes.csv', "Name,Name,Amount\nAli,Mohamed,50\n");

        $result = $this->processor->process($path, 'text/csv');

        $this->assertTrue($result->success);
        $this->assertSame(['Name', 'Name_2', 'Amount'], $result->metadata['sheets'][0]['headers']);
    }

    public function test_empty_file_fails_cleanly(): void
    {
        $path = $this->write('empty.csv', '');

        $result = $this->processor->process($path, 'text/csv');

        $this->assertFalse($result->success);
        $this->assertSame('empty_file', $result->error);
    }

    public function test_basic_statistics_are_computed_for_a_numeric_column(): void
    {
        $path = $this->write('stats.csv', "Name,Amount\nA,10\nB,20\nC,30\n");

        $result = $this->processor->process($path, 'text/csv');

        $stats = $result->metadata['sheets'][0]['columns']['Amount']['stats'];
        $this->assertSame(10.0, $stats['min']);
        $this->assertSame(30.0, $stats['max']);
        $this->assertSame(60.0, $stats['sum']);
        $this->assertSame(20.0, $stats['average']);
    }

    public function test_truncates_a_dataset_larger_than_the_configured_row_limit(): void
    {
        config(['ai.files.spreadsheet_max_rows_per_sheet' => 5]);

        $lines = ['Row'];

        for ($i = 1; $i <= 50; $i++) {
            $lines[] = "Row {$i}";
        }

        $path = $this->write('large.csv', implode("\n", $lines)."\n");

        $result = $this->processor->process($path, 'text/csv');

        $this->assertTrue($result->success);
        $this->assertTrue($result->metadata['sheets'][0]['truncated']);
    }
}
