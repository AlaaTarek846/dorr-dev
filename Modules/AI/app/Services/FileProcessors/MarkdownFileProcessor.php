<?php

namespace Modules\AI\Services\FileProcessors;

/**
 * Phase 2: previously Markdown files were just handed to TextFileProcessor
 * and treated as flat text (losing all structure). This is a small,
 * deliberately hand-written line-based structural parser - no CommonMark
 * dependency was added (doc S48: "avoid unnecessary dependencies... a
 * suitable package may already exist" - none of this project's installed
 * packages parse Markdown, and a minimal structural reader covers what
 * this phase actually needs: headings/paragraphs/lists/links/code
 * blocks/block quotes/simple GFM tables). It is NOT a full CommonMark-
 * spec-compliant renderer - nested/complex Markdown (nested lists,
 * inline HTML, reference-style links, etc.) degrades to plain paragraph
 * text rather than being mis-parsed. This is a known, documented Phase 2
 * limitation, not a silent gap.
 */
class MarkdownFileProcessor implements AiFileProcessorInterface
{
    protected const SUPPORTED = ['text/markdown'];

    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, self::SUPPORTED, true);
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

        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $raw);
        $normalized = str_replace(["\r\n", "\r"], "\n", is_string($clean) ? $clean : $raw);

        $blocks = $this->parse($normalized);

        $text = trim(implode("\n", array_map(fn ($b) => $this->blockToText($b), $blocks)));

        if ($text === '') {
            return AiFileProcessingResult::failed('empty_file');
        }

        return AiFileProcessingResult::ok(
            text: $text,
            metadata: ['block_count' => count($blocks)],
            blocks: $blocks,
            documentType: 'markdown',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function parse(string $text): array
    {
        $lines = explode("\n", $text);
        $blocks = [];
        $paragraphBuffer = [];
        $lineNumber = 0;
        $total = count($lines);

        $flushParagraph = function () use (&$paragraphBuffer, &$blocks) {
            if ($paragraphBuffer === []) {
                return;
            }

            $paragraph = trim(implode(' ', $paragraphBuffer));

            if ($paragraph !== '') {
                $blocks[] = ['type' => 'paragraph', 'text' => $paragraph];
            }

            $paragraphBuffer = [];
        };

        while ($lineNumber < $total) {
            $line = $lines[$lineNumber];
            $trimmed = trim($line);

            // Fenced code block: ``` ... ```
            if (preg_match('/^```(\w*)\s*$/', $trimmed, $m)) {
                $flushParagraph();
                $language = $m[1] !== '' ? $m[1] : null;
                $codeLines = [];
                $lineNumber++;

                while ($lineNumber < $total && trim($lines[$lineNumber]) !== '```') {
                    $codeLines[] = $lines[$lineNumber];
                    $lineNumber++;
                }

                $blocks[] = ['type' => 'code', 'text' => implode("\n", $codeLines), 'language' => $language];
                $lineNumber++;

                continue;
            }

            // Heading: # .. ######
            if (preg_match('/^(#{1,6})\s+(.*)$/', $trimmed, $m)) {
                $flushParagraph();
                $blocks[] = ['type' => 'heading', 'level' => strlen($m[1]), 'text' => trim($m[2]), 'source' => ['line' => $lineNumber + 1]];
                $lineNumber++;

                continue;
            }

            // Block quote: > ...
            if (str_starts_with($trimmed, '>')) {
                $flushParagraph();
                $quoteLines = [];

                while ($lineNumber < $total && str_starts_with(trim($lines[$lineNumber]), '>')) {
                    $quoteLines[] = trim(preg_replace('/^>\s?/', '', trim($lines[$lineNumber])));
                    $lineNumber++;
                }

                $blocks[] = ['type' => 'quote', 'text' => trim(implode(' ', $quoteLines))];

                continue;
            }

            // Unordered or ordered list.
            if (preg_match('/^(?:[-*+]|\d+\.)\s+(.*)$/', $trimmed, $m)) {
                $flushParagraph();
                $items = [];

                while ($lineNumber < $total && preg_match('/^(?:[-*+]|\d+\.)\s+(.*)$/', trim($lines[$lineNumber]), $itemMatch)) {
                    $items[] = trim($itemMatch[1]);
                    $lineNumber++;
                }

                $blocks[] = ['type' => 'list', 'items' => $items];

                continue;
            }

            // Simple GFM pipe table: header row, "---|---" separator, data rows.
            if (str_contains($trimmed, '|') && $lineNumber + 1 < $total && preg_match('/^\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?$/', trim($lines[$lineNumber + 1]))) {
                $flushParagraph();
                $headers = $this->splitTableRow($trimmed);
                $lineNumber += 2;
                $rows = [];

                while ($lineNumber < $total && str_contains(trim($lines[$lineNumber]), '|') && trim($lines[$lineNumber]) !== '') {
                    $rows[] = $this->splitTableRow(trim($lines[$lineNumber]));
                    $lineNumber++;
                }

                $blocks[] = ['type' => 'table', 'headers' => $headers, 'rows' => $rows];

                continue;
            }

            // Blank line ends the current paragraph.
            if ($trimmed === '') {
                $flushParagraph();
                $lineNumber++;

                continue;
            }

            // A bare link on its own line.
            if (preg_match('/^\[([^\]]+)\]\(([^)]+)\)$/', $trimmed, $m)) {
                $flushParagraph();
                $blocks[] = ['type' => 'link', 'text' => $m[1], 'url' => $m[2]];
                $lineNumber++;

                continue;
            }

            $paragraphBuffer[] = $trimmed;
            $lineNumber++;
        }

        $flushParagraph();

        return $blocks;
    }

    /**
     * @return list<string>
     */
    protected function splitTableRow(string $row): array
    {
        $row = trim($row, '|');

        return array_map('trim', explode('|', $row));
    }

    protected function blockToText(array $block): string
    {
        return match ($block['type']) {
            'heading' => str_repeat('#', (int) $block['level']).' '.$block['text'],
            'paragraph', 'quote', 'link' => $block['text'],
            'code' => $block['text'],
            'list' => implode("\n", array_map(fn ($i) => '- '.$i, $block['items'])),
            'table' => implode("\n", array_map(
                fn ($row) => implode(' | ', $row),
                [$block['headers'], ...$block['rows']],
            )),
            default => '',
        };
    }
}
