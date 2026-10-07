<?php

namespace Modules\AI\Services\DocumentGeneration;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Renders parsed document blocks (see AiDocumentContentParser) into a real
 * .xlsx via PhpSpreadsheet. Only genuinely tabular blocks become real
 * spreadsheet tables (with headers and per-column data); headings,
 * paragraphs and bullet lists are written as single labelled rows so a
 * non-tabular answer (most chat replies) still produces a readable sheet
 * rather than an empty one.
 */
class AiXlsxDocumentRenderer
{
    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    public function render(string $title, array $blocks): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->safeSheetTitle($title));
        $sheet->setRightToLeft(true);

        $row = 1;
        $maxColumnsUsed = 1;

        foreach ($blocks as $block) {
            [$row, $columnsUsed] = match ($block['type'] ?? null) {
                'heading' => [$this->writeHeading($sheet, $row, $block), 1],
                'paragraph' => [$this->writeParagraph($sheet, $row, $block), 1],
                'bullets' => [$this->writeBullets($sheet, $row, $block), 1],
                'table' => $this->writeTable($sheet, $row, $block),
                default => [$row, 1],
            };

            $maxColumnsUsed = max($maxColumnsUsed, $columnsUsed);
        }

        foreach (range(0, $maxColumnsUsed - 1) as $index) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setAutoSize(true);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'ai-doc-').'.xlsx';
        (new Xlsx($spreadsheet))->save($tempPath);

        $bytes = file_get_contents($tempPath);
        @unlink($tempPath);
        $spreadsheet->disconnectWorksheets();

        return $bytes !== false ? $bytes : '';
    }

    protected function safeSheetTitle(string $title): string
    {
        $clean = preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $title) ?? 'Report';
        $clean = trim($clean) ?: 'Report';

        return mb_substr($clean, 0, 31);
    }

    /**
     * @param  array<string, mixed>  $block
     */
    protected function writeHeading(Worksheet $sheet, int $row, array $block): int
    {
        // Root-cause fix: a spreadsheet cell is read as data, not styled
        // prose, so strip markdown markers (e.g. "**") instead of writing
        // them verbatim - see AiDocumentInlineFormatter for why XLSX gets
        // plain text while PDF/DOCX get real bold/italic runs.
        $sheet->setCellValue("A{$row}", AiDocumentInlineFormatter::toPlainText((string) ($block['text'] ?? '')));

        $size = match ((int) ($block['level'] ?? 2)) {
            1 => 16,
            2 => 13,
            default => 11,
        };

        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize($size);
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        return $row + 2;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    protected function writeParagraph(Worksheet $sheet, int $row, array $block): int
    {
        $sheet->setCellValue("A{$row}", AiDocumentInlineFormatter::toPlainText((string) ($block['text'] ?? '')));
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT)->setWrapText(true);

        return $row + 1;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    protected function writeBullets(Worksheet $sheet, int $row, array $block): int
    {
        foreach ($block['items'] ?? [] as $item) {
            $sheet->setCellValue("A{$row}", '• '.AiDocumentInlineFormatter::toPlainText((string) $item));
            $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $row++;
        }

        return $row + 1;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array{0: int, 1: int}
     */
    protected function writeTable(Worksheet $sheet, int $row, array $block): array
    {
        $headers = array_values($block['headers'] ?? []);
        $rows = $block['rows'] ?? [];

        if ($headers === []) {
            return [$row, 1];
        }

        foreach ($headers as $i => $header) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$col}{$row}", AiDocumentInlineFormatter::toPlainText((string) $header));
        }

        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EFEFEF');
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $row++;

        foreach ($rows as $dataRow) {
            $cells = array_values($dataRow);

            foreach ($headers as $i => $header) {
                $col = Coordinate::stringFromColumnIndex($i + 1);
                $sheet->setCellValue("{$col}{$row}", AiDocumentInlineFormatter::toPlainText((string) ($cells[$i] ?? '')));
                $sheet->getStyle("{$col}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }

            $row++;
        }

        return [$row + 1, count($headers)];
    }
}
