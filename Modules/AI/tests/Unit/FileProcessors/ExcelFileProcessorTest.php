<?php

namespace Modules\AI\Tests\Unit\FileProcessors;

use Modules\AI\Services\FileProcessors\ExcelFileProcessor;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Proves ExcelFileProcessor genuinely reads a real XLSX file (built with
 * PhpSpreadsheet itself, round-tripped through the real writer and
 * reader) rather than just that the code parses - same spirit as
 * AiDocumentTextExtractorTest for PDF/DOCX.
 *
 * Phase 3 narrows this processor to XLS/XLSX only (CSV/TSV moved to
 * their own CsvFileProcessorTest/TsvFileProcessorTest) and adds real
 * structured-normalization coverage: header detection, duplicate
 * headers, column types (including the leading-zero-stays-string and
 * Arabic-text cases the doc repeatedly stresses), formulas, merged
 * cells, and hidden sheets.
 */
class ExcelFileProcessorTest extends TestCase
{
    protected ExcelFileProcessor $processor;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new ExcelFileProcessor;
        $this->tempDir = sys_get_temp_dir().'/ai-excel-processor-test-'.uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tempDir.'/*') ?: []);
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    protected function save(Spreadsheet $spreadsheet, string $name): string
    {
        $path = $this->tempDir.'/'.$name;
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    public function test_supports_xls_and_xlsx_but_not_csv_anymore(): void
    {
        $this->assertTrue($this->processor->supports('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
        $this->assertTrue($this->processor->supports('application/vnd.ms-excel'));
        $this->assertFalse($this->processor->supports('text/csv'));
        $this->assertFalse($this->processor->supports('text/tab-separated-values'));
        $this->assertFalse($this->processor->supports('application/pdf'));
    }

    public function test_extracts_rows_and_headers_from_a_real_xlsx_file(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sales');
        $sheet->fromArray([
            ['Product', 'Revenue'],
            ['Widget', 100],
            ['Gadget', 250],
        ], null, 'A1');

        $path = $this->save($spreadsheet, 'sales.xlsx');

        $result = $this->processor->process($path, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertTrue($result->success);
        $this->assertStringContainsString('## Sales', $result->text);
        $this->assertStringContainsString('Product | Revenue', $result->text);
        $this->assertStringContainsString('Widget | 100', $result->text);
        $this->assertStringContainsString('Gadget | 250', $result->text);
        $this->assertSame(1, $result->metadata['sheet_count']);

        $sheetMeta = $result->metadata['sheets'][0];
        $this->assertSame(['Product', 'Revenue'], $sheetMeta['headers']);
        $this->assertSame(0, $sheetMeta['header_row']);
        $this->assertGreaterThan(0.5, $sheetMeta['header_detection_confidence']);
        $this->assertSame('string', $sheetMeta['columns']['Product']['type']);
        $this->assertSame('integer', $sheetMeta['columns']['Revenue']['type']);
        $this->assertEquals(100, $sheetMeta['columns']['Revenue']['stats']['min']); // may be 100.0
        $this->assertEquals(250, $sheetMeta['columns']['Revenue']['stats']['max']);
    }

    public function test_leading_zero_codes_and_arabic_text_are_never_coerced(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['Code', 'City'],
            ['00123', 'القاهرة'],
            ['00456', 'أحمد'],
        ], null, 'A1');
        // fromArray() writes "00123" as a genuine string cell (not a
        // numeric one) because it doesn't look like a valid PHP numeric
        // literal with leading zeros - exactly the real-world case doc
        // S10 is guarding against.

        $path = $this->save($spreadsheet, 'codes.xlsx');
        $result = $this->processor->process($path, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertTrue($result->success);
        $sheetMeta = $result->metadata['sheets'][0];
        $this->assertSame('string', $sheetMeta['columns']['Code']['type']);
        $this->assertSame('00123', $sheetMeta['rows'][0]['Code']);
        $this->assertSame('أحمد', $sheetMeta['rows'][1]['City']);
        $this->assertStringContainsString('القاهرة', $result->text);
    }

    public function test_duplicate_headers_are_renamed_and_warned_about(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['Name', 'Name', 'Amount'],
            ['Ali', 'Mohamed', 50],
        ], null, 'A1');

        $path = $this->save($spreadsheet, 'dupes.xlsx');
        $result = $this->processor->process($path, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertTrue($result->success);
        $this->assertSame(['Name', 'Name_2', 'Amount'], $result->metadata['sheets'][0]['headers']);
        $this->assertNotEmpty(array_filter($result->warnings, fn ($w) => str_starts_with($w, 'DUPLICATE_HEADER:')));
    }

    public function test_formula_value_and_calculated_result_are_both_captured_separately_from_row_data(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['Item', 'Qty', 'Total'],
            ['Widget', 2, '=B2*10'],
        ], null, 'A1');

        $path = $this->save($spreadsheet, 'formula.xlsx');
        $result = $this->processor->process($path, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertTrue($result->success);
        $sheetMeta = $result->metadata['sheets'][0];

        // The row's data value is the clean calculated+formatted result
        // ("20"), never a "formula = value" display string mixed into
        // the dataset.
        $this->assertSame('20', $sheetMeta['rows'][0]['Total']);
        $this->assertSame('integer', $sheetMeta['columns']['Total']['type']);

        $this->assertCount(1, $sheetMeta['formulas']);
        $this->assertSame('=B2*10', $sheetMeta['formulas'][0]['formula']);
        $this->assertSame(20, $sheetMeta['formulas'][0]['value']);
        $this->assertFalse($sheetMeta['formulas'][0]['value_unavailable']);
    }

    public function test_merged_cells_are_reported(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([['Title', null], ['A', 'B']], null, 'A1');
        $sheet->mergeCells('A1:B1');

        $path = $this->save($spreadsheet, 'merged.xlsx');
        $result = $this->processor->process($path, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertTrue($result->success);
        $this->assertContains('A1:B1', $result->metadata['sheets'][0]['merged_cells']);
    }

    public function test_hidden_sheet_visibility_is_detected(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([['A'], [1]], null, 'A1');
        $hidden = $spreadsheet->createSheet();
        $hidden->setTitle('Internal');
        $hidden->fromArray([['X'], [1]], null, 'A1');
        $hidden->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $path = $this->save($spreadsheet, 'hidden.xlsx');
        $result = $this->processor->process($path, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertTrue($result->success);
        $hiddenMeta = current(array_filter($result->metadata['sheets'], fn ($s) => $s['name'] === 'Internal'));
        $this->assertSame('hidden', $hiddenMeta['visibility']);
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
        $path = $this->tempDir.'/broken.xlsx';
        file_put_contents($path, 'not a real xlsx file at all');

        $result = $this->processor->process($path, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertFalse($result->success);
    }

    public function test_truncates_a_sheet_larger_than_the_configured_row_limit(): void
    {
        config(['ai.files.spreadsheet_max_rows_per_sheet' => 5]);

        $spreadsheet = new Spreadsheet;
        $rows = [['Row']];

        for ($i = 1; $i <= 50; $i++) {
            $rows[] = ["Row {$i}"];
        }

        $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1');

        $path = $this->save($spreadsheet, 'large.xlsx');

        $result = $this->processor->process($path, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertTrue($result->success);
        $this->assertTrue($result->metadata['sheets'][0]['truncated']);
        $this->assertStringNotContainsString('Row 50', $result->text);
    }
}
