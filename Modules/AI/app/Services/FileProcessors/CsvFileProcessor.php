<?php

namespace Modules\AI\Services\FileProcessors;

use Modules\AI\Services\FileProcessors\Concerns\AnalyzesSpreadsheetData;

/**
 * Phase 3 (doc S4/S18): CSV used to be handled by ExcelFileProcessor via
 * PhpSpreadsheet's generic CSV reader - moved out into its own dedicated
 * processor because CSV needs its own concerns PhpSpreadsheet's reader
 * does not expose at all: a detected (not assumed) delimiter with a
 * confidence score, real encoding/BOM detection with Arabic text
 * explicitly verified to round-trip, and per-row malformed-data warnings
 * with row numbers. Header detection, duplicate-header renaming, column
 * typing and statistics reuse the same AnalyzesSpreadsheetData trait as
 * ExcelFileProcessor and TsvFileProcessor - one implementation, not
 * three.
 */
class CsvFileProcessor implements AiFileProcessorInterface
{
    use AnalyzesSpreadsheetData;

    protected const SUPPORTED = ['text/csv'];

    protected const DOCUMENT_TYPE = 'csv';

    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, static::SUPPORTED, true);
    }

    public function process(string $absolutePath, string $mimeType): AiFileProcessingResult
    {
        if (! $this->supports($mimeType)) {
            return AiFileProcessingResult::failed('unsupported_mime_type');
        }

        $raw = @file_get_contents($absolutePath);

        if ($raw === false) {
            return AiFileProcessingResult::failed('could_not_read_file');
        }

        if (trim($raw) === '') {
            return AiFileProcessingResult::failed('empty_file');
        }

        [$normalized, $encoding, $warnings] = $this->detectAndNormalizeEncoding($raw);
        $normalized = str_replace(["\r\n", "\r"], "\n", $normalized);

        $sampleLineCount = max(5, (int) config('ai.files.csv_delimiter_sample_lines', 15));
        $sampleLines = array_slice(explode("\n", $normalized), 0, $sampleLineCount);
        [$delimiter, $delimiterConfidence] = $this->resolveDelimiter($sampleLines);

        if ($delimiterConfidence < 0.34) {
            $warnings[] = 'DELIMITER_DETECTION_LOW_CONFIDENCE';
        }

        $result = $this->parseAndNormalize($normalized, $delimiter, $warnings);

        if ($result === null) {
            return AiFileProcessingResult::failed('no_data_found');
        }

        $sheetMeta = $result + [
            'name' => 'Sheet1',
            'delimiter' => $delimiter === "\t" ? 'tab' : $delimiter,
            'delimiter_detection_confidence' => $delimiterConfidence,
        ];

        $previewLines = [];

        if ($sheetMeta['headers'] !== []) {
            $previewLines[] = implode(' | ', $sheetMeta['headers']);
        }

        foreach (array_slice($sheetMeta['rows'], 0, 50) as $row) {
            unset($row['_row_number']);
            $previewLines[] = implode(' | ', array_map(fn ($v) => (string) $v, array_values($row)));
        }

        $text = trim(implode("\n", $previewLines));

        if ($sheetMeta['truncated']) {
            $text .= "\n\n[".__('ai.document_truncated_note').']';
        }

        return AiFileProcessingResult::ok(
            text: $text !== '' ? $text : null,
            metadata: [
                'sheet_count' => 1,
                'sheets' => [$sheetMeta],
                'encoding' => $encoding,
            ],
            warnings: $warnings,
            documentType: $this->documentType(),
        );
    }

    /**
     * Overridden by TsvFileProcessor - kept as a one-line hook rather
     * than duplicating the whole of process() for a single string.
     */
    protected function documentType(): string
    {
        return static::DOCUMENT_TYPE;
    }

    /**
     * Doc S18: the detected delimiter, as a candidate set of comma/
     * semicolon/tab/pipe - CSV never assumes comma, it detects. Split
     * out as its own overridable step so TsvFileProcessor (doc S4: a
     * dedicated processor, tab fixed by format contract, but still
     * validated rather than blindly trusted - doc S18) can reuse every
     * other line of this parser while only changing this one decision.
     *
     * @param  list<string>  $sampleLines
     * @return array{0: string, 1: float}
     */
    protected function resolveDelimiter(array $sampleLines): array
    {
        return $this->detectDelimiter($sampleLines);
    }

    /**
     * Doc S20/S21: a malformed row (wrong column count, an unterminated
     * quoted field PHP's own CSV reader already recovers from by
     * swallowing the following newline into the field) is reported with
     * its row number, never silently dropped or allowed to desync every
     * column after it.
     *
     * @param  list<string>  $warnings
     * @return array<string, mixed>|null
     */
    protected function parseAndNormalize(string $normalized, string $delimiter, array &$warnings): ?array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $normalized);
        rewind($stream);

        $maxRows = max(50, (int) config('ai.files.spreadsheet_max_rows_per_sheet', 2000));
        $rawRows = [];
        $rowNumbers = [];
        $totalRows = 0;
        $truncated = false;
        $lineNumber = 0;

        while (($fields = fgetcsv($stream, 0, $delimiter)) !== false) {
            $lineNumber++;

            if ($fields === [null] || $fields === ['']) {
                continue;
            }

            $totalRows++;

            if (count($rawRows) < $maxRows) {
                $rawRows[] = $fields;
                $rowNumbers[] = $lineNumber;
            } else {
                $truncated = true;
            }
        }

        fclose($stream);

        if ($rawRows === []) {
            return null;
        }

        [$headerIndex, $confidence] = $this->detectHeaderRow($rawRows);
        $rawHeaders = $rawRows[$headerIndex];
        $headers = $this->normalizeHeaders($rawHeaders);
        $expectedColumnCount = count($rawHeaders);

        foreach (array_count_values($rawHeaders) as $header => $count) {
            if ($count > 1 && trim((string) $header) !== '') {
                $warnings[] = "DUPLICATE_HEADER:{$header}";
            }
        }

        $dataRows = [];
        $dataRowNumbers = [];

        foreach ($rawRows as $i => $row) {
            if ($i === $headerIndex) {
                continue;
            }

            if (count($row) !== $expectedColumnCount) {
                $warnings[] = "COLUMN_COUNT_MISMATCH:row_{$rowNumbers[$i]}:expected_{$expectedColumnCount}_got_".count($row);
            }

            $dataRows[] = $row;
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
            $columns[$header] = $this->summarizeColumn($columnValues);
        }

        return [
            'row_count' => $totalRows,
            'column_count' => count($headers),
            'header_row' => $headerIndex,
            'header_detection_confidence' => $confidence,
            'headers' => $headers,
            'columns' => $columns,
            'rows' => $normalizedRows,
            'truncated' => $truncated,
        ];
    }
}
