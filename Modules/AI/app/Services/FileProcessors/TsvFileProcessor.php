<?php

namespace Modules\AI\Services\FileProcessors;

/**
 * Phase 3 (doc S4/S18): TSV's delimiter is fixed by format contract
 * (tab), but doc S18 is explicit that this must still be validated
 * against the actual file content, not blindly trusted from the
 * extension/MIME alone - a ".tsv" file that turns out to be comma- or
 * semicolon-delimited gets a low-confidence warning instead of being
 * silently mis-split into one giant first column.
 *
 * Everything else (encoding/BOM/Arabic-text handling, header detection,
 * duplicate-header renaming, column typing/statistics, malformed-row
 * warnings) is identical to CsvFileProcessor, so this class only
 * overrides the three things that actually differ.
 */
class TsvFileProcessor extends CsvFileProcessor
{
    protected const SUPPORTED = ['text/tab-separated-values'];

    protected const DOCUMENT_TYPE = 'tsv';

    /**
     * @param  list<string>  $sampleLines
     * @return array{0: string, 1: float}
     */
    protected function resolveDelimiter(array $sampleLines): array
    {
        [, $tabConfidence] = $this->detectDelimiter($sampleLines, ["\t"]);

        return ["\t", $tabConfidence];
    }
}
