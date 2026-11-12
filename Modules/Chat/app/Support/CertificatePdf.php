<?php

namespace Modules\Chat\Support;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;

/**
 * A certificate as a PDF (docs/groups_tasks.md GR-028): landscape, Arabic-correct with the bundled
 * Cairo font — the same way the AI module renders its Arabic PDFs (AiPdfDocumentRenderer).
 */
final class CertificatePdf
{
    public static function render(string $student, string $title, ?string $reason, string $group, string $teacher, string $date): string
    {
        $fontDir = storage_path('fonts');
        File::ensureDirectoryExists($fontDir);

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Cairo');
        $options->set('fontDir', $fontDir);
        $options->set('fontCache', $fontDir);
        $options->set('chroot', [public_path(), storage_path()]);

        $dompdf = new Dompdf($options);
        $font = public_path('app/fonts/cairo.ttf');
        if (is_file($font)) {
            foreach (['normal', 'bold'] as $weight) {
                $dompdf->getFontMetrics()->registerFont(['family' => 'Cairo', 'style' => 'normal', 'weight' => $weight], $font);
            }
        }

        [$student, $title, $reason, $group, $teacher, $date] = array_map('e', [$student, $title, (string) $reason, $group, $teacher, $date]);
        $heading = e(__('chat.certificate.heading'));
        $givenTo = e(__('chat.certificate.given_to'));
        $from = e(__('chat.certificate.from'));
        $dir = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';

        $dompdf->loadHtml(<<<HTML
<!DOCTYPE html>
<html dir="{$dir}"><head><meta charset="UTF-8"><style>
* { font-family: 'Cairo', 'DejaVu Sans', sans-serif; }
body { margin: 0; direction: {$dir}; text-align: center; color: #1f2937; }
.frame { margin: 26px; padding: 34px 40px; border: 6px double #be185d; height: 470px; }
.heading { font-size: 34px; color: #be185d; margin: 0 0 6px; }
.title { font-size: 20px; margin: 0 0 26px; color: #374151; }
.given { font-size: 15px; color: #6b7280; margin: 0; }
.student { font-size: 32px; margin: 6px 0 18px; border-bottom: 1px solid #e5e7eb; display: inline-block; padding: 0 40px 6px; }
.reason { font-size: 15px; margin: 0 0 30px; color: #374151; }
.foot { font-size: 13px; color: #6b7280; }
</style></head><body><div class="frame">
<p class="heading">{$heading}</p>
<p class="title">{$title}</p>
<p class="given">{$givenTo}</p>
<p class="student">{$student}</p>
<p class="reason">{$reason}</p>
<p class="foot">{$from} {$teacher} · {$group} · {$date}</p>
</div></body></html>
HTML, 'UTF-8');
        $dompdf->setPaper('a4', 'landscape');
        $dompdf->render();

        return $dompdf->output();
    }
}
