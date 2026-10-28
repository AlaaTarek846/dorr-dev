<?php

namespace Modules\Freelance\Services\Features;

use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * The Word file behind Admin → Freelance → Switches: what each switch does (always in Arabic).
 * The rows come from FreelanceFeatures::all(), so a switch added later shows up in the file by itself
 * — add its entry to MEANINGS to describe it.
 */
class FreelanceFeaturesGuide
{
    /** @var array<string, array{name: string, controls: string, task: string}> */
    private const MEANINGS = [
        'freelance' => ['name' => 'خدمة العمل الحر كلها', 'controls' => 'الخدمة كلها في الدولة. لو مقفولة مفيش حاجة بتشتغل: لا المستخدم يشوفها ولا المحترف يشتغل.', 'task' => 'كل المهام'],
        'projects' => ['name' => 'المشاريع (الطلبات)', 'controls' => 'إن العميل ينشئ مشروع (طلب خدمة) من تطبيق دُرر.', 'task' => 'الدفعة 2'],
        'invitations' => ['name' => 'دعوة المحترفين', 'controls' => 'إن العميل يدعو محترف معيّن لمشروعه بدل ما ينتظر العروض.', 'task' => 'الدفعة 2'],
        'attachments' => ['name' => 'المرفقات', 'controls' => 'رفع ملفات وصور على المشروع والتسليم.', 'task' => 'الدفعتان 2 و6'],
        'bidding' => ['name' => 'العروض', 'controls' => 'إن المحترفين يقدّموا عروض أسعار على المشروع.', 'task' => 'الدفعة 3'],
        'free_offer_quota' => ['name' => 'العروض المجانية الشهرية', 'controls' => 'الحصة الشهرية المجانية من العروض لكل محترف. لو مقفولة يتحدد حساب العروض من سياسة الدولة.', 'task' => 'الدفعة 3 و FL-008'],
        'milestones' => ['name' => 'المراحل', 'controls' => 'تقسيم الاتفاق على مراحل. لو مقفولة يبقى المشروع دفعة واحدة.', 'task' => 'الدفعة 4'],
        'chat' => ['name' => 'الشات بين العميل والمحترف', 'controls' => 'المحادثة بين العميل والمحترف على المشروع.', 'task' => 'FL-006'],
        'wallet_payment' => ['name' => 'الدفع المحجوز بين المحافظ', 'controls' => 'العميل يحجز المبلغ، وبعد اعتماد التسليم الفلوس تتحرر للمحترف.', 'task' => 'الدفعة 5'],
        'client_fee' => ['name' => 'رسم العميل', 'controls' => 'الرسم الصغير اللي بيدفعه العميل فوق السعر.', 'task' => 'الدفعة 5 و FL-008'],
        'commission' => ['name' => 'عمولة المحترف', 'controls' => 'عمولة المنصة من المحترف (الأكبر).', 'task' => 'الدفعة 5 و FL-008'],
        'ai_assistant' => ['name' => 'المساعد الذكي', 'controls' => 'مساعد الذكاء الاصطناعي داخل العمل الحر.', 'task' => 'الدفعة 9'],
        'disputes' => ['name' => 'النزاعات', 'controls' => 'فتح نزاع بين العميل والمحترف.', 'task' => 'الدفعة 7'],
        'ratings' => ['name' => 'التقييمات', 'controls' => 'تقييم الطرفين بعد انتهاء المشروع.', 'task' => 'الدفعة 7'],
    ];

    private const NOTES = [
        'المفتاح هنا بيقول «الميزة متاحة» بس. القيم الفعلية (نسبة العمولة، حجم الحصة، المهل) بتتضبط في شاشة «الأسعار والقواعد» وبتكون ديناميكية بالدولة والتخصص.',
        'مفاتيح «رسم العميل» و«عمولة المحترف» و«العروض المجانية الشهرية» ممكن تتقفل من غير ما تقفل «الدفع المحجوز» أو «العروض»، يعني تقدر تشغّل الدفع والعروض مجاناً في دولة معيّنة بدون عمولة.',
        'المفاتيح قابلة للزيادة: كل دفعة مهام بتضيف مفاتيحها، وأي مفتاح جديد بيظهر في الشاشة وفي الملف ده.',
        'زر «نسخ من دولة أخرى» بينسخ كل المفاتيح مرة واحدة، فدولة جديدة تبدأ زي دولة موجودة.',
    ];

    /**
     * @param  array<string, bool>|null  $state  feature => on for the chosen country (null = no column)
     * @return string path of a temporary .docx
     */
    public function build(?string $countryName = null, ?array $state = null): string
    {
        $word = new PhpWord;
        $word->setDefaultFontName('Arial');
        $word->setDefaultFontSize(11);
        $word->getSettings()->setThemeFontLang(new \PhpOffice\PhpWord\Style\Language('ar-SA'));

        $section = $word->addSection(['orientation' => 'landscape']);
        $rtl = ['rtl' => true, 'alignment' => Jc::START, 'spaceAfter' => 120];
        $font = ['rtl' => true];

        $section->addText('مفاتيح الخصائص — العمل الحر', ['rtl' => true, 'bold' => true, 'size' => 20], $rtl);
        $section->addText('تاريخ التنزيل: '.now()->format('Y-m-d'), $font, $rtl);
        $this->heading($section, 'الفكرة');
        $section->addText('كل مفتاح هو switch بتفتحه أو تقفله لكل دولة لوحدها. مفيش قاعدة = مقفول. ولو «خدمة العمل الحر كلها» مقفولة في الدولة، كل المفاتيح التانية بتتقفل مهما كانت حالتها. كده تقدر تفتح الخدمة في دولة واحدة وتجرّب، وتفتح باقي الخصائص واحدة واحدة.', $font, $rtl);

        $this->heading($section, 'المفاتيح');
        $cols = $state === null ? [2200, 2600, 6800, 2200] : [2200, 2600, 6000, 2000, 1400];
        $head = $state === null ? ['المفتاح', 'الاسم', 'بيتحكم في إيه', 'المهمة'] : ['المفتاح', 'الاسم', 'بيتحكم في إيه', 'المهمة', 'الحالة'];
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 70, 'bidiVisual' => true]);
        $table->addRow(null, ['tblHeader' => true]);
        foreach ($head as $i => $label) {
            $table->addCell($cols[$i], ['bgColor' => 'EEEEEE'])->addText($label, ['rtl' => true, 'bold' => true], ['rtl' => true, 'alignment' => Jc::START]);
        }
        foreach (FreelanceFeatures::all() as $feature) {
            $m = self::MEANINGS[$feature] ?? ['name' => $feature, 'controls' => '—', 'task' => '—'];
            $cells = [$feature, $m['name'], $m['controls'], $m['task']];
            if ($state !== null) {
                $cells[] = ($state[$feature] ?? false) ? 'نشط' : 'غير نشط';
            }
            $table->addRow();
            foreach ($cells as $i => $text) {
                $cell = $table->addCell($cols[$i]);
                $isKey = $i === 0;
                $cell->addText($text, $isKey ? ['name' => 'Consolas', 'size' => 9] : ['rtl' => true], $isKey ? ['alignment' => Jc::START] : ['rtl' => true, 'alignment' => Jc::START]);
            }
        }
        if ($state !== null && $countryName !== null) {
            $section->addText('الحالة المعروضة للدولة: '.$countryName, ['rtl' => true, 'italic' => true], $rtl + ['spaceBefore' => 120]);
        }

        $this->heading($section, 'ملاحظات مهمة');
        foreach (self::NOTES as $note) {
            $section->addListItem($note, 0, $font, null, $rtl);
        }

        $path = tempnam(sys_get_temp_dir(), 'flsw').'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);

        return $path;
    }

    private function heading(Section $section, string $text): void
    {
        $section->addText($text, ['rtl' => true, 'bold' => true, 'size' => 14], ['rtl' => true, 'alignment' => Jc::START, 'spaceBefore' => 240, 'spaceAfter' => 80]);
    }
}
