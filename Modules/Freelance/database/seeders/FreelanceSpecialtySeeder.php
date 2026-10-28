<?php

namespace Modules\Freelance\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Freelance\Models\FreelanceSpecialty;

/**
 * FL-101: the starting specialty catalog (a specialty and its sub-specialties, in English and Arabic). It only adds:
 * run it again and nothing is duplicated, and what an admin added, renamed or switched off is left alone.
 */
class FreelanceSpecialtySeeder extends Seeder
{
    /** @var list<array{0: string, 1: string, 2: list<array{0: string, 1: string}>}> */
    private const SPECIALTIES = [
        ['Programming & Tech', 'البرمجة والتقنية', [['Web development', 'تطوير المواقع'], ['Mobile apps', 'تطبيقات الجوال'], ['Software & APIs', 'البرمجيات والـ API'], ['QA & testing', 'اختبار الجودة'], ['Data & AI', 'البيانات والذكاء الاصطناعي']]],
        ['Design & Creative', 'التصميم والإبداع', [['Logo & branding', 'الشعارات والهوية'], ['UI / UX design', 'تصميم الواجهات وتجربة المستخدم'], ['Illustration', 'الرسم والإيضاح'], ['Video & animation', 'الفيديو والموشن'], ['Photography', 'التصوير']]],
        ['Writing & Translation', 'الكتابة والترجمة', [['Content writing', 'كتابة المحتوى'], ['Translation', 'الترجمة'], ['Proofreading', 'التدقيق اللغوي'], ['Copywriting', 'الكتابة الإعلانية']]],
        ['Marketing & Sales', 'التسويق والمبيعات', [['Social media', 'السوشيال ميديا'], ['SEO', 'تحسين محركات البحث'], ['Paid ads', 'الإعلانات المدفوعة'], ['Email & CRM', 'البريد الإلكتروني وإدارة العملاء']]],
        ['Business & Admin', 'الأعمال والإدارة', [['Virtual assistance', 'المساعد الافتراضي'], ['Accounting & finance', 'المحاسبة والمالية'], ['Customer support', 'خدمة العملاء'], ['Project management', 'إدارة المشاريع']]],
        ['Engineering & Architecture', 'الهندسة والعمارة', [['Architecture & interior', 'العمارة والتصميم الداخلي'], ['Civil & structural', 'الهندسة المدنية'], ['Electrical & mechanical', 'الكهرباء والميكانيكا']]],
        ['Education & Training', 'التعليم والتدريب', [['Tutoring', 'الدروس الخصوصية'], ['Online courses', 'الدورات التدريبية'], ['Language teaching', 'تعليم اللغات']]],
    ];

    public function run(): void
    {
        foreach (self::SPECIALTIES as $i => [$en, $ar, $children]) {
            $parent = $this->specialty(null, $en, $ar, $i + 1);
            foreach ($children as $j => [$childEn, $childAr]) {
                $this->specialty($parent->id, $childEn, $childAr, $j + 1);
            }
        }
    }

    private function specialty(?int $parentId, string $en, string $ar, int $sortOrder): FreelanceSpecialty
    {
        $existing = FreelanceSpecialty::query()->where('parent_id', $parentId)
            ->whereHas('translations', fn ($q) => $q->where('locale', 'en')->where('name', $en))->first();
        if ($existing !== null) {
            return $existing;
        }

        $specialty = FreelanceSpecialty::query()->create(['parent_id' => $parentId, 'status' => true, 'sort_order' => $sortOrder]);
        $specialty->translations()->create(['locale' => 'en', 'name' => $en]);
        $specialty->translations()->create(['locale' => 'ar', 'name' => $ar]);

        return $specialty;
    }
}
