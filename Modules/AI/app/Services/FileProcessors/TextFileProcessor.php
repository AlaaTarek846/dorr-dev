<?php

namespace Modules\AI\Services\FileProcessors;

/**
 * Phase 1: plain-text content, no library needed. Phase 2 adds: BOM
 * detection (UTF-8/UTF-16 LE/BE) and a best-effort fallback for common
 * legacy encodings via mb_detect_encoding() (doc S16: "do not assume
 * every TXT file is UTF-8"), line-ending normalization, and paragraph-
 * level blocks with a starting line number as the source reference
 * (doc S35) instead of one flat string.
 *
 * `text/markdown` moved to the new MarkdownFileProcessor - this
 * processor now only claims `text/plain`.
 */
class TextFileProcessor implements AiFileProcessorInterface
{
    protected const SUPPORTED = ['text/plain'];

    /**
     * Encodings mb_detect_encoding() is allowed to guess, in priority
     * order - a small, deliberate list (not mb_list_encodings()'s full
     * set) covering the common non-UTF-8 cases this app is likely to
     * see (Arabic/Latin legacy text files), not every encoding that
     * ever existed.
     */
    protected const CANDIDATE_ENCODINGS = ['UTF-8', 'Windows-1256', 'ISO-8859-6', 'Windows-1252', 'ISO-8859-1'];

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

        [$normalized, $encoding, $warnings] = $this->normalizeEncoding($raw);

        // Line endings normalized before line numbers are computed, so
        // a CRLF file and an LF file report the same line numbers.
        $normalized = str_replace(["\r\n", "\r"], "\n", $normalized);

        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $normalized);
        $text = trim(is_string($clean) ? $clean : '');

        if ($text === '') {
            return AiFileProcessingResult::failed('empty_file');
        }

        $blocks = $this->splitIntoParagraphBlocks($normalized);

        return AiFileProcessingResult::ok(
            text: $text,
            metadata: ['char_count' => mb_strlen($text), 'encoding' => $encoding],
            blocks: $blocks,
            warnings: $warnings,
            documentType: 'txt',
        );
    }

    /**
     * @return array{0: string, 1: string, 2: list<string>}
     */
    protected function normalizeEncoding(string $raw): array
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

        $detected = mb_detect_encoding($raw, self::CANDIDATE_ENCODINGS, true);

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
     * @return list<array<string, mixed>>
     */
    protected function splitIntoParagraphBlocks(string $text): array
    {
        $lines = explode("\n", $text);
        $blocks = [];
        $buffer = [];
        $startLine = null;

        $flush = function () use (&$buffer, &$startLine, &$blocks) {
            if ($buffer === []) {
                return;
            }

            $paragraph = trim(implode("\n", $buffer));

            if ($paragraph !== '') {
                $blocks[] = ['type' => 'paragraph', 'text' => $paragraph, 'source' => ['line' => $startLine]];
            }

            $buffer = [];
            $startLine = null;
        };

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                $flush();

                continue;
            }

            $startLine ??= $index + 1;
            $buffer[] = $line;
        }

        $flush();

        return $blocks;
    }
}
