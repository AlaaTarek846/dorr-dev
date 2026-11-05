<?php

namespace Modules\Freelance\Services\Projects;

use Modules\Freelance\Models\FreelanceChecklistTemplate;

/** FS-105: the first ready-made checklists (website, app, design, marketing, writing); the admin edits or adds more. Safe to run again. */
class ChecklistTemplateDefaults
{
    /** @return array<string, array{ar: array{name: string, items: list<string>}, en: array{name: string, items: list<string>}}> */
    public static function all(): array
    {
        return [
            'website' => [
                'ar' => ['name' => 'موقع إلكتروني', 'items' => ['الصفحات كلها اتعملت زي المتفق عليه', 'الموقع شغال كويس على الموبايل والكمبيوتر', 'كل الروابط والأزرار شغالة', 'الفورمز بتبعت وبتوصل', 'سرعة التحميل مقبولة', 'الأمان الأساسي (HTTPS وكلمات السر)', 'الملفات المصدرية اتسلمت', 'شرح التشغيل والتحديث اتسلم']],
                'en' => ['name' => 'Website', 'items' => ['All agreed pages are built', 'Works well on phone and desktop', 'Every link and button works', 'Forms send and arrive', 'Loading speed is acceptable', 'Basic security (HTTPS, passwords)', 'Source files delivered', 'Run and update guide delivered']],
            ],
            'mobile_app' => [
                'ar' => ['name' => 'تطبيق موبايل', 'items' => ['التطبيق بيشتغل على الأجهزة المتفق عليها', 'مفيش Crash في الاستخدام العادي', 'الصلاحيات المطلوبة معقولة ومشروحة', 'الأيقونة والشاشات زي التصميم', 'ملفات البناء (APK / IPA) اتسلمت', 'الكود المصدري اتسلم', 'دليل التثبيت والتشغيل اتسلم']],
                'en' => ['name' => 'Mobile app', 'items' => ['Runs on the agreed devices', 'No crashes in normal use', 'Requested permissions are reasonable and explained', 'Icon and screens match the design', 'Build files (APK / IPA) delivered', 'Source code delivered', 'Install and run guide delivered']],
            ],
            'design' => [
                'ar' => ['name' => 'تصميم', 'items' => ['المقاسات والصيغ زي المتفق عليه', 'الملفات المصدرية المفتوحة اتسلمت', 'الألوان والخطوط ثابتة في كل الشغل', 'التصميم شغال في حالات الاستخدام المختلفة', 'الملفات منظمة ومسمّاة بوضوح']],
                'en' => ['name' => 'Design', 'items' => ['Sizes and formats are as agreed', 'Editable source files delivered', 'Colors and fonts are consistent throughout', 'The design works in the different use cases', 'Files are organized and clearly named']],
            ],
            'marketing' => [
                'ar' => ['name' => 'تسويق', 'items' => ['نطاق الحملة زي المتفق عليه', 'المواد (نصوص وصور وفيديو) اتسلمت', 'القياسات والأهداف واضحة', 'التقرير اتسلم بالأرقام', 'النتايج المتفق عليها اتحققت أو اتشرح ليه لأ']],
                'en' => ['name' => 'Marketing', 'items' => ['Campaign scope is as agreed', 'Materials (copy, images, video) delivered', 'Metrics and goals are clear', 'Report delivered with the numbers', 'Agreed results reached, or why not is explained']],
            ],
            'writing' => [
                'ar' => ['name' => 'كتابة وترجمة', 'items' => ['عدد الكلمات زي المتفق عليه', 'مفيش أخطاء إملائية أو نحوية', 'الأسلوب مناسب للجمهور', 'المصادر واضحة ومفيش نقل', 'التعديلات المتفق عليها اتنفذت']],
                'en' => ['name' => 'Writing and translation', 'items' => ['Word count is as agreed', 'No spelling or grammar mistakes', 'Tone suits the audience', 'Sources are clear and nothing is copied', 'Agreed edits are done']],
            ],
        ];
    }

    public static function ensure(): void
    {
        $sort = 0;
        foreach (self::all() as $code => $langs) {
            $sort += 10;
            $t = FreelanceChecklistTemplate::query()->withTrashed()->firstOrNew(['code' => $code]);
            if ($t->exists) {
                continue;
            }
            $t->fill(['status' => true, 'sort_order' => $sort])->fillAllTranslations([
                'name' => ['ar' => $langs['ar']['name'], 'en' => $langs['en']['name']],
                'items' => ['ar' => implode("\n", $langs['ar']['items']), 'en' => implode("\n", $langs['en']['items'])],
            ])->save();
        }
    }
}