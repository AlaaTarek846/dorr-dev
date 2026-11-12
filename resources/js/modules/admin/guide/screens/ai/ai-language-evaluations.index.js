/**
 * Guide: AI language evaluations (admin.ai-language-evaluations.index) - the record of how well the assistant does
 * in each language / variant.
 * Source of truth checked: views/ai-language-evaluations/*.vue, composables/useAiQualityScreens.js
 * (useAiLanguageEvaluations), Modules/AI/app/Models/AiLanguageEvaluation.php (+ AiLanguageEvaluationTranslation),
 * Repositories/AiLanguageEvaluationRepository.php, Http/Controllers/AiLanguageEvaluationController.php,
 * Http/Requests/AiLanguageEvaluationRequest.php, Http/Resources/AiLanguageEvaluationResource.php,
 * Repositories/AiLanguageVariantRepository.php (dropdown), Support/AiAdminScreens.php, routes/admin.php,
 * database/migrations/2026_11_10_170100_make_ai_language_evaluations_translatable.php.
 */
export default {
    route: 'admin.ai-language-evaluations.index',
    updated: '2026-11-11',

    ar: {
        summary: 'هنا سجل تقييمات جودة الرد لكل لغة (أو لهجة): اسم مجموعة الاختبار، عدد الحالات، نسبة النجاح، النتيجة وملاحظاتك.',
        audience: 'مسؤول الذكاء الاصطناعي أو الجودة. محتاج صلاحيات "تقييمات اللغة": عرض، إضافة، تعديل، حذف، تغيير الحالة، حذف متعدد.',
        purpose: [
            'سجل بتملاه بإيدك بعد ما تختبر الموديل بلغة معينة - الشاشة مش بتشغّل اختبارات بنفسها.',
            'فيه حاجتين مختلفين: "النتيجة" (لسه/جاري/نجح/فشل) و"الحالة" (تشغيل وإيقاف السجل في القائمة).',
            'مفيش جزء تاني من النظام بيقرا التقييمات دي؛ هي للمتابعة بس.',
        ],
        sections: [
            {
                title: 'شريط الأدوات',
                items: [
                    { name: 'البحث', desc: 'بالاسم (أي لغة)، أو الملاحظات، أو النتيجة، أو كود اللغة أو اللهجة.' },
                    { name: 'الكل / مفعّل / متوقف / المحذوفات', desc: 'فلترة سريعة بالأعداد، والسلة بتظهر لو فيها حاجة.' },
                    { name: 'حذف المحدد / إضافة تقييم', desc: 'حسب صلاحياتك.' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'الاسم', desc: 'بلغة اللوحة، وتحته الملاحظات ورقم التقييم.' },
                    { name: 'اللغة', desc: 'وتحتها اللهجة لو فيه - "محذوف" أو "متوقف" لو اللهجة نفسها اتشالت أو اتقفلت.' },
                    { name: 'عدد الحالات / نسبة النجاح / النتيجة', desc: 'زي ما اتكتبوا في الفورم.' },
                    { name: 'الحالة / التاريخ / الأزرار', desc: 'زي شاشة الدول.' },
                ],
            },
            {
                title: 'فورم التقييم',
                items: [
                    { name: 'الاسم (تاب لكل لغة)', desc: 'إجباري، من 2 لـ 150 حرف في كل لغة.' },
                    { name: 'اللغة', desc: 'إجباري، من لغات الذكاء الاصطناعي.' },
                    { name: 'اللهجة', desc: 'اختياري. لهجات اللغة المختارة اللي مش في السلة؛ لهجة محذوفة التقييم مختارها أصلاً بتفضل.' },
                    { name: 'عدد الحالات', desc: '0 أو أكتر، فاضي = 0.' },
                    { name: 'نسبة النجاح', desc: 'من 0 لـ 100، ممكن تفضل فاضية.' },
                    { name: 'النتيجة', desc: 'لسه ماتعملش / جاري / نجح / فشل.' },
                    { name: 'الملاحظات', desc: 'لحد 2000 حرف. نص حر للأدمن ومش بيتترجم.' },
                    { name: 'الحالة', desc: 'مقفول = التقييم بيظهر في "متوقف".' },
                ],
            },
        ],
        tasks: [
            {
                title: 'تسجيل نتيجة اختبار',
                steps: ['دوس "إضافة تقييم".', 'اكتب الاسم في كل تاب.', 'اختار اللغة (واللهجة لو عايز).', 'اكتب عدد الحالات ونسبة النجاح واختار النتيجة.', 'دوس حفظ.'],
            },
        ],
        affects: [
            { route: 'admin.ai-language-variants.index', text: 'اللهجة بتتختار من هنا، ومينفعش تتحذف نهائي طول ما تقييم مختارها.' },
        ],
        warnings: [
            { level: 'info', text: '"النتيجة" مش هي "الحالة": قفل الحالة مش بيغيّر النتيجة.' },
            { level: 'info', text: 'الحذف النهائي متاح من السلة في أي وقت - مفيش حاجة بتعتمد على التقييم.' },
            { level: 'warning', text: 'قائمة اللغات بتيجي من شاشة "لغات الذكاء الاصطناعي"؛ أدمن معاه صلاحية التقييمات بس من غير صلاحية تلك الشاشة مش هيشوف اللغات.' },
        ],
    },

    en: {
        summary: 'The record of reply-quality evaluations per language (or variant): the suite\'s name, case count, pass rate, result and your notes.',
        audience: 'AI or quality administrator. Needs the "Language evaluations" permissions: view, create, update, delete, change status, multiple delete.',
        purpose: [
            'A record you fill in by hand after testing the model in a language - the screen runs no tests itself.',
            'Two different things: the "Result" (pending/running/passed/failed) and the "Status" (the record\'s on/off switch in the list).',
            'No other part of the system reads these evaluations; they are for follow-up.',
        ],
        sections: [
            {
                title: 'Toolbar',
                items: [
                    { name: 'Search', desc: 'By name (any language), notes, result, language or variant code.' },
                    { name: 'All / Active / Inactive / Trash', desc: 'Quick filters with counts; Trash shows when it has rows.' },
                    { name: 'Delete selected / Add evaluation', desc: 'Per your permissions.' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Name', desc: 'In the panel language, with the notes and id.' },
                    { name: 'Language', desc: 'With the variant if any - "Deleted"/"Inactive" when that variant was trashed or switched off.' },
                    { name: 'Test cases / pass rate / result', desc: 'As entered in the form.' },
                    { name: 'Status / dates / buttons', desc: 'Like countries.' },
                ],
            },
            {
                title: 'Evaluation form',
                items: [
                    { name: 'Name (one tab per language)', desc: 'Required, 2-150 chars in each language.' },
                    { name: 'Language', desc: 'Required, from the AI languages.' },
                    { name: 'Variant', desc: 'Optional. The picked language\'s variants not in the trash; a trashed one the evaluation already uses stays.' },
                    { name: 'Test cases', desc: '0 or more, empty = 0.' },
                    { name: 'Pass rate', desc: '0 to 100, may stay empty.' },
                    { name: 'Result', desc: 'Pending / running / passed / failed.' },
                    { name: 'Notes', desc: 'Up to 2000 chars. Free admin text, not translated.' },
                    { name: 'Status', desc: 'Off = the evaluation shows under "Inactive".' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Record a test result',
                steps: ['Click "Add evaluation".', 'Type the name in each tab.', 'Pick the language (and the variant if needed).', 'Enter the case count and pass rate, pick the result.', 'Save.'],
            },
        ],
        affects: [
            { route: 'admin.ai-language-variants.index', text: 'The variant is picked from there; it cannot be deleted for good while an evaluation uses it.' },
        ],
        warnings: [
            { level: 'info', text: 'The "Result" is not the "Status": switching the status off does not change the result.' },
            { level: 'info', text: 'Delete for good is always allowed from the trash - nothing depends on an evaluation.' },
            { level: 'warning', text: 'The language list comes from the "AI languages" screen; an admin with the evaluations permissions only, without that screen\'s, sees no languages.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-language-evaluations/index.vue (countries-style list)',
            'resources/js/modules/admin/themes/theme-1/views/ai-language-evaluations/ModalCreateAndUpdate.vue (name tabs; pickers: ai-languages, ai-language-variants/dropdown)',
            'resources/js/composables/useAiQualityScreens.js (useAiLanguageEvaluations), stores/aiQualityScreens.js',
            'Modules/AI/app/Http/Controllers/AiLanguageEvaluationController.php (CatalogController; guarded by admin.screen on the route group)',
            'Modules/AI/app/Repositories/AiLanguageEvaluationRepository.php (TranslatableRepository)',
            'Modules/AI/app/Models/AiLanguageEvaluation.php (SoftDeletes, status + evaluation_status; variant withTrashed)',
        ],
        api: [
            'GET|POST       /api/admin/v1/ai-language-evaluations            (?status_counts=1, search, filterColumns, trashed)',
            'GET|PUT|DELETE /api/admin/v1/ai-language-evaluations/{evaluation}',
            'PATCH          /api/admin/v1/ai-language-evaluations/{evaluation}/status',
            'POST           /api/admin/v1/ai-language-evaluations/delete-multiple',
            'POST           /api/admin/v1/ai-language-evaluations/{evaluation}/restore',
            'DELETE         /api/admin/v1/ai-language-evaluations/{evaluation}/force',
        ],
        tables: [
            'ai_language_evaluations (language_id, variant_id, test_case_count, pass_rate, evaluation_status, evaluated_at, notes, status, deleted_at)',
            'ai_language_evaluation_translations (ai_language_evaluation_id, locale, name)',
        ],
        permissions: [
            'ai-language-evaluations.view (screen, list; also reads ai-language-variants/dropdown)',
            'ai-language-evaluations.create · ai-language-evaluations.update (edit, restore)',
            'ai-language-evaluations.delete (trash, force delete) · ai-language-evaluations.change-status · ai-language-evaluations.multiple-delete',
        ],
        decisions: [
            {
                ar: 'عمود status القديم كان نتيجة التقييم (pending/running/passed/failed) مش زرار تشغيل، فاتغيّر اسمه لـ evaluation_status بنفس القيم، واتضاف status جديد (boolean) للتشغيل والإيقاف.',
                en: 'The old status column was the evaluation\'s result (pending/running/passed/failed), not an on/off switch, so it was renamed evaluation_status with the same values and a new boolean status was added for on/off.',
            },
            {
                ar: 'الاسم بس اللي بيتترجم؛ الملاحظات نص حر للأدمن.',
                en: 'Only the name is translated; the notes are free admin text.',
            },
        ],
    },
};
