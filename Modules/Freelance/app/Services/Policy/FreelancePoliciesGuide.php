<?php

namespace Modules\Freelance\Services\Policy;

use App\Models\Country;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * The Word file behind Admin → Freelance → Prices & rules (always Arabic): how the page works, every
 * number with its meaning, default and the chosen country's current value, and a worked example
 * computed by FreelanceFees itself. The rows come from FreelancePolicyData::KEYS, so a new key shows up
 * on its own — describe it in MEANINGS.
 */
class FreelancePoliciesGuide
{
    /** @var array<string, array{name: string, controls: string}> */
    private const MEANINGS = [
        'commission.type' => ['name' => 'نوع عمولة المحترف', 'controls' => 'نسبة من المبلغ، أو مبلغ ثابت.'],
        'commission.value' => ['name' => 'قيمة عمولة المحترف', 'controls' => 'بتتخصم من المحترف عند تحرير الدفعة. نسبة بالمئة أو مبلغ بعملة الدولة حسب النوع.'],
        'commission.min_minor' => ['name' => 'أقل عمولة', 'controls' => 'لو العمولة طلعت أقل منها بتترفع لها. فاضي = من غير حد.'],
        'commission.max_minor' => ['name' => 'أقصى عمولة', 'controls' => 'لو العمولة طلعت أكبر منها بتتخفض لها. فاضي = من غير حد.'],
        'client_fee.type' => ['name' => 'نوع رسم العميل', 'controls' => 'نسبة من المبلغ، أو مبلغ ثابت.'],
        'client_fee.value' => ['name' => 'قيمة رسم العميل', 'controls' => 'بيدفعه العميل فوق سعر المرحلة. نسبة بالمئة أو مبلغ حسب النوع.'],
        'client_fee.min_minor' => ['name' => 'أقل رسم للعميل', 'controls' => 'فاضي = من غير حد.'],
        'client_fee.max_minor' => ['name' => 'أقصى رسم للعميل', 'controls' => 'فاضي = من غير حد.'],
        'offers.free_monthly_quota' => ['name' => 'العروض المجانية في الشهر', 'controls' => 'عدد العروض اللي المحترف يقدر يقدمها كل شهر من غير ما تتحسب عليه.'],
        'offers.window_days' => ['name' => 'أيام استقبال العروض', 'controls' => 'المدة اللي المشروع بيستقبل فيها عروض بعد نشره.'],
        'offers.max_per_project' => ['name' => 'أقصى عدد عروض للمشروع', 'controls' => 'بعده المشروع بيقفل استقبال العروض.'],
        'review.days' => ['name' => 'أيام مراجعة العميل', 'controls' => 'مهلة العميل لمراجعة التسليم قبل الاعتماد التلقائي.'],
        'review.auto_approve' => ['name' => 'الاعتماد التلقائي', 'controls' => 'لو مفعّل، التسليم بيتعتمد لوحده بعد انتهاء أيام المراجعة وتتحرر الدفعة.'],
        'review.free_revisions' => ['name' => 'جولات التعديل المجانية', 'controls' => 'عدد المرات اللي العميل يقدر يطلب فيها تعديلات من غير مقابل.'],
        'milestones.max_count' => ['name' => 'أقصى عدد مراحل', 'controls' => 'أكبر عدد مراحل يتقسم عليه الاتفاق.'],
        'milestones.min_amount_minor' => ['name' => 'أقل قيمة للمرحلة', 'controls' => 'فاضي = من غير حد.'],
        'project.min_budget_minor' => ['name' => 'أقل ميزانية للمشروع', 'controls' => 'فاضي = من غير حد.'],
        'project.max_budget_minor' => ['name' => 'أكبر ميزانية للمشروع', 'controls' => 'فاضي = من غير حد.'],
        'earnings.hold_days' => ['name' => 'أيام تعليق الأرباح', 'controls' => 'الأيام اللي تفضل فيها الأرباح معلقة قبل ما تبقى متاحة للمحترف. صفر = متاحة فوراً.'],
    ];

    private const NOTES = [
        'الاتفاق بيحتفظ بنسخة من الأرقام وقت القبول، فتعديل الأسعار بعد كده ما بيأثرش على شغل اتفق عليه.',
        'الحفظ بيرفض: مفتاح غير معروف، قيمة سالبة، نسبة فوق 100%، حد أدنى أكبر من الحد الأقصى، وتعديل على الدولة يبوّظ أرقام تخصص تابع لها.',
        'نسخ سياسة دولة على دولة بعملة مختلفة بينقل النسب والأعداد والأيام والتفعيلات بس. المبالغ والرسم الثابت (ونوعه) بتتخطى وبتظهرلك أسماءها عشان تضبطها بعملة الدولة الجديدة.',
        'قائمة التخصصات بتتفعّل مع كتالوج التخصصات؛ لحد ساعتها كل الأرقام على مستوى الدولة.',
    ];

    public function __construct(private readonly FreelancePolicies $policies) {}

    /** @return string path of a temporary .docx */
    public function build(Country $country): string
    {
        $country->loadMissing('currency');
        $currency = $country->currency;
        $symbol = $currency?->symbol ?: ($currency?->code ?? '');
        $places = (int) ($currency?->decimal_places ?? 2);
        $policy = $this->policies->for($country->id);
        $values = $policy->all();
        $defaults = FreelancePolicyData::defaults();

        $word = new PhpWord;
        $word->setDefaultFontName('Arial');
        $word->setDefaultFontSize(11);
        $word->getSettings()->setThemeFontLang(new \PhpOffice\PhpWord\Style\Language('ar-SA'));
        $section = $word->addSection(['orientation' => 'landscape']);
        $rtl = ['rtl' => true, 'alignment' => Jc::START, 'spaceAfter' => 120];
        $font = ['rtl' => true];

        $section->addText('الأسعار والقواعد — العمل الحر', ['rtl' => true, 'bold' => true, 'size' => 20], $rtl);
        $section->addText('الدولة: '.($country->translatedName() ?? $country->code).' · العملة: '.($currency?->code ?? '—').' · تاريخ التنزيل: '.now()->format('Y-m-d'), $font, $rtl);

        $this->heading($section, 'الفكرة');
        $section->addText('الأرقام اللي العمل الحر ماشي عليها، بتتضبط لكل دولة، ولكل تخصص داخل الدولة لو محتاج أرقام خاصة. كل قيمة بتيجي من أدق نطاق محددها (الدولة + التخصص ثم الدولة ثم الافتراضي). المبالغ بالعملة الصغرى لعملة الدولة نفسها، والنسب بتتكتب في الشاشة بالمئة.', $font, $rtl);

        $this->heading($section, 'إزاي تستخدم الصفحة');
        foreach ([
            'اختار الدولة (والتخصص لما يتفعّل) من فوق، والعملة بتظهر جنبها.',
            'كل رقم بيظهر مع «الموروث». القلم بيخليه قيمة خاصة بالنطاق ده، والسهم بيرجّعه للموروث.',
            'المعاينة على اليمين بتحسب لك على أي مبلغ: العميل يدفع كام، والعمولة، وصافي المحترف، قبل الحفظ.',
            'زر الحفظ تحت بيحفظ كل التعديلات مرة واحدة، و«نسخ من دولة أخرى» بينسخ نطاقات دولة على دولة.',
        ] as $step) {
            $section->addListItem($step, 0, $font, null, $rtl);
        }

        $this->heading($section, 'الأرقام');
        $cols = [2300, 2500, 4800, 1900, 1900];
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 70, 'bidiVisual' => true]);
        $table->addRow(null, ['tblHeader' => true]);
        foreach (['المفتاح', 'الاسم', 'بيتحكم في إيه', 'الافتراضي', 'القيمة الحالية'] as $i => $label) {
            $table->addCell($cols[$i], ['bgColor' => 'EEEEEE'])->addText($label, ['rtl' => true, 'bold' => true], ['rtl' => true, 'alignment' => Jc::START]);
        }
        foreach (array_keys(FreelancePolicyData::KEYS) as $key) {
            $m = self::MEANINGS[$key] ?? ['name' => $key, 'controls' => '—'];
            $table->addRow();
            foreach ([$key, $m['name'], $m['controls'], $this->show($key, $defaults[$key] ?? null, $defaults, $symbol, $places), $this->show($key, $values[$key] ?? null, $values, $symbol, $places)] as $i => $text) {
                $table->addCell($cols[$i])->addText($text, $i === 0 ? ['name' => 'Consolas', 'size' => 9] : ['rtl' => true], ['rtl' => $i !== 0, 'alignment' => Jc::START]);
            }
        }

        $factor = 10 ** $places;
        $example = FreelanceFees::breakdown(1000 * $factor, $policy);
        $this->heading($section, 'مثال محسوب بأرقام الدولة الحالية');
        $section->addText("مشروع قيمته 1000 {$symbol}:", $font, $rtl);
        foreach ([
            'رسم العميل' => $example['client_fee_minor'],
            'العميل يدفع' => $example['client_total_minor'],
            'عمولة المحترف' => $example['commission_minor'],
            'المحترف ياخد' => $example['freelancer_net_minor'],
        ] as $label => $minor) {
            $section->addListItem($label.': '.$this->money($minor, $symbol, $places), 0, $font, null, $rtl);
        }
        $section->addText('العميل يدفع = المبلغ + رسم العميل. المحترف ياخد = المبلغ − العمولة. النسبة بتتقرّب لأقرب وحدة صغرى ثم بيتطبّق الحد الأدنى والأقصى، والعمولة ما تعدّيش المبلغ نفسه.', ['rtl' => true, 'italic' => true], $rtl);

        $this->heading($section, 'ملاحظات مهمة');
        foreach (self::NOTES as $note) {
            $section->addListItem($note, 0, $font, null, $rtl);
        }

        $path = tempnam(sys_get_temp_dir(), 'flpol').'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);

        return $path;
    }

    /** @param array<string, mixed> $values */
    private function show(string $key, mixed $value, array $values, string $symbol, int $places): string
    {
        $kind = FreelancePolicyData::KEYS[$key]['kind'] ?? null;

        return match (true) {
            $kind === 'bool' => $value ? 'مفعّل' : 'مقفول',
            $kind === 'enum' => $value === 'fixed' ? 'مبلغ ثابت' : 'نسبة',
            $value === null => 'من غير حد',
            FreelancePolicyData::isMoney($key, $values) => $this->money((int) $value, $symbol, $places),
            $kind === 'amount' => rtrim(rtrim(number_format($value / 100, 2, '.', ''), '0'), '.').'%',
            str_contains($key, 'days') => $value.' يوم',
            default => (string) $value,
        };
    }

    private function money(int $minor, string $symbol, int $places): string
    {
        return number_format($minor / (10 ** $places), $places, '.', ',').' '.$symbol;
    }

    private function heading(Section $section, string $text): void
    {
        $section->addText($text, ['rtl' => true, 'bold' => true, 'size' => 14], ['rtl' => true, 'alignment' => Jc::START, 'spaceBefore' => 240, 'spaceAfter' => 80]);
    }
}
