<?php

namespace Modules\Freelance\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\FreelanceSkillTranslation;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Services\Catalog\FreelanceSkills;

/**
 * FL-102: the starting skill catalog, each tied to the sub-specialties it belongs to (by their English names, from
 * FreelanceSpecialtySeeder). It only adds: run it again and nothing is duplicated, and what an admin renamed, merged or
 * switched off is left alone.
 */
class FreelanceSkillSeeder extends Seeder
{
    /** @var list<array{0: string, 1: string, 2: list<string>}> english, arabic, the sub-specialties (english) */
    private const SKILLS = [
        ['Laravel', 'لارافيل', ['Web development', 'Software & APIs']],
        ['PHP', 'بي إتش بي', ['Web development', 'Software & APIs']],
        ['JavaScript', 'جافاسكريبت', ['Web development']],
        ['Vue.js', 'فيو', ['Web development']],
        ['React', 'ريأكت', ['Web development', 'Mobile apps']],
        ['WordPress', 'ووردبريس', ['Web development']],
        ['Node.js', 'نود', ['Web development', 'Software & APIs']],
        ['Python', 'بايثون', ['Software & APIs', 'Data & AI']],
        ['REST APIs', 'واجهات REST', ['Software & APIs']],
        ['Flutter', 'فلاتر', ['Mobile apps']],
        ['Kotlin', 'كوتلن', ['Mobile apps']],
        ['Swift', 'سويفت', ['Mobile apps']],
        ['Test automation', 'أتمتة الاختبار', ['QA & testing']],
        ['Machine learning', 'تعلّم الآلة', ['Data & AI']],
        ['Data analysis', 'تحليل البيانات', ['Data & AI']],
        ['Logo design', 'تصميم الشعارات', ['Logo & branding']],
        ['Brand identity', 'الهوية البصرية', ['Logo & branding']],
        ['Photoshop', 'فوتوشوب', ['Logo & branding', 'Illustration', 'Photography']],
        ['Illustrator', 'إليستريتور', ['Logo & branding', 'Illustration']],
        ['Figma', 'فيجما', ['UI / UX design']],
        ['User research', 'أبحاث المستخدمين', ['UI / UX design']],
        ['Video editing', 'مونتاج الفيديو', ['Video & animation']],
        ['Motion graphics', 'الموشن جرافيك', ['Video & animation']],
        ['Photo retouching', 'تعديل الصور', ['Photography']],
        ['Article writing', 'كتابة المقالات', ['Content writing']],
        ['Blog writing', 'كتابة المدونات', ['Content writing']],
        ['English to Arabic translation', 'الترجمة من الإنجليزية إلى العربية', ['Translation']],
        ['Arabic to English translation', 'الترجمة من العربية إلى الإنجليزية', ['Translation']],
        ['Legal translation', 'الترجمة القانونية', ['Translation']],
        ['Proofreading', 'التدقيق اللغوي', ['Proofreading']],
        ['Ad copywriting', 'كتابة الإعلانات', ['Copywriting']],
        ['Social media management', 'إدارة السوشيال ميديا', ['Social media']],
        ['SEO', 'تحسين محركات البحث', ['SEO']],
        ['Google Ads', 'إعلانات جوجل', ['Paid ads']],
        ['Meta Ads', 'إعلانات ميتا', ['Paid ads', 'Social media']],
        ['Email marketing', 'التسويق عبر البريد', ['Email & CRM']],
        ['Bookkeeping', 'مسك الدفاتر', ['Accounting & finance']],
        ['Customer support', 'خدمة العملاء', ['Customer support']],
        ['Project management', 'إدارة المشاريع', ['Project management']],
        ['AutoCAD', 'أوتوكاد', ['Architecture & interior', 'Civil & structural']],
        ['Interior design', 'التصميم الداخلي', ['Architecture & interior']],
        ['Arabic tutoring', 'تدريس اللغة العربية', ['Tutoring', 'Language teaching']],
    ];

    public function run(): void
    {
        $specialties = FreelanceSpecialty::query()->whereNotNull('parent_id')->with('translations')->get()
            ->keyBy(fn (FreelanceSpecialty $s) => $s->translations->firstWhere('locale', 'en')?->name);

        foreach (self::SKILLS as [$en, $ar, $tied]) {
            $skill = FreelanceSkillTranslation::query()->where('locale', 'en')->where('normalized', FreelanceSkills::normalize($en))->first()?->skill
                ?? $this->create($en, $ar);
            // Links are only added, so an admin's own changes stay.
            $skill->specialties()->syncWithoutDetaching(collect($tied)->map(fn ($name) => $specialties->get($name)?->id)->filter()->all());
        }
    }

    private function create(string $en, string $ar): FreelanceSkill
    {
        $skill = FreelanceSkill::query()->create(['slug' => $this->slug($en), 'status' => FreelanceSkill::ACTIVE]);
        foreach (['en' => $en, 'ar' => $ar] as $locale => $name) {
            // The Arabic name may already belong to another skill (a re-run after a rename): leave it out then.
            $taken = FreelanceSkillTranslation::query()->where('locale', $locale)->where('normalized', FreelanceSkills::normalize($name))->exists();
            if (! $taken) {
                $skill->translations()->create(['locale' => $locale, 'name' => $name, 'normalized' => FreelanceSkills::normalize($name)]);
            }
        }

        return $skill;
    }

    private function slug(string $en): string
    {
        $base = \Illuminate\Support\Str::slug($en) ?: 'skill';
        $slug = $base;
        for ($i = 2; FreelanceSkill::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
