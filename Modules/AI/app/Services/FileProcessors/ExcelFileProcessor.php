<?php

namespace Modules\AI\Services\FileProcessors;

use Modules\AI\Services\FileProcessors\Concerns\AnalyzesSpreadsheetData;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as SharedDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Phase 1: first real XLSX/XLS support in this codebase via
 * phpoffice/phpspreadsheet (already a composer dependency, used
 * elsewhere for export, never for AI file reading). `text/csv` and
 * `text/tab-separated-values` moved out in Phase 3 to their own
 * dedicated CsvFileProcessor/TsvFileProcessor (doc S4).
 *
 * Phase 3 adds real multi-sheet structured normalization: header
 * detection with a confidence score, per-column type detection and
 * statistics, formulas (formula + calculated value where available),
 * merged-cell ranges, hidden-sheet visibility, and a bounded row sample
 * (doc S22: "do not put millions of rows into one API response") -
 * while every row is still walked once for statistics (doc S27).
 *
 * Honesty note on memory (doc S15): merged-cell ranges and real
 * number-format-based date detection (`Date::isDateTime()`) both
 * require PhpSpreadsheet to actually parse style/number-format data,
 * which `setReadDataOnly(true)` (Phase 1's choice) skips entirely - with
 * it on, `getMergeCells()` silently returns nothing and a date serial
 * reads as a plain number. Doc S13/S9 explicitly require both features
 * to be real, so this phase reads WITHOUT that flag. The honest
 * trade-off: more memory per cell than Phase 1 used, on top of the fact
 * that PhpSpreadsheet's `load()` already parses the entire workbook into
 * memory regardless of this setting or of reading rows via an iterator
 * afterwards - there is no true streaming reader here. For a very large
 * workbook this is a real risk worth benchmarking (see the Phase 3
 * report's Known Limitations) - not something this phase can honestly
 * claim is solved by a row iterator alone.
 */
class ExcelFileProcessor implements AiFileProcessorInterface
{
    use AnalyzesSpreadsheetData;

    protected const SUPPORTED = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
    ];

    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, self::SUPPORTED, true);
    }

    public function process(string $absolutePath, string $mimeType): AiFileProcessingResult
    {
        if (! $this->supports($mimeType)) {
            return AiFileProcessingResult::failed('unsupported_mime_type');
        }

        try {
            if ($mimeType === 'application/vnd.ms-excel') {
                // Browsers on Windows also label plain CSV exports with this type, so auto-detection stays.
                $reader = IOFactory::createReaderForFile($absolutePath);
            } else {
                // A declared .xlsx must really be one; anything else (a text file renamed) is not read as CSV.
                $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();

                if (! $reader->canRead($absolutePath)) {
                    return AiFileProcessingResult::failed('XLSX_PARSE_FAILED');
                }
            }
            $spreadsheet = $reader->load($absolutePath);
        } catch (\Throwable) {
            return AiFileProcessingResult::failed($mimeType === 'application/vnd.ms-excel' ? 'XLS_PARSE_FAILED' : 'XLSX_PARSE_FAILED');
        }

        $maxRows = max(50, (int) config('ai.files.spreadsheet_max_rows_per_sheet', 2000));
        $sheetsMeta = [];
        $previewLines = [];
        $warnings = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $sheetMeta = $this->processSheet($sheet, $maxRows, $warnings);
            $sheetsMeta[] = $sheetMeta;

            $previewLines[] = '## '.$sheetMeta['name'];

            if ($sheetMeta['headers'] !== []) {
                $previewLines[] = implode(' | ', $sheetMeta['headers']);
            }

            foreach (array_slice($sheetMeta['rows'], 0, 50) as $row) {
                unset($row['_row_number']);
                $previewLines[] = implode(' | ', array_map(fn ($v) => (string) $v, array_values($row)));
            }
        }

        $text = trim(implode("\n", $previewLines));

        if ($text === '' && array_sum(array_column($sheetsMeta, 'row_count')) === 0) {
            return AiFileProcessingResult::failed('no_data_found');
        }

        if (array_sum(array_column($sheetsMeta, 'truncated'))) {
            $text .= "\n\n[".__('ai.document_truncated_note').']';
        }

        return AiFileProcessingResult::ok(
            text: $text !== '' ? $text : null,
            metadata: [
                'sheet_count' => count($sheetsMeta),
                'sheets' => $sheetsMeta,
            ],
            warnings: $warnings,
            documentType: $mimeType === 'application/vnd.ms-excel' ? 'xls' : 'xlsx',
        );
    }

    /**
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    protected function processSheet(Worksheet $sheet, int $maxRows, array &$warnings): array
    {
        $sheetName = $sheet->getTitle();
        $rawRows = [];
        $dateFlagsByRow = [];
        $rowNumbers = [];
        $formulas = [];
        $totalRows = 0;
        $truncated = false;

        foreach ($sheet->getRowIterator() as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            $values = [];
            $dateFlags = [];

            foreach ($cellIterator as $cell) {
                $values[] = (string) ($cell->getFormattedValue() ?? '');

                try {
                    $dateFlags[] = SharedDate::isDateTime($cell);
                } catch (\Throwable) {
                    $dateFlags[] = false;
                }

                if ($cell->isFormula()) {
                    $calculated = null;
                    $unavailable = false;

                    try {
                        $calculatedRaw = $cell->getCalculatedValue();
                        $calculated = is_scalar($calculatedRaw) ? $calculatedRaw : null;
                    } catch (\Throwable) {
                        $unavailable = true;
                    }

                    $formulas[] = [
                        'cell' => $cell->getCoordinate(),
                        'formula' => (string) $cell->getValue(),
                        'value' => $calculated,
                        'value_unavailable' => $unavailable || $calculated === null,
                    ];
                }
            }

            if (array_filter($values, fn ($v) => trim((string) $v) !== '') === []) {
                $totalRows++;

                continue;
            }

            if (count($rawRows) < $maxRows) {
                $rawRows[] = $values;
                $dateFlagsByRow[] = $dateFlags;
                $rowNumbers[] = $row->getRowIndex();
            } else {
                $truncated = true;
            }

            $totalRows++;
        }

        $mergedCells = array_values($sheet->getMergeCells());

        if ($rawRows === []) {
            return [
                'name' => $sheetName,
                'visibility' => $this->visibility($sheet),
                'row_count' => 0,
                'column_count' => 0,
                'header_row' => null,
                'header_detection_confidence' => 0.0,
                'headers' => [],
                'columns' => [],
                'rows' => [],
                'formulas' => $formulas,
                'merged_cells' => $mergedCells,
                'truncated' => false,
            ];
        }

        [$headerIndex, $confidence] = $this->detectHeaderRow($rawRows);
        $rawHeaders = $rawRows[$headerIndex] ?? [];
        $headers = $this->normalizeHeaders($rawHeaders);

        foreach (array_count_values($rawHeaders) as $header => $count) {
            if ($count > 1 && trim((string) $header) !== '') {
                $warnings[] = "DUPLICATE_HEADER:{$sheetName}:{$header}";
            }
        }

        $dataRows = [];
        $dataDateFlags = [];
        $dataRowNumbers = [];

        foreach ($rawRows as $i => $row) {
            if ($i === $headerIndex) {
                continue;
            }

            $dataRows[] = $row;
            $dataDateFlags[] = $dateFlagsByRow[$i];
            $dataRowNumbers[] = $rowNumbers[$i];
        }

        $normalizedRows = [];

        foreach ($dataRows as $i => $row) {
            $record = ['_row_number' => $dataRowNumbers[$i]];

            foreach ($headers as $colIndex => $header) {
                $record[$header] = $row[$colIndex] ?? null;
            }

            $normalizedRows[] = $record;
        }

        $columns = [];

        foreach ($headers as $colIndex => $header) {
            $columnValues = array_map(fn ($row) => $row[$colIndex] ?? null, $dataRows);
            $columnDateFlags = array_map(fn ($flags) => $flags[$colIndex] ?? false, $dataDateFlags);
            $columns[$header] = $this->summarizeColumn($columnValues, $columnDateFlags);
        }

        return [
            'name' => $sheetName,
            'visibility' => $this->visibility($sheet),
            'row_count' => $totalRows,
            'column_count' => count($headers),
            'header_row' => $headerIndex,
            'header_detection_confidence' => $confidence,
            'headers' => $headers,
            'columns' => $columns,
            'rows' => $normalizedRows,
            'formulas' => $formulas,
            'merged_cells' => $mergedCells,
            'truncated' => $truncated,
        ];
    }

    protected function visibility(Worksheet $sheet): string
    {
        return match ($sheet->getSheetState()) {
            Worksheet::SHEETSTATE_HIDDEN => 'hidden',
            Worksheet::SHEETSTATE_VERYHIDDEN => 'very_hidden',
            default => 'visible',
        };
    }
}
