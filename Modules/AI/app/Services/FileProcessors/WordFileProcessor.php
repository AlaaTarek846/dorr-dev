<?php

namespace Modules\AI\Services\FileProcessors;

use PhpOffice\PhpWord\Element\Image;
use PhpOffice\PhpWord\Element\Link;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;

/**
 * Phase 1: replaced the hand-rolled ZipArchive/word-document.xml reader
 * with phpoffice/phpword for DOCX. Phase 2 adds: legacy .doc support
 * (phpword's own bundled MsDoc reader - already in vendor/, never used -
 * doc S12/S15: a real, dedicated reader, not DOCX-parser-pretending-to-
 * read-DOC), normalized blocks (heading/paragraph/list/table/
 * image_reference) instead of a flattened "# heading"/"cell | cell" text
 * dump, and document metadata via PhpWord's DocInfo.
 */
class WordFileProcessor implements AiFileProcessorInterface
{
    protected const MIME_DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    protected const MIME_DOC = 'application/msword';

    protected const SUPPORTED = [self::MIME_DOCX, self::MIME_DOC];

    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, self::SUPPORTED, true);
    }

    public function process(string $absolutePath, string $mimeType): AiFileProcessingResult
    {
        if (! $this->supports($mimeType)) {
            return AiFileProcessingResult::failed('unsupported_mime_type');
        }

        $readerName = $mimeType === self::MIME_DOC ? 'MsDoc' : 'Word2007';

        try {
            $phpWord = IOFactory::load($absolutePath, $readerName);
        } catch (\Throwable) {
            // Doc S15: a DOC/DOCX this reader genuinely cannot open is a
            // clean, named failure - never a silent empty "ready" row.
            return AiFileProcessingResult::failed(
                $mimeType === self::MIME_DOC ? 'DOC_PROCESSOR_UNAVAILABLE' : 'DOCX_PARSE_FAILED',
            );
        }

        $blocks = [];
        $warnings = [];
        $tableCount = 0;
        $imageCount = 0;
        $pendingListItems = [];
        $sections = $phpWord->getSections();
        $this->docxHeadings = $mimeType === self::MIME_DOCX ? $this->readDocxHeadings($absolutePath) : [];

        foreach ($sections as $section) {
            $this->walk($section->getElements(), $blocks, $pendingListItems, $tableCount, $imageCount, $warnings);
        }
        $this->flushList($pendingListItems, $blocks);

        $text = trim(implode("\n", array_map(fn ($b) => $this->blockToText($b), $blocks)));

        if ($text === '' && $imageCount === 0) {
            return AiFileProcessingResult::failed('no_text_found');
        }

        $docInfo = null;

        try {
            $docInfo = $phpWord->getDocInfo();
        } catch (\Throwable) {
            // Doc S9: optional metadata missing must never fail the document.
        }

        return AiFileProcessingResult::ok(
            text: $text !== '' ? $text : null,
            metadata: [
                'table_count' => $tableCount,
                'image_count' => $imageCount,
                'section_count' => count($sections),
                'title' => $docInfo?->getTitle(),
                'author' => $docInfo?->getCreator(),
                'subject' => $docInfo?->getSubject(),
                'created_at' => $docInfo?->getCreated(),
                'modified_at' => $docInfo?->getModified(),
            ],
            blocks: $blocks,
            warnings: $warnings,
            documentType: $mimeType === self::MIME_DOC ? 'doc' : 'docx',
        );
    }

    /**
     * @param  list<mixed>  $elements
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<string>  $pendingListItems
     * @param  list<string>  $warnings
     */
    protected function walk(array $elements, array &$blocks, array &$pendingListItems, int &$tableCount, int &$imageCount, array &$warnings): void
    {
        foreach ($elements as $element) {
            if ($element instanceof ListItem || $element instanceof ListItemRun) {
                $itemText = trim($this->elementText($element));

                if ($itemText !== '') {
                    $pendingListItems[] = $itemText;
                }

                continue;
            }

            // Any non-list element ends a run of consecutive list items.
            $this->flushList($pendingListItems, $blocks);

            if ($element instanceof Title) {
                $heading = trim($this->elementText($element));

                if ($heading !== '') {
                    $blocks[] = [
                        'type' => 'heading',
                        'level' => (int) ($element->getDepth() ?: 1),
                        'text' => $heading,
                    ];
                }
            } elseif ($element instanceof Table) {
                $tableCount++;
                $this->extractTable($element, $blocks);
            } elseif ($element instanceof Image) {
                $imageCount++;
                $blocks[] = ['type' => 'image_reference', 'index' => $imageCount];
            } elseif ($element instanceof Link) {
                $linkText = trim((string) ($element->getText() ?: $element->getSource()));

                if ($linkText !== '') {
                    $blocks[] = ['type' => 'link', 'text' => $linkText, 'url' => (string) $element->getSource()];
                }
            } elseif ($element instanceof TextRun || $element instanceof Text) {
                $line = trim($this->elementText($element));

                if ($line !== '') {
                    // A real .docx has no Title elements when read back: its headings are plain
                    // paragraphs whose style is named "Heading1", "Title", "عنوان 1"...
                    $level = $this->takeHeadingLevel($line);

                    $blocks[] = $level !== null
                        ? ['type' => 'heading', 'level' => $level, 'text' => $line]
                        : ['type' => 'paragraph', 'text' => $line];
                }
            } elseif (method_exists($element, 'getElements')) {
                $this->walk($element->getElements(), $blocks, $pendingListItems, $tableCount, $imageCount, $warnings);
            }
        }
    }

    /**
     * @param  list<string>  $pendingListItems
     * @param  list<array<string, mixed>>  $blocks
     */
    protected function flushList(array &$pendingListItems, array &$blocks): void
    {
        if ($pendingListItems === []) {
            return;
        }

        $blocks[] = ['type' => 'list', 'items' => $pendingListItems];
        $pendingListItems = [];
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    protected function extractTable(Table $table, array &$blocks): void
    {
        $rows = [];

        foreach ($table->getRows() as $row) {
            $cells = [];

            foreach ($row->getCells() as $cell) {
                $cellText = trim(implode(' ', array_map(
                    fn ($cellElement) => $this->elementText($cellElement),
                    $cell->getElements(),
                )));
                $cells[] = $cellText;
            }

            if (array_filter($cells) !== []) {
                $rows[] = $cells;
            }
        }

        if ($rows === []) {
            return;
        }

        // First row is treated as the header - doc S13's example shape -
        // matching this codebase's own ExcelFileProcessor convention of
        // treating the first row specially where a header makes sense.
        $blocks[] = [
            'type' => 'table',
            'headers' => $rows[0],
            'rows' => array_slice($rows, 1),
        ];
    }

    protected function blockToText(array $block): string
    {
        return match ($block['type']) {
            'heading' => str_repeat('#', max(1, (int) ($block['level'] ?? 1))).' '.$block['text'],
            'paragraph', 'link' => $block['text'],
            'list' => implode("\n", array_map(fn ($i) => '- '.$i, $block['items'])),
            'table' => implode("\n", array_map(
                fn ($row) => implode(' | ', $row),
                [$block['headers'], ...$block['rows']],
            )),
            'image_reference' => '',
            default => '',
        };
    }

    /**
     * Heading text => remaining levels, read straight from word/document.xml. PhpWord drops paragraph
     * style names when it reads a real .docx back, so without this every heading would be flattened
     * into an ordinary paragraph.
     *
     * @var array<string, list<int>>
     */
    protected array $docxHeadings = [];

    /**
     * @return array<string, list<int>>
     */
    protected function readDocxHeadings(string $absolutePath): array
    {
        $zip = new \ZipArchive();

        if ($zip->open($absolutePath) !== true) {
            return [];
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (! is_string($xml) || $xml === '') {
            return [];
        }

        $dom = new \DOMDocument();

        if (! @$dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
            return [];
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $found = [];

        foreach ($xpath->query('//w:p[w:pPr]') ?: [] as $paragraph) {
            $level = null;
            $style = $xpath->evaluate('string(w:pPr/w:pStyle/@w:val)', $paragraph);

            if (is_string($style) && preg_match('/^\s*(?:heading|title|subtitle|عنوان)\s*(\d)?\s*$/iu', $style, $m) === 1) {
                $level = isset($m[1]) && $m[1] !== '' ? (int) $m[1] : 1;
            } elseif ($xpath->evaluate('count(w:pPr/w:outlineLvl)', $paragraph) > 0) {
                $outline = $xpath->evaluate('string(w:pPr/w:outlineLvl/@w:val)', $paragraph);
                $level = is_numeric($outline) ? ((int) $outline) + 1 : null;
            }

            if ($level === null) {
                continue;
            }

            $text = '';

            foreach ($xpath->query('.//w:t', $paragraph) ?: [] as $node) {
                $text .= $node->textContent;
            }

            $text = trim((string) preg_replace('/\s+/u', ' ', $text));

            if ($text !== '') {
                $found[$text][] = max(1, min(6, $level));
            }
        }

        return $found;
    }

    /** Consumes and returns the heading level recorded for this paragraph text, if any. */
    protected function takeHeadingLevel(string $line): ?int
    {
        $key = trim((string) preg_replace('/\s+/u', ' ', $line));

        if ($key === '' || empty($this->docxHeadings[$key])) {
            return null;
        }

        return array_shift($this->docxHeadings[$key]);
    }

    protected function elementText(mixed $element): string
    {
        if ($element instanceof Text) {
            return (string) $element->getText();
        }

        if ($element instanceof Title) {
            return (string) $element->getText();
        }

        if ($element instanceof TextRun) {
            return implode('', array_map(
                fn ($child) => $this->elementText($child),
                $element->getElements(),
            ));
        }

        if (method_exists($element, 'getText')) {
            $value = $element->getText();

            return is_string($value) ? $value : '';
        }

        return '';
    }
}
