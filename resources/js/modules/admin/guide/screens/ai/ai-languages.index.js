/**
 * Guide: AI languages (admin.ai-languages.index).
 * Source of truth checked: views/ai-languages/index.vue, Modules/AI/routes/admin.php (admin/v1/ai-languages),
 * Http/Controllers/AiLanguageController.php, Http/Requests/AiLanguageRequest.php, Http/Resources/AiLanguageResource.php,
 * Repositories/AiLanguageRepository.php (shared App\Models\Language), Services/AiLanguageService.php,
 * Http/Controllers/AiUserLanguagePreferenceSelfController.php, Services/AiChatLanguageResolver.php,
 * Support/AiAdminScreens.php (READERS).
 */
export default {
    route: 'admin.ai-languages.index',
    updated: '2026-11-10',

    ar: {
        summary: 'هنا بتختار أنهي لغة من لغات المنصة المساعد الذكي يقدر يستخدمها. الشاشة مش بتضيف لغة ولا بتمسحها، بس بتشغّل أو تقفل "مفعّلة للذكاء الاصطناعي" على كل لغة موجودة.',
        audience: 'مدير المنصة أو مسؤول المحتوى. فتح الشاشة محتاج صلاحية "لغات الذكاء الاصطناعي: عرض"، وتشغيل وقفل اللغة محتاج "تعديل".',
        purpose: [
            'اللغات نفسها هي نفس جدول لغات المنصة العام (languages). الكود والاسم والاتجاه بيتعدلوا من شاشة اللغات العامة بس.',
            'اللغة المفعّلة هنا هي اللي بتظهر لليوزر في صفحة "لغة المساعد" علشان يختار المساعد يرد بيها دايمًا.',
            'قايمة اللغات هنا هي اللي بتظهر كمان في شاشة تفضيلات لغة اليوزر وشاشة لهجات اللغة.',
        ],
        sections: [
            {
                title: 'الجدول',
                items: [
                    { name: 'الكود', desc: 'كود اللغة (مثلًا ar أو en). مترتب أبجديًا بالكود.' },
                    { name: 'الاسم', desc: 'اسم اللغة بلغة لوحة التحكم، جاي من ترجمات اللغة العامة.' },
                    { name: 'الاتجاه', desc: 'rtl أو ltr، زي ما هو متسجل في اللغات العامة.' },
                    { name: 'مفعّلة للذكاء الاصطناعي', desc: 'زرار تشغيل وإيقاف. بيتحفظ على طول أول ما تدوس. من غير صلاحية "تعديل" بيظهر مكانه "نعم/لا" بس.' },
                    { name: 'الترقيم', desc: 'تحت الجدول: تقلب بين الصفحات وتختار عدد الصفوف في الصفحة.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'تفعيل لغة للمساعد',
                steps: [
                    'لو اللغة مش موجودة في الجدول، ضيفها الأول من شاشة اللغات العامة.',
                    'ارجع هنا ودوس على زرار "مفعّلة للذكاء الاصطناعي" جنب اللغة.',
                    'هتظهر رسالة نجاح. اليوزر هيلاقي اللغة في اختيارات "لغة المساعد".',
                ],
            },
            {
                title: 'إخفاء لغة من اختيارات اليوزر',
                steps: [
                    'اقفل الزرار جنب اللغة.',
                    'اللغة مش هتظهر في قايمة اختيار اليوزر تاني. شوف التنبيهات علشان اللي كانوا مختارينها قبل كده.',
                ],
            },
        ],
        affects: [
            { text: 'صفحة "لغة المساعد" عند اليوزر (الويب وAndroid): بتعرض اللغات المفعّلة هنا بس.' },
            { route: 'admin.ai-user-language-preferences.index', text: 'تفضيلات لغة اليوزر: قايمة اللغات في الشاشة دي جاية من هنا.' },
            { route: 'admin.ai-language-variants.index', text: 'لهجات اللغة: كل لهجة مربوطة بلغة من هنا.' },
            { route: 'admin.languages.index', text: 'اللغات العامة: هي المكان اللي بتضيف أو تعدّل أو تمسح منه اللغة نفسها.' },
        ],
        warnings: [
            { level: 'warning', text: 'قفل اللغة بيخفيها من قايمة الاختيار بس. اليوزر اللي كان مختارها "لغة ثابتة" قبل كده المساعد هيفضل يرد عليه بيها، لأن الرد مش بيشيك على التفعيل.' },
            { level: 'info', text: 'مفيش إضافة ولا حذف هنا عن قصد: اللغة بتأثر على الموقع ولوحة التحكم كمان، فده شغل شاشة اللغات العامة.' },
            { level: 'info', text: 'مفيش بحث ولا فلتر في الشاشة دي. اللغات مترتبة بالكود.' },
        ],
    },

    en: {
        summary: 'Choose which of the platform languages the AI assistant may use. This screen never adds or deletes a language; it only switches "AI enabled" on or off for each existing one.',
        audience: 'Platform or content owner. Needs "AI languages: view" to open the screen and "update" to switch a language.',
        purpose: [
            'The languages are the shared platform `languages` table; code, name and direction are edited on the general Languages screen only.',
            'An enabled language is offered to users on their "assistant language" page, so the assistant can always reply in it.',
            'The same list feeds the user language preferences and language variants screens.',
        ],
        sections: [
            {
                title: 'Table',
                items: [
                    { name: 'Code', desc: 'Language code (e.g. ar, en). Sorted by code.' },
                    { name: 'Name', desc: 'In the panel language, from the general language translations.' },
                    { name: 'Direction', desc: 'rtl or ltr, as stored on the general language.' },
                    { name: 'AI enabled', desc: 'On/off toggle, saved immediately. Without "update" a yes/no badge shows instead.' },
                    { name: 'Pagination', desc: 'Page through the list and pick the rows per page.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Enable a language for the assistant',
                steps: ['If it is missing, add it first on the general Languages screen.', 'Come back and switch on "AI enabled" for it.', 'A success message shows; users now see it in the assistant language picker.'],
            },
            {
                title: 'Hide a language from users',
                steps: ['Switch its toggle off.', 'It disappears from the user picker — see the warnings for users who already picked it.'],
            },
        ],
        affects: [
            { text: 'The user "assistant language" page (web and Android) lists only the languages enabled here.' },
            { route: 'admin.ai-user-language-preferences.index', text: 'User language preferences: its language list comes from here.' },
            { route: 'admin.ai-language-variants.index', text: 'Language variants: each variant belongs to a language here.' },
            { route: 'admin.languages.index', text: 'General Languages: where a language itself is added, edited or deleted.' },
        ],
        warnings: [
            { level: 'warning', text: 'Switching a language off only hides it from the picker. A user who already chose it as a fixed language still gets replies in it — the reply does not check this flag.' },
            { level: 'info', text: 'No add or delete here on purpose: a language also affects the website and panel, so that belongs to the general Languages screen.' },
            { level: 'info', text: 'No search or filter on this screen; languages are sorted by code.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-languages/index.vue',
            'Modules/AI/app/Http/Controllers/AiLanguageController.php (index, show, update only)',
            'Modules/AI/app/Http/Requests/AiLanguageRequest.php (ai_enabled required|boolean)',
            'Modules/AI/app/Repositories/AiLanguageRepository.php (App\\Models\\Language, with translations, order by code)',
            'Modules/AI/app/Http/Resources/AiLanguageResource.php',
            'Modules/AI/app/Http/Controllers/AiUserLanguagePreferenceSelfController.php (reads ai_enabled = true)',
        ],
        api: [
            'GET /api/admin/v1/ai-languages            (page, per_page; all=1 used by other screens)',
            'GET /api/admin/v1/ai-languages/{language}',
            'PUT /api/admin/v1/ai-languages/{language} { ai_enabled }',
            'User: GET /api/user/v1/ai-language-preference → languages where ai_enabled = true',
        ],
        tables: ['languages (ai_enabled)', 'language_translations (name)'],
        permissions: [
            'ai-languages.view (screen, list; also readable by ai-language-variants.view and ai-user-language-preferences.view)',
            'ai-languages.update (the AI enabled toggle)',
        ],
        decisions: [
            {
                ar: 'NEEDS-DECISION: AiChatLanguageResolver مش بيشيك على ai_enabled، فاللغة المقفولة بتفضل شغالة لليوزر اللي مختارها ثابتة. وطلب تحديث اليوزر (AiUserLanguagePreferenceSelfUpdateRequest) بيقبل أي languages.id مش المفعّلة بس. نقفلها ولا نسيبها كده؟',
                en: 'NEEDS-DECISION: AiChatLanguageResolver does not check ai_enabled, so a disabled language keeps working for users who fixed it; the user self-update request also accepts any languages.id, not only enabled ones. Enforce it or keep as is?',
            },
        ],
    },
};
