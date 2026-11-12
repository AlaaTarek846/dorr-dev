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
        'milestones.min_amount_minor' => ['name' => 'أقل قيمة للمرحلة', 'controls' => 'أقل قيمة لكل مرحلة لما الشغل يتقسم (الافتراضي 50.00). فاضي = من غير حد.'],
        'project.min_budget_minor' => ['name' => 'أقل ميزانية للمشروع', 'controls' => 'فاضي = من غير حد.'],
        'project.max_budget_minor' => ['name' => 'أكبر ميزانية للمشروع', 'controls' => 'فاضي = من غير حد.'],
        'cancellation.started_freelancer_bp' => ['name' => 'نصيب المحترف لما مرحلة بدأت واتلغت', 'controls' => 'لو مرحلة بدأ فيها الشغل واتلغت باتفاق الطرفين: النسبة دي من قيمة المرحلة (ومن رسم العميل والعمولة) تتحسب للمحترف، والباقي يرجع للعميل. صفر = كله يرجع للعميل.'],
        'ratings.window_days' => ['name' => 'أيام التقييم بعد المشروع', 'controls' => 'الطرفين يقيّموا بعض خلالها بعد قفل المشروع. التقييمين بيظهروا مع بعض لما الاتنين يقيّموا أو المدة تخلص.'],
        'changes.response_days' => ['name' => 'أيام الرد على طلب تعديل', 'controls' => 'المدة اللي الطرف التاني عنده يوافق فيها على تمديد أو تغيير في الاتفاق بعد ما بدأ، وبعدها الطلب بيسقط.'],
        'disputes.response_days' => ['name' => 'أيام الرد في النزاع', 'controls' => 'المدة اللي كل طرف عنده يقدّم فيها أدلته بعد فتح النزاع.'],
        'disputes.appeal_days' => ['name' => 'أيام الاعتراض على قرار النزاع', 'controls' => 'القرار الأول ما بيتنفذش قبلها، وأي طرف يقدر يعترض مرة واحدة فيها فيقرر موظف تاني. صفر = القرار بيتنفذ فوراً من غير اعتراض.'],
        'invitations.max_per_project' => ['name' => 'أقصى عدد دعوات للمشروع الخاص', 'controls' => 'أكتر عدد محترفين العميل يقدر يدعوهم لمشروع واحد (الافتراضي 10).'],
        'services.require_review' => ['name' => 'مراجعة الخدمات الجاهزة قبل النشر', 'controls' => 'لو مفعّل، الخدمة الجديدة أو المعدّلة ما تظهرش للعملاء غير بعد موافقة موظف.'],
        'services.max_active' => ['name' => 'أقصى عدد خدمات جاهزة للمحترف', 'controls' => 'عدد الخدمات المنشورة أو المستنية مراجعة في نفس الوقت (الافتراضي 5).'],
        'services.answer_hours' => ['name' => 'ساعات قبول طلب الخدمة', 'controls' => 'المحترف بيقبل الطلب أو يرفضه خلالها، وبعدها الطلب بينتهي لوحده (الافتراضي 48).'],
        'consultations.require_review' => ['name' => 'مراجعة الاستشارات قبل النشر', 'controls' => 'لو مفعّل، الاستشارة الجديدة أو المعدّلة ما تظهرش للعملاء غير بعد موافقة موظف.'],
        'consultations.max_active' => ['name' => 'أقصى عدد استشارات للمحترف', 'controls' => 'عدد الاستشارات المنشورة أو المستنية مراجعة في نفس الوقت (الافتراضي 5).'],
        'consultations.free_cancel_hours' => ['name' => 'ساعات الإلغاء المجاني للاستشارة', 'controls' => 'العميل يلغي الاستشارة ويسترد كل فلوسه لحد الساعات دي قبل الميعاد؛ بعدها الإلغاء بيدفع للمحترف (الافتراضي 24).'],
        'consultations.response_hours' => ['name' => 'ساعات رد المحترف على طلب الاستشارة', 'controls' => 'المحترف لازم يوافق على طلب الحجز قبل الميعاد؛ لو ما ردّش خلال المدة دي (أو جه الميعاد) الطلب بيتلغي وفلوس العميل بترجع كاملة (الافتراضي 24).'],
        'consultations.complaint_hours' => ['name' => 'ساعات الشكوى بعد الاستشارة', 'controls' => 'بعد ما الجلسة تخلص العميل يقدر يشتكي خلالها، ولو ما اشتكاش الفلوس بتنزل للمحترف (الافتراضي 24).'],
        'consultations.min_notice_hours' => ['name' => 'أقل مهلة لحجز استشارة', 'controls' => 'أقرب ميعاد العميل يقدر يحجزه بعد الساعات دي من دلوقتي (الافتراضي 2).'],
        'consultations.max_days_ahead' => ['name' => 'أبعد يوم لحجز استشارة', 'controls' => 'العميل يشوف المواعيد لحد الأيام دي قدام (الافتراضي 30).'],
        'special_offers.daily_limit' => ['name' => 'أقصى عروض خاصة في اليوم', 'controls' => 'عدد العروض الخاصة اللي المحترف يبعتها لعملائه السابقين في اليوم الواحد (الافتراضي 10).'],
        'special_offers.open_per_client' => ['name' => 'عروض مفتوحة للعميل الواحد', 'controls' => 'كام عرض خاص من نفس المحترف يقدر يستنى رد نفس العميل في نفس الوقت (الافتراضي 1).'],
        'special_offers.cooldown_days' => ['name' => 'مهلة بعد رفض العميل', 'controls' => 'لو العميل رفض عرض خاص، المحترف ما يبعتلوش عرض تاني قبل الأيام دي (الافتراضي 7، صفر = من غير مهلة).'],
        'special_offers.max_valid_days' => ['name' => 'أطول مدة للعرض الخاص', 'controls' => 'أقصى أيام يفضل فيها العرض الخاص مفتوح قبل ما ينتهي لوحده (الافتراضي 14).'],
        'loyalty.max_discount_bp' => ['name' => 'أكبر خصم ولاء', 'controls' => 'أكبر نسبة خصم يقدر المحترف يديها لعميل متكرر في برنامج الولاء بتاعه (الافتراضي 30%).'],
        'loyalty.max_tiers' => ['name' => 'أكتر عدد شرائح في برنامج الولاء', 'controls' => 'كام شريحة خصم (مثلاً بعد شغلتين / بعد 5) يقدر المحترف يحط في برنامجه (الافتراضي 4).'],
        'workroom.tasks_max' => ['name' => 'أقصى عدد مهام في غرفة عمل المشروع', 'controls' => 'لو المشروع وصل للعدد ده، المهام الجديدة بتتمنع والقديمة بتفضل زي ما هي (مفيش مسح ولا قفل مشروع). فاضي = من غير حد (الافتراضي).'],
        'workroom.files_max' => ['name' => 'أقصى عدد ملفات في غرفة عمل المشروع', 'controls' => 'لو المشروع وصل للعدد ده، الملفات الجديدة بتتمنع (الإصدارات الجديدة لملف موجود لسه بتتقبل) والقديمة بتفضل زي ما هي. فاضي = من غير حد (الافتراضي).'],
        'teams.owned_max' => ['name' => 'أقصى عدد فرق المحترف كـ Owner', 'controls' => 'المحترف بيكون مسؤول (Owner) عن كام فريق في نفس الوقت. الافتراضي 1 (فريق واحد). لو وصل للحد، إنشاء فريق جديد أو ترقية نفسه لـ Owner في فريق تاني بيتمنع والقديم بيفضل زي ما هو. فاضي = من غير حد.'],
        'teams.joined_max' => ['name' => 'أقصى عدد فرق ينضم لها المحترف كـ PM أو عضو', 'controls' => 'غير الفرق اللي هو Owner فيها. الافتراضي 5. لو وصل للحد الانضمام لفريق جديد بيتمنع والفرق اللي فيها بتفضل زي ما هي. فاضي = من غير حد.'],
        'teams.members_max' => ['name' => 'أقصى عدد أعضاء في الفريق الواحد', 'controls' => 'بيتحسب الأعضاء النشطين (بما فيهم الـ Owner والـ PM). لو الفريق وصل للحد انضمام عضو جديد بيتمنع والأعضاء الحاليين بيفضلوا. ده حد الفريق اللي من غير باقة؛ الفريق المشترك في باقة فريق بياخد الأكبر بين ده وحد الباقة. فاضي = من غير حد (الافتراضي)، وساعتها الباقة مش بتغيّر حاجة.'],
        'teams.offers_monthly' => ['name' => 'عدد عروض الفريق في الشهر', 'controls' => 'كام عرض يقدر الفريق يبعتهم في الشهر الميلادي، وبيتحسب على الفريق نفسه مش على الـ Owner (عروض الـ Owner الشخصية ليها حدها). العرض اللي بيتسحب قبل ما العميل يشوفه بيرجّع مكانه. ده حد الفريق اللي من غير باقة؛ باقة الفريق بترفعه (أو بتشيله). فاضي = من غير حد (الافتراضي)، والعروض اللي اتبعتت بتفضل لو قللت الرقم.'],
        'teams.projects_max' => ['name' => 'أقصى عدد مشاريع شغّالة للفريق', 'controls' => 'كام مشروع (اتفاق شغّال) يقدر الفريق يشتغل عليهم في نفس الوقت. لو وصل للحد، إرسال عرض جديد باسم الفريق بيتمنع، والمشاريع الشغّالة بتكمّل زي ما هي (تقليل الحد أو الباقة ما بيقطعش مشروع جاري). ده حد الفريق اللي من غير باقة؛ باقة الفريق بترفعه. فاضي = من غير حد (الافتراضي).'],
        'workroom.storage_mb' => ['name' => 'أقصى مساحة ملفات في غرفة عمل المشروع (ميجا)', 'controls' => 'مجموع حجم كل إصدارات ملفات المشروع (اللي ما اتمسحتش). لو الرفع الجديد هيعدّي الحد بيتمنع والقديم بيفضل. حجم الملف الواحد لسه محكوم بالحد العام للرفع. فاضي = من غير حد (الافتراضي).'],
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
        $currency = app(FreelanceMoney::class)->currency();
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
            $kind === 'rate' => rtrim(rtrim(number_format($value / 100, 2, '.', ''), '0'), '.').'%',
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
