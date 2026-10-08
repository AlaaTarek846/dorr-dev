<?php

namespace Modules\AI\Services\FileProcessors\Concerns;

/**
 * Shared spreadsheet-normalization logic (doc S7/S9/S10/S22/S27/S41)
 * used by ExcelFileProcessor, CsvFileProcessor, and TsvFileProcessor -
 * one implementation of "detect header row / classify a column / rename
 * a duplicate header" instead of three slightly different ones.
 */
trait AnalyzesSpreadsheetData
{
    /**
     * Doc S8: row 1 is not assumed to be the header - a small, honest
     * heuristic instead (mostly-text row whose neighbor is mostly
     * numeric scores higher), with an explicit confidence so the caller
     * can decide what to do with a low-confidence guess rather than
     * silently trusting it.
     *
     * @param  list<list<mixed>>  $sampleRows  Raw, unmodified row values
     *                                         - never destroyed, only read.
     * @return array{0: int, 1: float} [header row index, confidence 0..1]
     */
    protected function detectHeaderRow(array $sampleRows, int $maxRowsToScan = 5): array
    {
        $bestIndex = 0;
        $bestScore = -1.0;
        $scanned = array_slice($sampleRows, 0, $maxRowsToScan, true);

        foreach ($scanned as $index => $row) {
            $nonEmpty = array_filter($row, fn ($v) => trim((string) $v) !== '');

            if ($nonEmpty === []) {
                continue;
            }

            $stringish = 0;

            foreach ($nonEmpty as $v) {
                if (! is_numeric(trim((string) $v))) {
                    $stringish++;
                }
            }

            $textRatio = $stringish / max(1, count($nonEmpty));
            $fillRatio = count($nonEmpty) / max(1, count($row));
            $score = ($textRatio * 0.7) + ($fillRatio * 0.3);

            $nextRow = $sampleRows[$index + 1] ?? null;

            if ($nextRow !== null) {
                $nextNonEmpty = array_filter($nextRow, fn ($v) => trim((string) $v) !== '');

                if ($nextNonEmpty !== []) {
                    $nextNumeric = 0;

                    foreach ($nextNonEmpty as $v) {
                        if (is_numeric(trim((string) $v))) {
                            $nextNumeric++;
                        }
                    }

                    $score += ($nextNumeric / max(1, count($nextNonEmpty))) * 0.2;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = $index;
            }
        }

        return [$bestIndex, round(max(0.0, min(1.0, $bestScore)), 2)];
    }

    /**
     * Doc S41 (duplicate headers): "Name | Name | Amount" becomes
     * "Name", "Name_2", "Amount" - never silently overwriting one
     * column's data with another's.
     *
     * @param  list<string>  $headers
     * @return list<string>
     */
    protected function normalizeHeaders(array $headers): array
    {
        $seen = [];
        $result = [];

        foreach ($headers as $header) {
            $header = trim((string) $header);

            if ($header === '') {
                $header = 'Column';
            }

            if (isset($seen[$header])) {
                $seen[$header]++;
                $result[] = $header.'_'.$seen[$header];
            } else {
                $seen[$header] = 1;
                $result[] = $header;
            }
        }

        return $result;
    }

    /**
     * Doc S9/S10 (column types, conservative detection): a value is
     * classified from its own text/formatting, never guessed from its
     * column name. Leading zeros (doc's own "000123" example) are
     * deliberately kept as `string`, never coerced to an integer.
     *
     * @return array{0: string, 1: mixed} [type, normalized value]
     */
    protected function classifyScalar(mixed $raw, bool $dateFormatted = false): array
    {
        if ($raw === null) {
            return ['empty', null];
        }

        if (is_bool($raw)) {
            return ['boolean', $raw];
        }

        if ($dateFormatted && (is_numeric($raw) || is_string($raw))) {
            return [str_contains((string) $raw, ':') ? 'datetime' : 'date', (string) $raw];
        }

        $value = trim((string) $raw);

        if ($value === '') {
            return ['empty', null];
        }

        if (preg_match('/^-?\d+(\.\d+)?\s?%$/', $value)) {
            return ['percentage', (float) rtrim($value, '% ')];
        }

        if (preg_match('/^(EGP|USD|SAR|AED|[$£€])\s?-?[\d,]+(\.\d+)?$/i', $value)
            || preg_match('/^-?[\d,]+(\.\d+)?\s?(EGP|USD|SAR|AED)$/i', $value)) {
            $numeric = (float) preg_replace('/[^\d.\-]/', '', $value);

            return ['currency', $numeric];
        }

        // Leading-zero numeric strings ("000123", "00123") are codes/IDs,
        // not numbers - doc S10's own example.
        if (preg_match('/^0\d+$/', $value)) {
            return ['string', $value];
        }

        if (preg_match('/^-?\d+$/', $value)) {
            return ['integer', (int) $value];
        }

        if (preg_match('/^-?\d+\.\d+$/', $value)) {
            return ['decimal', (float) $value];
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $value)) {
            return [str_contains($value, ':') ? 'datetime' : 'date', $value];
        }

        return ['string', $value];
    }

    /**
     * Doc S9/S27 (column metadata + basic statistics): aggregates a
     * column's sampled values into one type (or `mixed` when the sample
     * genuinely mixes incompatible types) plus count/min/max/sum/average
     * for numeric columns, unique/null counts for everything, and
     * min/max for dates - never an expensive full-dataset computation
     * beyond what was already read for the row sample (doc S28: "make
     * statistics configurable... basic statistics should be enough").
     *
     * @param  list<mixed>  $values
     * @param  list<bool>  $dateFlags  Same-length "was this cell
     *                                 formatted as a date" flags, for
     *                                 spreadsheet sources that know this
     *                                 for real (Excel/XLS) rather than
     *                                 guessing from text (CSV/TSV pass
     *                                 an empty array here).
     * @return array<string, mixed>
     */
    protected function summarizeColumn(array $values, array $dateFlags = []): array
    {
        $typeCounts = [];
        $nullCount = 0;
        $uniqueValues = [];
        $numeric = [];
        $dates = [];

        foreach ($values as $i => $raw) {
            [$type, $normalized] = $this->classifyScalar($raw, $dateFlags[$i] ?? false);
            $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;

            if ($type === 'empty') {
                $nullCount++;

                continue;
            }

            $uniqueValues[is_scalar($normalized) ? (string) $normalized : json_encode($normalized)] = true;

            if (in_array($type, ['integer', 'decimal', 'currency', 'percentage'], true)) {
                $numeric[] = (float) $normalized;
            } elseif (in_array($type, ['date', 'datetime'], true)) {
                $dates[] = (string) $normalized;
            }
        }

        $nonEmptyTypes = array_keys(array_filter($typeCounts, fn ($count, $type) => $type !== 'empty', ARRAY_FILTER_USE_BOTH));
        $type = match (true) {
            count($nonEmptyTypes) === 0 => 'empty',
            count($nonEmptyTypes) === 1 => $nonEmptyTypes[0],
            default => 'mixed',
        };

        $column = [
            'type' => $type,
            'null_count' => $nullCount,
            'unique_count' => count($uniqueValues),
        ];

        if ($numeric !== []) {
            $column['stats'] = [
                'count' => count($numeric),
                'min' => min($numeric),
                'max' => max($numeric),
                'sum' => array_sum($numeric),
                'average' => round(array_sum($numeric) / count($numeric), 4),
            ];
        } elseif ($dates !== []) {
            sort($dates);
            $column['stats'] = ['min_date' => $dates[0], 'max_date' => end($dates)];
        }

        return $column;
    }

    /**
     * Doc S19/S20 (CSV/TSV encoding): the exact same BOM/legacy-encoding
     * detection TextFileProcessor uses for plain text, shared here rather
     * than re-implemented, with one addition this phase's doc repeatedly
     * stresses: Arabic text (doc's own examples: "\u0623\u062d\u0645\u062f",
     * "\u0645\u062d\u0645\u062f", "\u0627\u0644\u0645\u0628\u064a\u0639\u0627\u062a",
     * "\u0627\u0644\u0642\u0627\u0647\u0631\u0629") must round-trip
     * correctly - Windows-1256/ISO-8859-6 are tried before giving up,
     * exactly as for plain text, never silently mangled into "?????".
     *
     * @return array{0: string, 1: string, 2: list<string>}
     */
    protected function detectAndNormalizeEncoding(string $raw): array
    {
        $warnings = [];

        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            return [substr($raw, 3), 'UTF-8 (BOM)', $warnings];
        }

        if (str_starts_with($raw, "\xFF\xFE")) {
            $converted = @mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');

            return [is_string($converted) ? $converted : $raw, 'UTF-16LE', $warnings];
        }

        if (str_starts_with($raw, "\xFE\xFF")) {
            $converted = @mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');

            return [is_string($converted) ? $converted : $raw, 'UTF-16BE', $warnings];
        }

        if (mb_check_encoding($raw, 'UTF-8')) {
            return [$raw, 'UTF-8', $warnings];
        }

        // mbstring has no Windows-1256 (the usual encoding of Arabic CSVs saved by Excel on Windows) and
        // throws ValueError if it is listed, so it is tried through iconv: accepted when it decodes
        // cleanly AND yields Arabic letters, which a wrong guess almost never does.
        if (function_exists('iconv')) {
            $arabic = @iconv('Windows-1256', 'UTF-8', $raw);

            if (is_string($arabic) && $arabic !== '' && preg_match('/\p{Arabic}/u', $arabic) === 1) {
                return [$arabic, 'Windows-1256', $warnings];
            }
        }

        $detected = mb_detect_encoding($raw, ['UTF-8', 'ISO-8859-6', 'Windows-1252', 'ISO-8859-1'], true);

        if ($detected && $detected !== 'UTF-8') {
            $converted = @mb_convert_encoding($raw, 'UTF-8', $detected);

            if (is_string($converted)) {
                return [$converted, $detected, $warnings];
            }
        }

        $warnings[] = 'encoding_could_not_be_detected_bytes_dropped';

        return [$raw, 'unknown', $warnings];
    }

    /**
     * Doc S18: delimiter is detected, never assumed from the file
     * extension alone - counts the candidate delimiter that appears most
     * consistently across the first few non-empty sample lines, with a
     * confidence score (how many of the sampled lines agree on the same
     * count) so a genuinely ambiguous file is flagged rather than
     * silently mis-split.
     *
     * @param  list<string>  $sampleLines  Raw lines (encoding already
     *                                     normalized), BOM-free.
     * @param  list<string>  $candidates
     * @return array{0: string, 1: float} [delimiter, confidence 0..1]
     */
    protected function detectDelimiter(array $sampleLines, array $candidates = [',', ';', "\t", '|']): array
    {
        $sampleLines = array_values(array_filter($sampleLines, fn ($line) => trim($line) !== ''));

        if ($sampleLines === []) {
            return [$candidates[0], 0.0];
        }

        $best = $candidates[0];
        $bestScore = -1.0;

        foreach ($candidates as $delimiter) {
            $counts = array_map(fn ($line) => substr_count($line, $delimiter), $sampleLines);

            if (max($counts) === 0) {
                continue;
            }

            $mode = array_count_values($counts);
            arsort($mode);
            $modeCount = array_key_first($mode);
            $agreement = $mode[$modeCount] / count($counts);
            $score = $agreement * min(1.0, $modeCount / 3);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $delimiter;
            }
        }

        return [$best, round(max(0.0, $bestScore), 2)];
    }
}
