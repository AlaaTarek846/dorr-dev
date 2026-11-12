/**
 * Guide: AI benchmark cases (admin.ai-benchmark-cases.index) - the question bank the benchmark runs score the
 * assistant on.
 * Source of truth checked: views/ai-benchmark-cases/*.vue, composables/useAiQualityScreens.js (useAiBenchmarkCases),
 * Modules/AI/app/Models/AiBenchmarkCase.php (+ Concerns/HasIsActiveAlias.php), Models/AiBenchmarkResult.php,
 * Repositories/AiBenchmarkCaseRepository.php, Http/Controllers/AiBenchmarkCaseController.php,
 * Http/Requests/AiBenchmarkCaseRequest.php, Http/Resources/AiBenchmarkCaseResource.php, Services/AiBenchmarkRunner.php,
 * routes/admin.php, database/migrations/2026_11_10_170200_add_status_and_trash_to_ai_benchmark_cases.php.
 */
export default {
    route: 'admin.ai-benchmark-cases.index',
    updated: '2026-11-11',

    ar: {
        summary: 'هنا بنك أسئلة الـ Benchmark: كل حالة سؤال بيتبعت للموديل في تشغيل الـ Benchmark، والنظام بيقيّم الرد (جاوب صح؟ امتنع؟ طلب توضيح؟).',
        audience: 'مسؤول الذكاء الاصطناعي أو الجودة. محتاج صلاحيات "حالات Benchmark": عرض، إضافة، تعديل، حذف، تغيير الحالة، حذف متعدد.',
        purpose: [
            'كل تشغيل من شاشة "تشغيلات Benchmark" بيبعت كل الحالات المفعّلة (أو حالات مسار واحد) للمزود ويحسب النجاح.',
            'فيه حالات سهلة وصعبة وعدائية وناقصة المعلومات، عشان نقيس الإجابة الصح والامتناع الصح.',
            'الحالة المقفولة أو اللي في السلة مش بتشتغل.',
        ],
        sections: [
            {
                title: 'شريط الأدوات',
                items: [
                    { name: 'البحث', desc: 'بالسؤال، أو الملاحظات، أو المسار، أو نوع المهمة، أو الدولة، أو اللغة.' },
                    { name: 'الكل / مفعّلة / متوقفة / المحذوفات', desc: 'فلترة سريعة بالأعداد، والسلة بتظهر لو فيها حاجة.' },
                    { name: 'حذف المحدد / إضافة حالة', desc: 'حسب صلاحياتك.' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'السؤال', desc: 'وتحته الرقم واللغة والدولة ونوع المهمة. دوس عليه يفتح التعديل.' },
                    { name: 'المسار / الصعوبة / السلوك المتوقع', desc: 'تصنيف الحالة وإيه الرد الصح.' },
                    { name: 'النتايج', desc: 'عدد النتايج اللي اتسجلت للحالة في التشغيلات اللي فاتت.' },
                    { name: 'الحالة / التاريخ / الأزرار', desc: 'زي شاشة الدول.' },
                ],
            },
            {
                title: 'فورم الحالة',
                items: [
                    { name: 'السؤال', desc: 'إجباري، لحد 5000 حرف. بيتبعت للموديل زي ما هو.' },
                    { name: 'السلوك المتوقع', desc: 'إجباري: يجاوب، أو يمتنع، أو يطلب توضيح. ده اللي بيتحدد بيه النجاح.' },
                    { name: 'الكلمات المفتاحية المتوقعة', desc: 'مفصولة بفاصلة، كل واحدة لحد 100 حرف. لو موجودة، الرد لازم يحتوي عليها عشان يعتبر صح.' },
                    { name: 'المسار', desc: 'legal / health / education / code / marketing / general_info أو فاضي. بيحدد تعليمات المسار اللي بتتبعت مع السؤال وفلتر التشغيل.' },
                    { name: 'الصعوبة', desc: 'إجباري: سهل / صعب / عدائي / معلومات ناقصة.' },
                    { name: 'مستوى الخطورة / اللغة / الدولة / نوع المهمة', desc: 'تصنيفات للمتابعة. الدولة حرفين بالظبط.' },
                    { name: 'الملاحظات', desc: 'لحد 1000 حرف، ملاحظة للأدمن مش بتتبعت للموديل.' },
                    { name: 'الحالة', desc: 'مقفولة = التشغيلات بتتخطاها.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'إضافة سؤال لازم الموديل يمتنع فيه',
                steps: ['دوس "إضافة حالة".', 'اكتب السؤال.', 'اختار السلوك "يجب أن يمتنع" والصعوبة "عدائي".', 'دوس حفظ، وشغّل Benchmark من شاشة التشغيلات.'],
            },
        ],
        affects: [
            { route: 'admin.ai-benchmark-runs.index', text: 'كل تشغيل بيبعت الحالات المفعّلة اللي مش في السلة بس، والنتايج القديمة بتفضل تسمّي الحالة حتى لو اتنقلت للسلة.' },
        ],
        warnings: [
            { level: 'warning', text: 'الحذف النهائي ممنوع طول ما فيه نتايج تشغيل قديمة للحالة (كانت هتتمسح معاها). استخدم السلة أو الإيقاف.' },
            { level: 'info', text: 'مفيش ترجمة هنا: السؤال والكلمات والملاحظات بيانات اختبار.' },
        ],
    },

    en: {
        summary: 'The benchmark question bank: each case is a question sent to the model in a benchmark run, and the system scores the reply (correct answer? abstained? asked for clarification?).',
        audience: 'AI or quality administrator. Needs the "Benchmark cases" permissions: view, create, update, delete, change status, multiple delete.',
        purpose: [
            'Each run from the "Benchmark runs" screen sends every active case (or one domain\'s cases) to the provider and computes the pass rate.',
            'Easy, hard, adversarial and insufficient-information cases measure both correct answers and correct abstention.',
            'A switched-off or trashed case never runs.',
        ],
        sections: [
            {
                title: 'Toolbar',
                items: [
                    { name: 'Search', desc: 'By question, notes, domain, task type, country or language.' },
                    { name: 'All / Active / Inactive / Trash', desc: 'Quick filters with counts; Trash shows when it has rows.' },
                    { name: 'Delete selected / Add case', desc: 'Per your permissions.' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Question', desc: 'With the id, language, country and task type. Click to edit.' },
                    { name: 'Domain / difficulty / expected behavior', desc: 'The case\'s classification and what the right reply is.' },
                    { name: 'Results', desc: 'How many results past runs recorded for it.' },
                    { name: 'Status / dates / buttons', desc: 'Like countries.' },
                ],
            },
            {
                title: 'Case form',
                items: [
                    { name: 'Question', desc: 'Required, up to 5000 chars. Sent to the model as is.' },
                    { name: 'Expected behavior', desc: 'Required: answer, abstain or ask for clarification. Decides pass / fail.' },
                    { name: 'Expected keywords', desc: 'Comma-separated, each up to 100 chars. When set, the reply must contain them to count as correct.' },
                    { name: 'Domain', desc: 'legal / health / education / code / marketing / general_info or empty. Picks the domain instructions sent with the question and the run filter.' },
                    { name: 'Difficulty', desc: 'Required: easy / hard / adversarial / insufficient info.' },
                    { name: 'Risk level / language / country / task type', desc: 'Classification for follow-up. The country is exactly 2 letters.' },
                    { name: 'Notes', desc: 'Up to 1000 chars, an admin note never sent to the model.' },
                    { name: 'Status', desc: 'Off = runs skip it.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Add a question the model must refuse',
                steps: ['Click "Add case".', 'Type the question.', 'Pick "Should abstain" and the "Adversarial" difficulty.', 'Save, then start a run from the runs screen.'],
            },
        ],
        affects: [
            { route: 'admin.ai-benchmark-runs.index', text: 'Each run sends only the active cases not in the trash; past results still name a case moved to the trash.' },
        ],
        warnings: [
            { level: 'warning', text: 'Delete for good is blocked while past runs have results for the case (they would be deleted with it). Use the trash or switch it off.' },
            { level: 'info', text: 'Nothing is translated here: the question, keywords and notes are test data.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-benchmark-cases/index.vue (countries-style list, no translations)',
            'resources/js/modules/admin/themes/theme-1/views/ai-benchmark-cases/ModalCreateAndUpdate.vue',
            'resources/js/composables/useAiQualityScreens.js (useAiBenchmarkCases), stores/aiQualityScreens.js',
            'Modules/AI/app/Http/Controllers/AiBenchmarkCaseController.php (CatalogController; guarded by admin.screen on the route group)',
            'Modules/AI/app/Repositories/AiBenchmarkCaseRepository.php (BaseRepository; results_count; force delete blocked by results)',
            'Modules/AI/app/Models/AiBenchmarkCase.php (SoftDeletes, status, is_active alias)',
            'Modules/AI/app/Services/AiBenchmarkRunner.php (cases where status, not trashed)',
        ],
        api: [
            'GET|POST       /api/admin/v1/ai-benchmark-cases            (?status_counts=1, search, filterColumns, trashed)',
            'GET|PUT|DELETE /api/admin/v1/ai-benchmark-cases/{case}',
            'PATCH          /api/admin/v1/ai-benchmark-cases/{case}/status',
            'POST           /api/admin/v1/ai-benchmark-cases/delete-multiple',
            'POST           /api/admin/v1/ai-benchmark-cases/{case}/restore',
            'DELETE         /api/admin/v1/ai-benchmark-cases/{case}/force',
        ],
        tables: ['ai_benchmark_cases (domain_key, country_code, language, task_type, difficulty, risk_level, prompt, expected_behavior, expected_answer_keywords, notes, status, deleted_at)'],
        permissions: [
            'ai-benchmark-cases.view (screen, list)',
            'ai-benchmark-cases.create · ai-benchmark-cases.update (edit, restore)',
            'ai-benchmark-cases.delete (trash, force delete) · ai-benchmark-cases.change-status · ai-benchmark-cases.multiple-delete',
        ],
        noTranslatableText: 'A benchmark case holds test data, not labels people read: the question sent to the model, the expected keywords, codes and free admin notes.',
        decisions: [
            {
                ar: 'مفيش جدول ترجمة: السؤال والكلمات بيانات اختبار والملاحظات للأدمن.',
                en: 'No translation table: the question and keywords are test data and the notes are for the admin.',
            },
        ],
    },
};
