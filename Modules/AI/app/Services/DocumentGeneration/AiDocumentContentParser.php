<?php

namespace Modules\AI\Services\DocumentGeneration;

/**
 * Business gap fix: generateDownloadableFile() used to dump the model's raw
 * chat reply verbatim into a .md file - no real PDF/DOCX/XLSX ever existed.
 * Real document rendering (AiDocumentRenderer) needs semantic blocks
 * (headings, paragraphs, bullet lists, tables), not a blob of markdown-ish
 * text. Rather than write a fragile general-purpose markdown parser, this
 * parses one STRICT, small subset that AiChatService explicitly asks the
 * model to use when producing a document (see
 * AiChatService::structureContentForDocument()) - the model already knows
 * markdown well, so it reliably sticks to this subset when instructed to.
 *
 * @phpstan-type DocBlock array{type: string, level?: int, text?: string, items?: list<string>, headers?: list<string>, rows?: list<list<string>>}
 */
class AiDocumentContentParser
{
    /**
     * @return list<array{type: string, level?: int, text?: string, items?: list<string>, headers?: list<string>, rows?: list<list<string>>}>
     */
    public static function parse(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($raw)) ?: [];
        $blocks = [];

        $paragraphBuffer = [];
        $bulletBuffer = [];
        $tableBuffer = [];

        $flushParagraph = function () use (&$paragraphBuffer, &$blocks) {
            if ($paragraphBuffer !== []) {
                $blocks[] = ['type' => 'paragraph', 'text' => trim(implode(' ', $paragraphBuffer))];
                $paragraphBuffer = [];
            }
        };

        $flushBullets = function () use (&$bulletBuffer, &$blocks) {
            if ($bulletBuffer !== []) {
                $blocks[] = ['type' => 'bullets', 'items' => $bulletBuffer];
                $bulletBuffer = [];
            }
        };

        $flushTable = function () use (&$tableBuffer, &$blocks) {
            if ($tableBuffer !== []) {
                $headers = array_shift($tableBuffer);
                // The markdown separator row ( |---|---| ) carries no data
                // - drop it if present (only dashes/colons/pipes/spaces).
                if (isset($tableBuffer[0]) && preg_match('/^[\s\-:|]+$/', implode('', $tableBuffer[0]))) {
                    array_shift($tableBuffer);
                }
                $blocks[] = ['type' => 'table', 'headers' => $headers, 'rows' => $tableBuffer];
                $tableBuffer = [];
            }
        };

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                $flushParagraph();
                $flushBullets();
                $flushTable();

                continue;
            }

            if (preg_match('/^(#{1,3})\s+(.+)$/u', $trimmed, $m)) {
                $flushParagraph();
                $flushBullets();
                $flushTable();
                $blocks[] = ['type' => 'heading', 'level' => strlen($m[1]), 'text' => trim($m[2])];

                continue;
            }

            if (preg_match('/^[-*]\s+(.+)$/u', $trimmed, $m)) {
                $flushParagraph();
                $flushTable();
                $bulletBuffer[] = trim($m[1]);

                continue;
            }

            if (str_starts_with($trimmed, '|') && substr_count($trimmed, '|') >= 2) {
                $flushParagraph();
                $flushBullets();
                $cells = array_map('trim', explode('|', trim($trimmed, '|')));
                $tableBuffer[] = $cells;

                continue;
            }

            $flushBullets();
            $flushTable();
            $paragraphBuffer[] = $trimmed;
        }

        $flushParagraph();
        $flushBullets();
        $flushTable();

        return $blocks;
    }

    /**
     * The document's title is the first level-1 heading, if the model gave
     * one - otherwise null, and the caller falls back to a generic title.
     *
     * @param  list<array{type: string, level?: int, text?: string}>  $blocks
     */
    public static function extractTitle(array $blocks): ?string
    {
        foreach ($blocks as $block) {
            if ($block['type'] === 'heading' && ($block['level'] ?? 0) === 1) {
                return $block['text'] ?? null;
            }
        }

        return null;
    }
}
