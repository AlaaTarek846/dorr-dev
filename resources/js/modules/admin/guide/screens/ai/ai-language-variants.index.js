/**
 * Guide: AI language variants (admin.ai-language-variants.index) - the dialects / styles a user may pick for the
 * AI chat's replies.
 * Source of truth checked: views/ai-language-variants/*.vue, composables/useAiQualityScreens.js (useAiLanguageVariants),
 * Modules/AI/app/Models/AiLanguageVariant.php (+ AiLanguageVariantTranslation, Concerns/HasFlatTranslatedText.php),
 * Repositories/AiLanguageVariantRepository.php (dropdown, force-delete blocks), Services/AiLanguageVariantService.php
 * (one default per language), Http/Controllers/AiLanguageVariantController.php, Http/Requests/AiLanguageVariantRequest.php,
 * Http/Resources/AiLanguageVariantResource.php, Services/AiChatLanguageResolver.php,
 * Http/Controllers/AiUserLanguagePreferenceSelfController.php, Support/AiAdminScreens.php (readers), routes/admin.php,
 * database/migrations/2026_11_10_170000_make_ai_language_variants_translatable.php.
 */
export default {
    route: 'admin.ai-language-variants.index',
    updated: '2026-11-11',

    ar: {
        summary: 'هنا لهجات وأساليب كل لغة (الفصحى، المصرية، السعودية، الإنجليزية القياسية...). المستخدم بيختار واحدة في إعدادات لغة الذكاء الاصطناعي، والشات بيرد بأسلوبها.',
        audience: 'مسؤول الذكاء الاصطناعي. محتاج صلاحيات "لهجات اللغة": عرض، إضافة، تعديل، حذف، تغيير الحالة، حذف متعدد.',
        purpose: [
            'لما المستخدم يثبّت لغة الرد ويختار لهجة، الشات بيتقاله "استخدم أسلوب كذا (رسمي/عامي) زي لهجة كذا".',
            'كل لغة ليها لهجة افتراضية واحدة بس.',
            'اللهجة المقفولة أو اللي في السلة: مش بتظهر للمستخدم والشات مش بيطبّقها (بيرد باللغة بس).',
        ],
        sections: [
            {
                title: 'شريط الأدوات',
                items: [
                    { name: 'البحث', desc: 'بالاسم (أي لغة)، أو الكود، أو الأسلوب، أو كود اللغة.' },
                    { name: 'الكل / مفعّلة / متوقفة / المحذوفات', desc: 'فلترة سريعة بالأعداد، والسلة بتظهر لو فيها حاجة.' },
                    { name: 'حذف المحدد / إضافة لهجة', desc: 'حسب صلاحياتك.' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'الاسم', desc: 'بلغة اللوحة، وتحته الكود ورقم اللهجة. دوس عليه يفتح التعديل.' },
                    { name: 'اللغة / الأسلوب / افتراضي', desc: 'اللغة اللي اللهجة تبعها، رسمي أو عامي، وهل هي الافتراضية.' },
                    { name: 'الحالة / التاريخ / الأزرار', desc: 'زي شاشة الدول: تشغيل وإيقاف، تعديل، حذف، ومن السلة استرجاع أو حذف نهائي.' },
                ],
            },
            {
                title: 'فورم اللهجة',
                items: [
                    { name: 'الاسم (تاب لكل لغة)', desc: 'إجباري، من 2 لـ 100 حرف في كل لغة. ده اللي المستخدم بيشوفه.' },
                    { name: 'اللغة', desc: 'إجباري، من لغات الذكاء الاصطناعي.' },
                    { name: 'الكود', desc: 'إجباري، لحد 50 حرف. قيمة تقنية مش بتتترجم، ومينفعش يتكرر جوه نفس اللغة - حتى لو اللهجة القديمة في السلة. الحفظ بيترفض برسالة "الكود ده مستخدم بالفعل للغة دي" (رجّع القديمة بدل ما تعمل جديدة). نفس الكود تحت لغة تانية مسموح.' },
                    { name: 'الأسلوب', desc: 'رسمي أو عامي - النبرة اللي الشات بيتقاله يستخدمها.' },
                    { name: 'افتراضي', desc: 'لو شغّلته، أي لهجة تانية افتراضية لنفس اللغة بتتقفل (حتى اللي في السلة).' },
                    { name: 'الحالة', desc: 'مقفولة = مش بتظهر للمستخدم والشات مش بيطبّقها.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'إضافة لهجة جديدة',
                steps: ['دوس "إضافة لهجة".', 'اكتب الاسم في تاب كل لغة.', 'اختار اللغة واكتب الكود والأسلوب.', 'دوس حفظ.'],
            },
            {
                title: 'وقف لهجة مؤقتًا',
                steps: ['اقفل زرار الحالة - المستخدمين اللي اختاروها الشات هيرد عليهم باللغة من غير أسلوب اللهجة.'],
            },
        ],
        affects: [
            { route: 'admin.ai-user-language-preferences.index', text: 'اختيار لهجة لكل مستخدم بيتعمل من اللهجات اللي مش في السلة.' },
            { route: 'admin.ai-language-evaluations.index', text: 'التقييم ممكن يكون للهجة معينة.' },
            { route: 'admin.ai-languages.index', text: 'كل لهجة تابعة للغة من هنا.' },
            { text: 'إعدادات لغة الذكاء الاصطناعي في تطبيق المستخدم: بيظهر فيها اللهجات المفعّلة بس، باسمها بلغة التطبيق.' },
        ],
        warnings: [
            { level: 'warning', text: 'نقل لهجة للسلة زي إيقافها: الشات مش هيطبّقها، واختيار المستخدمين ليها بيفضل لحد ما تسترجعها.' },
            { level: 'warning', text: 'الحذف النهائي ممنوع طول ما فيه مستخدم أو تقييم مختارها - شيلها منهم الأول.' },
            { level: 'info', text: 'الكود والأسلوب مش بيتترجموا؛ الاسم بس.' },
        ],
    },

    en: {
        summary: 'The dialects / styles of each language (MSA, Egyptian, Saudi, Standard English...). A user picks one in their AI language settings and the chat replies in its style.',
        audience: 'AI administrator. Needs the "Language variants" permissions: view, create, update, delete, change status, multiple delete.',
        purpose: [
            'When a user fixes the reply language and picks a variant, the chat is told "use a formal/conversational tone, matching the X style".',
            'Each language has a single default variant.',
            'A switched-off or trashed variant is not offered to users and the chat does not apply it (it replies in the language only).',
        ],
        sections: [
            {
                title: 'Toolbar',
                items: [
                    { name: 'Search', desc: 'By name (any language), code, style or language code.' },
                    { name: 'All / Active / Inactive / Trash', desc: 'Quick filters with counts; Trash shows when it has rows.' },
                    { name: 'Delete selected / Add variant', desc: 'Per your permissions.' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Name', desc: 'In the panel language, with the code and id. Click to edit.' },
                    { name: 'Language / style / default', desc: 'The language it belongs to, formal or conversational, and whether it is the default.' },
                    { name: 'Status / dates / buttons', desc: 'Like countries: toggle, edit, delete; restore or delete for good from the trash.' },
                ],
            },
            {
                title: 'Variant form',
                items: [
                    { name: 'Name (one tab per language)', desc: 'Required, 2-100 chars in each language. This is what users see.' },
                    { name: 'Language', desc: 'Required, from the AI languages.' },
                    { name: 'Code', desc: 'Required, up to 50 chars. Technical, not translated, unique within the language - the trash included. Saving a taken code is refused with "This code is already used for this language" (restore the old variant instead). The same code under another language is allowed.' },
                    { name: 'Style', desc: 'Formal or conversational - the tone the chat is told to use.' },
                    { name: 'Default', desc: 'Turning it on turns off every other default of the same language (the trash included).' },
                    { name: 'Status', desc: 'Off = not offered to users and not applied by the chat.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Add a variant',
                steps: ['Click "Add variant".', 'Type the name in each language tab.', 'Pick the language, type the code and pick the style.', 'Save.'],
            },
            {
                title: 'Pause a variant',
                steps: ['Switch its status off - users who picked it get replies in the language without its style.'],
            },
        ],
        affects: [
            { route: 'admin.ai-user-language-preferences.index', text: 'Each user\'s variant is picked from the variants not in the trash.' },
            { route: 'admin.ai-language-evaluations.index', text: 'An evaluation may target a variant.' },
            { route: 'admin.ai-languages.index', text: 'Every variant belongs to one of these languages.' },
            { text: 'The user app\'s AI language settings: only switched-on variants show, named in the app language.' },
        ],
        warnings: [
            { level: 'warning', text: 'Moving a variant to the trash is the same as switching it off: the chat stops applying it; users keep it picked until you restore it.' },
            { level: 'warning', text: 'Delete for good is blocked while a user preference or an evaluation uses it - clear it there first.' },
            { level: 'info', text: 'The code and style are not translated; only the name is.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-language-variants/index.vue (countries-style list)',
            'resources/js/modules/admin/themes/theme-1/views/ai-language-variants/ModalCreateAndUpdate.vue (name tabs; languages from ai-languages)',
            'resources/js/composables/useAiQualityScreens.js (useAiLanguageVariants), stores/aiQualityScreens.js',
            'Modules/AI/app/Http/Controllers/AiLanguageVariantController.php (CatalogController; guarded by admin.screen on the route group)',
            'Modules/AI/app/Repositories/AiLanguageVariantRepository.php (TranslatableRepository; dropdown; force delete blocked by preferences / evaluations)',
            'Modules/AI/app/Services/AiLanguageVariantService.php (CatalogService; one default per language, trash included)',
            'Modules/AI/app/Models/AiLanguageVariant.php (SoftDeletes, status, is_active alias)',
            'Modules/AI/app/Services/AiChatLanguageResolver.php (applies the variant only when isAvailable())',
            'Modules/AI/app/Http/Controllers/AiUserLanguagePreferenceSelfController.php (user app: variants where status, not trashed)',
        ],
        api: [
            'GET|POST       /api/admin/v1/ai-language-variants            (?status_counts=1, search, filterColumns, trashed)',
            'GET            /api/admin/v1/ai-language-variants/dropdown   (evaluations form, user-preferences screen; not trashed, paused ones too)',
            'GET|PUT|DELETE /api/admin/v1/ai-language-variants/{variant}',
            'PATCH          /api/admin/v1/ai-language-variants/{variant}/status',
            'POST           /api/admin/v1/ai-language-variants/delete-multiple',
            'POST           /api/admin/v1/ai-language-variants/{variant}/restore',
            'DELETE         /api/admin/v1/ai-language-variants/{variant}/force',
            'GET            /api/user/v1/ai-language-preference           (variants[]: same keys; name in X-Locale)',
        ],
        tables: [
            'ai_language_variants (language_id, code, style, is_default, status, deleted_at)',
            'ai_language_variant_translations (ai_language_variant_id, locale, name)',
        ],
        permissions: [
            'ai-language-variants.view (screen, list, dropdown) - ai-language-evaluations.view and ai-user-language-preferences.view may also read it (AiAdminScreens::READERS)',
            'ai-language-variants.create · ai-language-variants.update (edit, restore)',
            'ai-language-variants.delete (trash, force delete) · ai-language-variants.change-status · ai-language-variants.multiple-delete',
        ],
        decisions: [
            {
                ar: 'الاسم بس اللي بيتترجم؛ الكود والأسلوب قيم تقنية.',
                en: 'Only the name is translated; the code and style are technical values.',
            },
            {
                ar: 'اللهجة المقفولة مبقتش تتطبّق في الشات (قبل كده كانت بتتطبّق لو المستخدم مختارها).',
                en: 'A switched-off variant is no longer applied by the chat (before, it still was when a user had picked it).',
            },
        ],
    },
};
