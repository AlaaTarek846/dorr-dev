<?php

namespace Modules\Marketing\Services\Research;

/**
 * Orchestrator step 04 (POL-11, AT-02): every claim is tied to sources the run really has. A citation to a source the
 * run does not have is dropped; a claim left with no source is kept only as an assumption, and a claim with a number
 * and no source is thrown away — a number is never shown without where it came from.
 */
class EvidenceGuard
{
    /**
     * @param  list<mixed>  $findings  as the AI returned them
     * @param  list<string>  $refs  the run's source refs (S1…)
     * @return array{kept: list<array{claim: string, source_refs: list<string>, confidence: string, is_assumption: bool, is_quantitative: bool}>, dropped_numbers: int, dropped_citations: int}
     */
    public function check(array $findings, array $refs): array
    {
        $kept = [];
        $droppedNumbers = 0;
        $droppedCitations = 0;

        foreach ($findings as $finding) {
            if (! is_array($finding)) {
                continue;
            }
            $claim = trim((string) ($finding['claim'] ?? ''));
            if ($claim === '' || mb_strlen($claim) > 1000) {
                continue;
            }
            $cited = collect((array) ($finding['sources'] ?? []))->map(fn ($r) => strtoupper(trim((string) $r)))->unique()->values();
            $valid = $cited->intersect($refs)->values()->all();
            $droppedCitations += $cited->count() - count($valid);
            $quantitative = self::hasNumber($claim);

            if ($valid === [] && $quantitative) {
                $droppedNumbers++;

                continue;
            }

            $confidence = in_array($finding['confidence'] ?? null, ['high', 'medium', 'low'], true) ? $finding['confidence'] : 'low';
            $kept[] = [
                'claim' => $claim,
                'source_refs' => $valid,
                'confidence' => $valid === [] ? 'low' : $confidence,
                'is_assumption' => $valid === [],
                'is_quantitative' => $quantitative,
            ];

            if (count($kept) >= 30) {
                break;
            }
        }

        return ['kept' => $kept, 'dropped_numbers' => $droppedNumbers, 'dropped_citations' => $droppedCitations];
    }

    /** Latin or Arabic-Indic digits. */
    public static function hasNumber(string $text): bool
    {
        return (bool) preg_match('/[0-9\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u', $text);
    }
}
