<?php

namespace Modules\AI\Services\DocumentGeneration;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

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
        $phpWord = new PhpWord();
        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(12);

        $section = $phpWord->addSection();

        foreach ($blocks as $block) {
            match ($block['type'] ?? null) {
                'heading' => $section->addText(
                    $block['text'] ?? '',
                    $this->headingFontStyle((int) ($block['level'] ?? 2)),
                    $this->rtlParagraphStyle(Jc::END, $block['level'] === 1 ? 240 : 160)
                ),
                'paragraph' => $section->addText(
                    $block['text'] ?? '',
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
            $section->addListItem(
                $item,
                0,
                ['rtl' => true],
                ['listType' => \PhpOffice\PhpWord\Style\ListItem::TYPE_BULLET_FILLED],
                $this->rtlParagraphStyle(Jc::END, 80)
            );
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
            'layout' => \PhpOffice\PhpWord\Style\Table::LAYOUT_AUTO,
        ]);

        $table->addRow();
        foreach ($headers as $header) {
            $cell = $table->addCell(2000, ['shading' => ['fill' => 'EFEFEF']]);
            $cell->addText($header, ['bold' => true, 'rtl' => true], ['alignment' => Jc::CENTER, 'rtl' => true]);
        }

        foreach ($rows as $row) {
            $table->addRow();
            $cells = array_values($row);

            foreach ($headers as $i => $header) {
                $value = $cells[$i] ?? '';
                $cell = $table->addCell(2000);
                $cell->addText((string) $value, ['rtl' => true], ['alignment' => Jc::CENTER, 'rtl' => true]);
            }
        }
    }
}
