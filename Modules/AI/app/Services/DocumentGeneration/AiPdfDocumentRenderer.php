<?php

namespace Modules\AI\Services\DocumentGeneration;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;

/**
 * Renders parsed document blocks (see AiDocumentContentParser) into a real,
 * Arabic-correct PDF via dompdf. Uses the project's own bundled Cairo font
 * (public/app/fonts/cairo.ttf - already shipped for the app's own Arabic
 * UI) registered directly with dompdf's font metrics, so Arabic shaping and
 * RTL bidi are handled by dompdf/the font itself rather than any custom
 * logic here.
 */
class AiPdfDocumentRenderer
{
    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    public function render(string $title, array $blocks): string
    {
        $fontDir = storage_path('fonts');
        File::ensureDirectoryExists($fontDir);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Cairo');
        $options->set('fontDir', $fontDir);
        $options->set('fontCache', $fontDir);
        $options->set('chroot', [public_path(), storage_path()]);

        $dompdf = new Dompdf($options);

        $fontPath = public_path('app/fonts/cairo.ttf');

        if (is_file($fontPath)) {
            $metrics = $dompdf->getFontMetrics();

            // Only one real weight is bundled (Regular) - registering it
            // for both normal and bold means bold text still renders in
            // Arabic (just without true boldness) instead of silently
            // falling back to a font with no Arabic glyphs at all.
            foreach (['normal', 'bold'] as $weight) {
                $metrics->registerFont(['family' => 'Cairo', 'style' => 'normal', 'weight' => $weight], $fontPath);
            }
        }

        $dompdf->loadHtml($this->buildHtml($title, $blocks), 'UTF-8');
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    protected function buildHtml(string $title, array $blocks): string
    {
        $body = '';

        foreach ($blocks as $block) {
            $body .= match ($block['type'] ?? null) {
                'heading' => sprintf(
                    '<h%1$d>%2$s</h%1$d>',
                    min(max((int) ($block['level'] ?? 2), 1), 3),
                    e($block['text'] ?? '')
                ),
                'paragraph' => '<p>'.nl2br(e($block['text'] ?? '')).'</p>',
                'bullets' => '<ul>'.implode('', array_map(
                    fn ($item) => '<li>'.e($item).'</li>',
                    $block['items'] ?? []
                )).'</ul>',
                'table' => $this->renderTable($block),
                default => '',
            };
        }

        $safeTitle = e($title);

        return <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>{$safeTitle}</title>
<style>
* { font-family: 'Cairo', 'DejaVu Sans', sans-serif; }
body { direction: rtl; text-align: right; font-size: 13px; line-height: 1.9; color: #1a1a1a; margin: 28px; }
h1 { font-size: 22px; margin: 0 0 18px; border-bottom: 2px solid #333; padding-bottom: 10px; }
h2 { font-size: 17px; margin: 22px 0 8px; color: #222; }
h3 { font-size: 14px; margin: 16px 0 6px; color: #333; }
p { margin: 0 0 10px; }
ul { margin: 0 0 14px; padding-right: 22px; padding-left: 0; list-style-position: outside; }
li { margin-bottom: 5px; }
table { width: 100%; border-collapse: collapse; margin: 14px 0 20px; }
th, td { border: 1px solid #ccc; padding: 7px 9px; text-align: right; font-size: 12px; }
th { background: #f2f2f2; font-weight: bold; }
</style>
</head>
<body>
{$body}
</body>
</html>
HTML;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    protected function renderTable(array $block): string
    {
        $headers = $block['headers'] ?? [];
        $rows = $block['rows'] ?? [];

        $thead = '<thead><tr>'.implode('', array_map(fn ($h) => '<th>'.e($h).'</th>', $headers)).'</tr></thead>';

        $tbody = '<tbody>';
        foreach ($rows as $row) {
            $tbody .= '<tr>'.implode('', array_map(fn ($c) => '<td>'.e($c).'</td>', $row)).'</tr>';
        }
        $tbody .= '</tbody>';

        return '<table>'.$thead.$tbody.'</table>';
    }
}
