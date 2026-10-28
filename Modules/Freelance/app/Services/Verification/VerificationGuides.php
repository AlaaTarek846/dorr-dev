<?php

namespace Modules\Freelance\Services\Verification;

use App\Models\Country;
use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Freelance\Models\FreelanceDocumentRequirement;
use Modules\Freelance\Models\FreelanceDocumentType;
use Modules\Freelance\Models\FreelanceVerificationRequest;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * The Word files behind the two verification screens (always Arabic): «مستندات التوثيق» and «طلبات التوثيق». Built
 * from the real data (the document types and what each country requires; the statuses and the queue) so they never
 * drift from the screens.
 */
class VerificationGuides
{
    /** What each account status means and what the freelancer can do in it. */
    private const STATUSES = [
        'registered' => ['دخل بالجوال بس', 'يتصفح ويكمّل ملفه. مفيش شغل.'],
        'profile_ready' => ['كمّل الاسم ووافق على الشروط', 'يرفع المستندات ويقدّم طلب التوثيق.'],
        'verification_submitted' => ['طلبه قيد المراجعة', 'مستنداته مقفولة لحد القرار.'],
        'verified' => ['الأدمن اعتمده', 'يقدّم عروض ويقبل دعوات ويستلم فلوس.'],
        'verification_rejected' => ['اترفض بسبب ظاهر له', 'يرفع مستندات جديدة ويقدّم تاني.'],
        'changes_requested' => ['مطلوب تعديل في مستند (أو مستند انتهى)', 'يعدّل المستند المطلوب ويقدّم تاني.'],
        'suspended' => ['موقوف من الأدمن', 'يدخل ويشوف السبب، ومفيش شغل.'],
        'blocked' => ['محظور من الأدمن', 'ما يقدرش يدخل.'],
    ];

    /** @return string path of a temporary .docx */
    public function documentTypes(Country $country): string
    {
        [$word, $section] = $this->start('مستندات التوثيق — العمل الحر', 'الدولة: '.($country->translatedName() ?? $country->code));

        $this->heading($section, 'الفكرة');
        $this->text($section, 'دي مستندات الهوية اللي المحترف بيرفعها عشان يتوثّق. الأدمن هو اللي بيحدد إيه المطلوب في كل دولة، ومن مين: المواطنين أو المقيمين أو الكل. مفيش أي مستند مطلوب إلا لو اتحدد هنا.');

        $this->heading($section, 'إزاي تستخدم الصفحة');
        foreach ([
            'زر «إضافة نوع مستند»: الاسم بالعربي والإنجليزي، والكود (حروف صغيرة وأرقام و_)، وهل بيطلب رقم وتاريخ انتهاء ووجه خلفي.',
            'في النافذة نفسها «مطلوب في دولة»: اختار الدولة ومن مين (الكل / المواطنين / المقيمين). تقدر تضيف أكتر من دولة.',
            'الـ switch بيوقف النوع. النوع الموقوف مش مطلوب من حد.',
            'فلتر الدولة فوق بيعرض الأنواع المطلوبة فيها بس.',
            'النوع اللي اترفع منه مستندات ما يتحذفش: أوقفه بدل الحذف.',
        ] as $step) {
            $section->addListItem($step, 0, ['rtl' => true], null, $this->rtl());
        }

        $this->heading($section, 'الأنواع الحالية');
        $types = FreelanceDocumentType::query()->with(['translations', 'requirements'])->orderBy('sort_order')->orderBy('id')->get();
        $rows = $types->map(fn (FreelanceDocumentType $t) => [
            $t->code,
            (string) $t->translatedName(),
            implode('، ', array_filter([$t->has_number ? 'رقم' : null, $t->has_expiry ? 'تاريخ انتهاء' : null, $t->needs_back_side ? 'وجه خلفي' : null])) ?: '—',
            $t->status ? 'نشط' : 'موقوف',
            $this->requiredIn($t, $country),
        ])->all();
        $this->table($section, ['الكود', 'الاسم', 'بيطلب', 'الحالة', 'مطلوب في '.($country->translatedName() ?? $country->code)], [2000, 2600, 3000, 1400, 3400], $rows);

        $this->heading($section, 'ملاحظات مهمة');
        foreach ([
            'ملفات المحترف بتتخزّن في تخزين خاص ومالهاش رابط عام.',
            'تغيير المتطلبات بيأثر على اللي هيقدّم بعد كده، وما بيغيّرش طلبات اتقدّمت أو اتعتمدت.',
            'الدولة اللي بتطلب مستندات مختلفة للمواطن والمقيم بتحتاج إن المحترف يحدد إقامته من ملفه قبل التقديم.',
        ] as $note) {
            $section->addListItem($note, 0, ['rtl' => true], null, $this->rtl());
        }

        return $this->save($word, 'fldoc');
    }

    /** @return string path of a temporary .docx */
    public function verifications(): string
    {
        [$word, $section] = $this->start('طلبات التوثيق — العمل الحر', 'عدّادات الطابور وقت التنزيل');

        $counts = FreelanceVerificationRequest::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $this->text($section, 'بانتظار المراجعة: '.(int) ($counts[FreelanceVerificationRequest::SUBMITTED] ?? 0).' · معتمد: '.(int) ($counts[FreelanceVerificationRequest::APPROVED] ?? 0).' · مرفوض: '.(int) ($counts[FreelanceVerificationRequest::REJECTED] ?? 0).' · مطلوب تعديل: '.(int) ($counts[FreelanceVerificationRequest::CHANGES_REQUESTED] ?? 0));

        $this->heading($section, 'الفكرة');
        $this->text($section, 'المحترف بيدخل بالجوال ويتصفح ويكمّل ملفه بحرية، لكن ما يقدرش يقدّم عرض أو يقبل دعوة أو يستلم فلوس إلا بعد ما حسابه يتوثّق. التوثيق هنا يدوي: المحترف بيرفع مستندات الهوية المطلوبة في دولته ويقدّم طلب، وإنت بتراجعه وتقرر.');

        $this->heading($section, 'حالات حساب المحترف');
        $statusRows = [];
        foreach (FreelancerStatus::cases() as $case) {
            [$meaning, $can] = self::STATUSES[$case->value] ?? ['—', '—'];
            $statusRows[] = [$case->value, $meaning, $can];
        }
        $this->table($section, ['الحالة', 'معناها', 'المحترف يقدر'], [2800, 4600, 5000], $statusRows);

        $this->heading($section, 'إزاي تراجع طلب');
        foreach ([
            'الطابور بيعرض الأقدم الأول. الفلتر بيحدد الحالة (بانتظار المراجعة / مطلوب تعديل / مرفوض / معتمد) والدولة، والبحث بالاسم أو الجوال أو الإيميل.',
            'زر «مراجعة» بيفتح الطلب: بيانات المحترف، ومستنداته (بتتحمّل بتوكنك ومالهاش رابط عام)، وطلباته السابقة.',
            'لكل مستند معلّق تقدر تقبله أو ترفضه بسبب، أو تسيبه «حسب القرار».',
            'بعدها تقرر على الطلب كله: «اعتماد» (كل المستندات المطلوبة لازم تكون سارية)، أو «رفض» أو «طلب تعديل». الرفض وطلب التعديل لازم لهم سبب بيظهر للمحترف.',
            'القرار بيتسجّل (مين ومتى) وبيوصل للمحترف إشعار. طلب اتاخد فيه قرار ما يتغيّرش، والمحترف يقدّم طلب جديد.',
        ] as $step) {
            $section->addListItem($step, 0, ['rtl' => true], null, $this->rtl());
        }

        $this->heading($section, 'اللي بيحصل بعد القرار');
        foreach ([
            'اعتماد: الحساب يبقى «موثّق» ويقدر يشتغل.',
            'رفض: الحساب «مرفوض» والسبب ظاهر له، ويرفع مستندات جديدة.',
            'طلب تعديل: المستندات اللي قبلتها بتفضل سارية، وهو يعدّل المرفوضة بس ويقدّم تاني.',
            'كل يوم بيتشيّك على المستندات: اللي انتهى تاريخه بيتحوّل «منتهي»، والمحترف الموثّق اللي ناقصه مستند مطلوب بيرجع «مطلوب تعديل».',
        ] as $line) {
            $section->addListItem($line, 0, ['rtl' => true], null, $this->rtl());
        }

        $this->heading($section, 'البوابة: إيه ممنوع وإيه مسموح قبل التوثيق');
        $this->text($section, 'ممنوع: تقديم عرض، وقبول دعوة مشروع، وبدء شغل، واستلام تمويل مرحلة أو مدفوعات. بيرد عليه النظام برسالة «وثّق حسابك الأول» ومعاها الخطوة الناقصة. مسموح: التصفح، وملفه ومعرض أعماله، ورفع المستندات، والإشعارات.');

        return $this->save($word, 'flver');
    }

    private function requiredIn(FreelanceDocumentType $type, Country $country): string
    {
        $rows = $type->requirements->where('country_id', $country->id)->where('is_required', true);
        if ($rows->isEmpty()) {
            return 'مش مطلوب';
        }
        if ($rows->contains(fn (FreelanceDocumentRequirement $r) => $r->residency === null)) {
            return 'الكل';
        }

        return $rows->map(fn (FreelanceDocumentRequirement $r) => $r->residency === 'citizen' ? 'المواطنين' : 'المقيمين')->unique()->implode(' و');
    }

    // -------------------------------------------------------------- word helpers

    /** @return array{0: PhpWord, 1: Section} */
    private function start(string $title, string $subtitle): array
    {
        $word = new PhpWord;
        $word->setDefaultFontName('Arial');
        $word->setDefaultFontSize(11);
        $word->getSettings()->setThemeFontLang(new \PhpOffice\PhpWord\Style\Language('ar-SA'));
        $section = $word->addSection(['orientation' => 'landscape']);
        $section->addText($title, ['rtl' => true, 'bold' => true, 'size' => 20], $this->rtl());
        $section->addText($subtitle.' · تاريخ التنزيل: '.now()->format('Y-m-d'), ['rtl' => true], $this->rtl());

        return [$word, $section];
    }

    /** @return array<string, mixed> */
    private function rtl(): array
    {
        return ['rtl' => true, 'alignment' => Jc::START, 'spaceAfter' => 120];
    }

    private function text(Section $section, string $text): void
    {
        $section->addText($text, ['rtl' => true], $this->rtl());
    }

    private function heading(Section $section, string $text): void
    {
        $section->addText($text, ['rtl' => true, 'bold' => true, 'size' => 14], ['rtl' => true, 'alignment' => Jc::START, 'spaceBefore' => 240, 'spaceAfter' => 80]);
    }

    /**
     * @param  list<string>  $head
     * @param  list<int>  $widths
     * @param  list<list<string>>  $rows
     */
    private function table(Section $section, array $head, array $widths, array $rows): void
    {
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 70, 'bidiVisual' => true]);
        $table->addRow(null, ['tblHeader' => true]);
        foreach ($head as $i => $label) {
            $table->addCell($widths[$i], ['bgColor' => 'EEEEEE'])->addText($label, ['rtl' => true, 'bold' => true], ['rtl' => true, 'alignment' => Jc::START]);
        }
        foreach ($rows as $row) {
            $table->addRow();
            foreach ($row as $i => $cell) {
                $isCode = $i === 0 && preg_match('/^[a-z0-9_]+$/', $cell) === 1;
                $table->addCell($widths[$i])->addText($cell, $isCode ? ['name' => 'Consolas', 'size' => 9] : ['rtl' => true], ['rtl' => ! $isCode, 'alignment' => Jc::START]);
            }
        }
    }

    private function save(PhpWord $word, string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix).'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);

        return $path;
    }
}
