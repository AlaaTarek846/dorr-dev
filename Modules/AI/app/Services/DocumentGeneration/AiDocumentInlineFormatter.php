<?php

namespace Modules\AI\Services\DocumentGeneration;

/**
 * Real, observed bug - the whole reason this class exists: AiDocumentContentParser
 * only ever recognized BLOCK-level markdown (#, -, |tables|) - a span of
 * inline markdown like "**bold**" inside a paragraph, bullet item, heading
 * or table cell was never touched, so it reached every renderer (PDF via
 * dompdf, DOCX via PhpWord, XLSX via PhpSpreadsheet) as literal asterisk
 * characters. The model's replies routinely use "**bold**" for emphasis
 * (completely normal GPT behaviour), so this was not an edge case - it
 * showed up in nearly every generated file with more than a plain
 * paragraph in it, and the exact same text (the same AiChatService reply
 * content) renders through this same literal-asterisk problem in the
 * Android chat bubble too (see AiMessageBubble for that fix).
 *
 * This is the one shared place that understands inline markdown - each
 * renderer turns the returned spans into whatever its own format needs
 * (HTML tags for PDF, styled text runs for DOCX, plain text for XLSX)
 * rather than three separate copies of the same regex.
 *
 * Deliberately a small, explicit subset (bold, italic, inline code, and
 * links collapsed to "text (url)") - the same "transparent, extensible,
 * not a full parser" trade-off AiRequiredCapabilityResolver documents for
 * its own keyword matching, appropriate here because the input is always
 * either the model's own reply or content it was explicitly instructed to
 * restructure (see AiChatService::structureContentForDocument()), never
 * arbitrary user-authored markdown.
 */
class AiDocumentInlineFormatter
{
    /**
     * @return list<array{text: string, bold: bool, italic: bool, code: bool}>
     */
    public static function parseSpans(string $text): array
    {
        // Links first, collapsed to plain "label (url)" text - none of the
        // three output formats here need a real clickable hyperlink badly
        // enough to justify the extra per-renderer plumbing, and this at
        // least keeps both the label and the destination readable instead
        // of showing raw "[label](url)" syntax.
        $text = preg_replace('/\[([^\]]+)\]\(([^)\s]+)\)/u', '$1 ($2)', $text) ?? $text;

        if ($text === '') {
            return [];
        }

        $pattern = '/(\*\*.+?\*\*|__.+?__|`.+?`|\*[^*\n]+?\*|_[^_\n]+?_)/us';
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$text];

        $spans = [];

        foreach ($parts as $part) {
            if (preg_match('/^\*\*(.+)\*\*$/us', $part, $m) || preg_match('/^__(.+)__$/us', $part, $m)) {
                $spans[] = ['text' => $m[1], 'bold' => true, 'italic' => false, 'code' => false];
            } elseif (preg_match('/^`(.+)`$/us', $part, $m)) {
                $spans[] = ['text' => $m[1], 'bold' => false, 'italic' => false, 'code' => true];
            } elseif (preg_match('/^\*(.+)\*$/us', $part, $m) || preg_match('/^_(.+)_$/us', $part, $m)) {
                $spans[] = ['text' => $m[1], 'bold' => false, 'italic' => true, 'code' => false];
            } else {
                $spans[] = ['text' => $part, 'bold' => false, 'italic' => false, 'code' => false];
            }
        }

        return $spans;
    }

    /**
     * Same spans, joined back into plain text with every marker stripped -
     * for the one renderer (XLSX) where per-run rich text inside a single
     * cell is not worth the complexity: a spreadsheet cell is read as
     * data, not as styled prose, so "clean plain text" is the correct
     * output there, not "literal asterisks" (the bug) or "bold runs"
     * (unnecessary complexity for a data cell).
     */
    public static function toPlainText(string $text): string
    {
        return implode('', array_map(fn (array $span) => $span['text'], self::parseSpans($text)));
    }
}
