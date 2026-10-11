<?php

namespace Modules\AI\Services;

use Smalot\PdfParser\Parser;

/**
 * Turns an attached document (PDF, DOCX, or plain text) into plain text
 * the chat model can actually read, closing the "document_analysis"
 * capability's real gap: AiRequiredCapabilityResolver already classified
 * a non-image attachment as needing this capability and AiRoutingEngine
 * already prefers a model tagged for it, but until this class existed
 * nothing ever turned the file itself into text - it only ever became a
 * "[user attached a file: name]" note, same as an image before the
 * vision work.
 *
 * Deliberately dependency-free (no composer package required) so this
 * works out of the box on every install:
 * - PDF: uses smalot/pdfparser automatically if the project happens to
 *   have it installed (best quality - handles more encodings/fonts), and
 *   otherwise falls back to a self-contained extractor that decompresses
 *   FlateDecode streams (PHP's built-in zlib) and reads text-showing
 *   operators (Tj/TJ) directly. That fallback covers the large majority
 *   of real-world PDFs, but - being a minimal implementation and not a
 *   full PDF renderer - will not extract text from an encrypted PDF, a
 *   scanned/image-only PDF (no OCR is performed), or one built with
 *   CID/Identity-H embedded fonts. Installing smalot/pdfparser
 *   (`composer require smalot/pdfparser`) upgrades PDF quality with no
 *   further code change, since it is tried first automatically.
 * - DOCX: reads word/document.xml directly via PHP's built-in
 *   ZipArchive (a .docx is just a zip of XML) and strips markup - no
 *   PhpWord or other dependency needed.
 * - Plain text/markdown/CSV: read as-is.
 * - Anything else (legacy .doc, .xlsx, images, ...) returns null so the
 *   caller degrades to the old plain-text attachment note instead of
 *   silently sending nothing useful.
 */
class AiDocumentTextExtractor
{
    protected const SUPPORTED_MIME_TYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/plain',
        'text/markdown',
        'text/csv',
    ];

    public static function supports(string $mimeType): bool
    {
        return in_array($mimeType, self::SUPPORTED_MIME_TYPES, true);
    }

    /**
     * @return string|null Extracted plain text, or null if the file type
     *                     isn't supported or nothing could be extracted.
     */
    public function extract(string $absolutePath, string $mimeType): ?string
    {
        if (! is_readable($absolutePath)) {
            return null;
        }

        $text = match ($mimeType) {
            'application/pdf' => $this->extractPdf($absolutePath),
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => $this->extractDocx($absolutePath),
            'text/plain', 'text/markdown', 'text/csv' => file_get_contents($absolutePath) ?: null,
            default => null,
        };

        if ($text === null) {
            return null;
        }

        // Root-cause fix - real, observed bug: extracted PDF text (most of
        // all from the dependency-free fallback path below, which has no
        // real encoding/CMap resolution) can easily contain invalid UTF-8
        // byte sequences - a garbled remnant of a CID/Identity-H embedded
        // font, say. Two things downstream both fail outright, not
        // gracefully, on a single invalid byte: the preg_replace() calls
        // just below (their /u modifier makes PCRE return null on invalid
        // input, which the old code masked with "?? $text" - silently
        // keeping the ORIGINAL, still-invalid text instead of a cleaned
        // one) and, far more seriously, json_encode() when this text is
        // later folded into the chat history and sent to the provider's
        // API - that throws/fails the entire request. The user never sees
        // either failure for what it is: it surfaces as the chat's
        // generic "provider unavailable" message on a request that would
        // have worked fine without the attachment. Sanitizing immediately
        // after extraction, before anything else touches this text, closes
        // both gaps at once.
        $text = $this->sanitizeUtf8($text);

        $text = trim(preg_replace('/[ \t]+/u', ' ', preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text) ?? $text);

        return $text === '' ? null : $text;
    }

    /**
     * Drops/replaces any byte sequence that isn't valid UTF-8 rather than
     * letting it silently poison every later consumer of this text (see
     * the call site's docblock above). iconv's //IGNORE suffix is tried
     * first because it drops invalid bytes outright instead of
     * substituting a replacement character for each one; mb_convert_encoding
     * is the fallback for the rare environment without iconv.
     */
    protected function sanitizeUtf8(string $text): string
    {
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);

        if (! is_string($clean) || $clean === '') {
            $clean = @mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        return is_string($clean) ? $clean : '';
    }

    protected function extractPdf(string $absolutePath): ?string
    {
        if (class_exists(Parser::class)) {
            try {
                $parser = new Parser;
                $text = $parser->parseFile($absolutePath)->getText();

                return trim((string) $text) !== '' ? $text : null;
            } catch (\Throwable) {
                // Falls through to the built-in extractor below - a
                // malformed/unusual PDF should degrade, not break chat.
            }
        }

        return $this->extractPdfWithoutDependencies($absolutePath);
    }

    /**
     * Minimal, dependency-free PDF text extraction: decompresses every
     * FlateDecode stream object and reads the raw text-showing operators
     * (Tj for a single string, TJ for an array of strings/kerning
     * numbers) out of the decompressed page content. This intentionally
     * does not implement encoding/CMap resolution, so non-Latin text
     * embedded with a custom/CID font may come out garbled or missing -
     * good enough for "can the model read what this document mostly
     * says", not a substitute for a real PDF text layer extractor.
     */
    protected function extractPdfWithoutDependencies(string $absolutePath): ?string
    {
        $raw = file_get_contents($absolutePath);

        if ($raw === false) {
            return null;
        }

        $chunks = [];

        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $streamMatches)) {
            foreach ($streamMatches[1] as $streamBody) {
                $decompressed = @gzuncompress($streamBody);

                // Not every stream is FlateDecode (some are already plain,
                // some are images/fonts we don't care about) - only keep
                // ones that actually contain text-showing operators.
                $content = $decompressed !== false ? $decompressed : $streamBody;

                if (! str_contains($content, 'Tj') && ! str_contains($content, 'TJ')) {
                    continue;
                }

                $chunks[] = $this->extractShowTextOperators($content);
            }
        }

        $text = trim(implode("\n", array_filter($chunks, fn ($chunk) => $chunk !== '')));

        return $text !== '' ? $text : null;
    }

    protected function extractShowTextOperators(string $content): string
    {
        $pieces = [];

        // Tj: (single string) Tj
        if (preg_match_all('/\(((?:\\\\.|[^()\\\\])*)\)\s*Tj/s', $content, $matches)) {
            foreach ($matches[1] as $match) {
                $pieces[] = $this->unescapePdfString($match);
            }
        }

        // TJ: [ (string) -120 (string) ... ] TJ - only the string parts matter for plain text.
        if (preg_match_all('/\[((?:[^\[\]]|\\\\.)*)\]\s*TJ/s', $content, $arrayMatches)) {
            foreach ($arrayMatches[1] as $array) {
                if (preg_match_all('/\(((?:\\\\.|[^()\\\\])*)\)/s', $array, $inner)) {
                    $line = implode('', array_map([$this, 'unescapePdfString'], $inner[1]));
                    $pieces[] = $line;
                }
            }
        }

        return implode(' ', $pieces);
    }

    protected function unescapePdfString(string $value): string
    {
        $value = preg_replace_callback('/\\\\([0-7]{1,3})/', fn ($m) => chr((int) octdec($m[1])), $value) ?? $value;

        return strtr($value, [
            '\\(' => '(', '\\)' => ')', '\\\\' => '\\',
            '\\n' => "\n", '\\r' => "\r", '\\t' => "\t",
        ]);
    }

    protected function extractDocx(string $absolutePath): ?string
    {
        if (! class_exists(\ZipArchive::class)) {
            return null;
        }

        $zip = new \ZipArchive;

        if ($zip->open($absolutePath) !== true) {
            return null;
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            return null;
        }

        // Paragraph/line breaks (<w:p>, <w:br>) become newlines before
        // tags are stripped, so paragraphs don't run together into one
        // wall of text.
        $withBreaks = preg_replace('/<\/w:p>|<w:br\s*\/?>/', "\n", $xml) ?? $xml;
        $text = strip_tags($withBreaks);

        return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
