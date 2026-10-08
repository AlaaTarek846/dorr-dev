<?php

namespace Modules\AI\Services;

/**
 * Shared text cleaning + chunking, extracted out of
 * AiKnowledgeIngestionService (where this logic first existed, for the
 * admin-curated knowledge base) so the Universal AI File Engine's own
 * indexing step can reuse the exact same, already-working algorithm for
 * user-uploaded conversation files instead of a second implementation
 * (master plan #8/#27) - sentence/paragraph-aware chunking with overlap,
 * not arbitrary character slicing.
 */
class AiTextChunker
{
    /**
     * Strips control characters, normalizes line endings and collapses
     * runs of blank lines/whitespace left over from PDF/HTML/OCR
     * extraction, without touching Arabic diacritics or punctuation.
     */
    public function clean(string $raw): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $raw) ?? $raw;
        $text = str_replace("\r\n", "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Sentence/paragraph-aware fixed-size chunking: splits on paragraph
     * and sentence boundaries where possible instead of cutting mid-word,
     * targeting $targetSize characters per chunk with a small overlap
     * between consecutive chunks so context is not lost right at a
     * boundary.
     *
     * @return list<string>
     */
    public function chunk(string $text, int $targetSize, int $overlap): array
    {
        $targetSize = max(200, $targetSize);
        $overlap = max(0, $overlap);

        if (mb_strlen($text) <= $targetSize) {
            return $text === '' ? [] : [$text];
        }

        preg_match_all('/.+?(?:[\.\!\?\x{061F}\x{06D4}]+\s+|\n\n+|$)/su', $text, $matches);
        $sentences = array_values(array_filter(array_map('trim', $matches[0] ?? []), fn ($s) => $s !== ''));

        if ($sentences === []) {
            $sentences = [$text];
        }

        $chunks = [];
        $current = '';

        foreach ($sentences as $sentence) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($sentence) + 1 > $targetSize) {
                $chunks[] = trim($current);
                $current = $overlap > 0 ? mb_substr($current, max(0, mb_strlen($current) - $overlap)) : '';
            }

            $current .= ($current !== '' ? ' ' : '').$sentence;

            // A single sentence longer than the target size on its own -
            // hard-split it rather than producing one giant chunk.
            while (mb_strlen($current) > $targetSize * 2) {
                $chunks[] = trim(mb_substr($current, 0, $targetSize));
                $current = mb_substr($current, $targetSize - $overlap);
            }
        }

        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return array_values(array_filter($chunks, fn ($c) => mb_strlen($c) >= 20));
    }
}
