<?php

namespace Modules\AI\Services\DocumentGeneration;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\ListItem;
use PhpOffice\PhpWord\Style\Table;

/**
 * Renders parsed document blocks (see AiDocumentContentParser) into a real
 * .docx via PhpWord. Unlike PDF, Word documents only reference a font by
 * NAME - the reader's own copy of Word/LibreOffice supplies the actual
 * Arabic glyphs - so no font embedding is needed here, only the RTL
 * paragraph/run flags PhpWord exposes.
 */
class AiDocxDocumentRenderer
{
    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    public function render(string $title, array $blocks): string
    {
        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(12);

        $section = $phpWord->addSection();

        foreach ($blocks as $block) {
            match ($block['type'] ?? null) {
                // Root-cause fix: addText() writes one raw string with no
                // markdown interpretation at all, so a reply using
                // "**bold**" (completely normal model output) showed up in
                // the .docx as literal asterisks - addFormattedText() below
                // splits the text into PhpWord's own addTextRun() runs so
                // real **bold**/*italic* spans render as real bold/italic
                // Word formatting instead. See AiDocumentInlineFormatter
                // for the shared span parser this routes through.
                'heading' => $this->addFormattedText(
                    $section,
                    (string) ($block['text'] ?? ''),
                    $this->headingFontStyle((int) ($block['level'] ?? 2)),
                    $this->rtlParagraphStyle(Jc::END, $block['level'] === 1 ? 240 : 160)
                ),
                'paragraph' => $this->addFormattedText(
                    $section,
                    (string) ($block['text'] ?? ''),
                    ['rtl' => true],
                    $this->rtlParagraphStyle(Jc::BOTH, 160)
                ),
                'bullets' => $this->addBullets($section, $block['items'] ?? []),
                'table' => $this->addTable($section, $block),
                default => null,
            };
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'ai-doc-').'.docx';

        IOFactory::createWriter($phpWord, 'Word2007')->save($tempPath);

        $bytes = file_get_contents($tempPath);
        @unlink($tempPath);

        return $bytes !== false ? $bytes : '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function headingFontStyle(int $level): array
    {
        return match ($level) {
            1 => ['bold' => true, 'size' => 20, 'rtl' => true],
            2 => ['bold' => true, 'size' => 15, 'rtl' => true],
            default => ['bold' => true, 'size' => 13, 'rtl' => true],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function rtlParagraphStyle(string $alignment, int $spaceAfter): array
    {
        return ['rtl' => true, 'bidi' => true, 'alignment' => $alignment, 'spaceAfter' => $spaceAfter];
    }

    /**
     * @param  list<string>  $items
     */
    protected function addBullets($section, array $items): void
    {
        foreach ($items as $item) {
            // addListItem() (unlike addListItemRun()) only ever accepts one
            // raw text string, so bullets need the run-based list API to
            // get real bold/italic spans instead of literal "**" markers.
            // addListItemRun() only accepts (depth, listStyle, pStyle) - no
            // 4th font-style argument like addListItem() has - so the 'rtl'
            // flag has to go on each run's addText() call instead (done in
            // appendSpans() below), not on the list item itself.
            $run = $section->addListItemRun(
                0,
                ['listType' => ListItem::TYPE_BULLET_FILLED],
                $this->rtlParagraphStyle(Jc::END, 80)
            );

            $this->appendSpans($run, (string) $item, ['rtl' => true]);
        }
    }

    /**
     * Writes $text into $container as one or more styled runs, splitting
     * on inline markdown spans (bold/italic/code) from the shared parser.
     * $container is anything exposing PhpWord's addTextRun() - a Section
     * or a table Cell.
     *
     * @param  array<string, mixed>  $runStyle  base style merged with each span's own bold/italic/code
     * @param  array<string, mixed>|null  $paragraphStyle
     */
    protected function addFormattedText($container, string $text, array $runStyle, ?array $paragraphStyle = null): void
    {
        $run = $container->addTextRun($paragraphStyle);

        $this->appendSpans($run, $text, $runStyle);
    }

    /**
     * @param  array<string, mixed>  $runStyle
     */
    protected function appendSpans($run, string $text, array $runStyle): void
    {
        $spans = AiDocumentInlineFormatter::parseSpans($text);

        if ($spans === []) {
            $spans = [['text' => '', 'bold' => false, 'italic' => false, 'code' => false]];
        }

        foreach ($spans as $span) {
            $style = $runStyle;

            if ($span['bold']) {
                $style['bold'] = true;
            }

            if ($span['italic']) {
                $style['italic'] = true;
            }

            if ($span['code']) {
                $style['name'] = 'Courier New';
            }

            $run->addText($span['text'], $style);
        }
    }

    /**
     * @param  array<string, mixed>  $block
     */
    protected function addTable($section, array $block): void
    {
        $headers = array_values($block['headers'] ?? []);
        $rows = $block['rows'] ?? [];

        if ($headers === []) {
            return;
        }

        $table = $section->addTable([
            'borderSize' => 6,
            'borderColor' => '999999',
            'width' => 100 * 50,
            'unit' => 'pct',
            'layout' => Table::LAYOUT_AUTO,
        ]);

        $table->addRow();
        foreach ($headers as $header) {
            $cell = $table->addCell(2000, ['shading' => ['fill' => 'EFEFEF']]);
            $this->addFormattedText($cell, (string) $header, ['bold' => true, 'rtl' => true], ['alignment' => Jc::CENTER, 'rtl' => true]);
        }

        foreach ($rows as $row) {
            $table->addRow();
            $cells = array_values($row);

            foreach ($headers as $i => $header) {
                $value = $cells[$i] ?? '';
                $cell = $table->addCell(2000);
                $this->addFormattedText($cell, (string) $value, ['rtl' => true], ['alignment' => Jc::CENTER, 'rtl' => true]);
            }
        }
    }
}
