/**
 * Guide: freelance skill tests and badges (admin.freelance.skillTests).
 * Source of truth checked: views/freelance/skill-tests/index.vue, Modules/Freelance/routes/admin.php (freelance-skill-tests,
 * freelance-skill-questions, freelance-skill-badges), Modules/Freelance/app/Http/Controllers/Admin/FreelanceSkillTestController.php,
 * Modules/Freelance/app/Services/Skills/FreelanceSkillTests.php (MIN_QUESTIONS = 3, settings, revoke), locales freelance.skill_tests.
 */
export default {
    route: 'admin.freelance.skillTests',
    updated: '2026-11-11',

    ar: {
        summary: 'بنك أسئلة اختيار من متعدد لكل مهارة واختبارها. المحترف اللي عنده المهارة بياخد أسئلة عشوائية، والسيرفر بيصحّح، ولو نجح ياخد شارة موثّقة بتظهر للعميل وتدخل في ترتيب "الموصى بهم". ومن هنا كمان بتلغي شارة.',
        audience: 'فريق جودة الشغل الحر. نفس صلاحيات القوائم: "قوائم الشغل الحر: عرض" للفتح، "إنشاء" لإضافة سؤال، "تعديل" للإعدادات وتعديل سؤال وإلغاء شارة، "حذف" لحذف سؤال.',
        purpose: [
            'تشغّل اختبار لمهارة وتظبط عدد الأسئلة والمدة ودرجة النجاح والانتظار قبل الإعادة.',
            'تكتب الأسئلة باللغتين وتعلّم الإجابة الصح.',
            'تتابع الشارات وتلغي شارة اتاخدت بغش أو غلط.',
        ],
        sections: [
            {
                title: 'تبويب الاختبارات',
                items: [
                    { name: 'البحث والفلتر', desc: 'بحث باسم المهارة، وفلتر: كل المهارات / فيها أسئلة / اختبارها شغّال. المهارات المفعّلة بس، الأكتر استخدامًا الأول.' },
                    { name: 'الأعمدة', desc: 'المهارة، عدد الأسئلة، الشغّالة منها، حالة الاختبار، عدد الشارات السارية.' },
                    { name: 'إعداد الاختبار', desc: 'بيفتح المهارة: الإعدادات وبنك الأسئلة.' },
                ],
            },
            {
                title: 'إعدادات الاختبار',
                items: [
                    { name: 'عدد الأسئلة في المحاولة', desc: 'من 3 لـ 50.' },
                    { name: 'المدة بالدقايق', desc: 'من 1 لـ 180.' },
                    { name: 'درجة النجاح (%)', desc: 'من 1 لـ 100.' },
                    { name: 'الانتظار قبل الإعادة (ساعات)', desc: 'من 0 لـ 8760 (سنة).' },
                    { name: 'الاختبار شغّال', desc: 'مينفعش يتشغّل غير لو فيه 3 أسئلة شغّالة على الأقل.' },
                ],
            },
            {
                title: 'بنك الأسئلة',
                items: [
                    { name: 'السؤال (ع / EN)', desc: 'إجباري باللغتين، من 3 لـ 500 حرف.' },
                    { name: 'الاختيارات', desc: 'من 2 لـ 6، كل اختيار باللغتين (لحد 300 حرف)، وإجابة صح واحدة بالظبط.' },
                    { name: 'شغّال', desc: 'السؤال المتوقف مش بيدخل في الاختبار.' },
                    { name: 'تعديل / حذف', desc: 'حسب صلاحياتك.' },
                ],
            },
            {
                title: 'تبويب الشارات',
                items: [
                    { name: 'الفلتر', desc: 'سارية / ملغية / الكل.' },
                    { name: 'الأعمدة', desc: 'المحترف، المهارة، الدرجة، تاريخ الحصول، تاريخ الانتهاء (أو "من غير انتهاء").' },
                    { name: 'إلغاء الشارة', desc: 'مع سبب اختياري (لحد 200 حرف).' },
                ],
            },
        ],
        tasks: [
            {
                title: 'تشغيل اختبار لمهارة',
                steps: [
                    'دوس "إعداد الاختبار" على المهارة.',
                    'ضيف 3 أسئلة على الأقل باللغتين وعلّم الإجابة الصح لكل سؤال.',
                    'اظبط العدد والمدة ودرجة النجاح، وشغّل "الاختبار شغّال"، ودوس حفظ.',
                ],
            },
            {
                title: 'إلغاء شارة',
                steps: [
                    'تبويب الشارات ← "سارية".',
                    'دوس "إلغاء الشارة"، اكتب السبب، وأكّد.',
                ],
            },
        ],
        affects: [
            { text: 'تطبيق المحترف: قايمة الاختبارات المتاحة للمهارات اللي عنده.' },
            { text: 'الشارة الموثّقة بتظهر للعميل في ملف المحترف وبتدخل في ترتيب "الموصى بهم".' },
            { route: 'admin.freelance.skills', text: 'المهارات نفسها بتتدار في شاشة المهارات؛ المتوقفة مش بتظهر هنا.' },
        ],
        warnings: [
            { level: 'warning', text: 'لو عدّلت أو حذفت سؤال والأسئلة الشغّالة بقت أقل من 3، الاختبار بيتقفل لوحده علشان محدش ياخد نص اختبار.' },
            { level: 'warning', text: 'الشاشة دي بتعرض الإجابات الصح، فصلاحية العرض نفسها حساسة.' },
            { level: 'info', text: 'إلغاء الشارة كمان بيلغي فترة الانتظار: المحترف يقدر يعيد الاختبار على طول.' },
        ],
    },

    en: {
        summary: 'A multiple-choice question bank and test per skill. A freelancer with the skill gets random questions, the server marks them, and a pass earns a verified badge shown to clients and used in "Recommended" ranking. Badges can be revoked here too.',
        audience: 'Freelance quality team. Same permissions as the catalogs: "Freelance catalogs: view" to open, create to add a question, update for settings, editing a question and revoking a badge, delete to delete a question.',
        purpose: [
            'Switch a skill\'s test on and set question count, duration, pass mark and retake wait.',
            'Write the questions in both languages and mark the right answer.',
            'Follow badges and revoke one earned by cheating or mistake.',
        ],
        sections: [
            {
                title: 'Tests tab',
                items: [
                    { name: 'Search and filter', desc: 'Search by skill name; filter all / with questions / test on. Active skills only, most used first.' },
                    { name: 'Columns', desc: 'Skill, questions, active questions, test status, valid badges.' },
                    { name: 'Configure', desc: 'Opens the skill: settings and question bank.' },
                ],
            },
            {
                title: 'Test settings',
                items: [
                    { name: 'Questions per attempt', desc: '3–50.' },
                    { name: 'Minutes', desc: '1–180.' },
                    { name: 'Pass mark (%)', desc: '1–100.' },
                    { name: 'Wait before retake (hours)', desc: '0–8760 (a year).' },
                    { name: 'Test on', desc: 'Can only be switched on with at least 3 active questions.' },
                ],
            },
            {
                title: 'Question bank',
                items: [
                    { name: 'Question (AR / EN)', desc: 'Required in both languages, 3–500 characters.' },
                    { name: 'Options', desc: '2–6, each in both languages (up to 300 characters), exactly one right answer.' },
                    { name: 'Active', desc: 'An inactive question is left out of the test.' },
                    { name: 'Edit / Delete', desc: 'As your permissions allow.' },
                ],
            },
            {
                title: 'Badges tab',
                items: [
                    { name: 'Filter', desc: 'Valid / revoked / all.' },
                    { name: 'Columns', desc: 'Freelancer, skill, score, earned on, expires (or "never").' },
                    { name: 'Revoke', desc: 'With an optional reason (up to 200 characters).' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Switch a skill test on',
                steps: [
                    'Click "Configure" on the skill.',
                    'Add at least 3 questions in both languages, each with its right answer.',
                    'Set count, minutes and pass mark, switch "Test on" and save.',
                ],
            },
            {
                title: 'Revoke a badge',
                steps: [
                    'Badges tab → "Valid".',
                    'Click "Revoke", write the reason and confirm.',
                ],
            },
        ],
        affects: [
            { text: 'Freelancer app: the tests offered for the freelancer\'s skills.' },
            { text: 'The verified badge shows to clients on the freelancer\'s profile and counts in "Recommended" ranking.' },
            { route: 'admin.freelance.skills', text: 'Skills themselves are managed in Skills; inactive ones do not show here.' },
        ],
        warnings: [
            { level: 'warning', text: 'If editing or deleting a question leaves fewer than 3 active ones, the test switches itself off so nobody gets half a test.' },
            { level: 'warning', text: 'This screen shows the right answers, so its view permission is sensitive.' },
            { level: 'info', text: 'Revoking a badge also clears the retake wait: the freelancer can retake at once.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/freelance/skill-tests/index.vue',
            'Modules/Freelance/app/Http/Controllers/Admin/FreelanceSkillTestController.php',
            'Modules/Freelance/app/Services/Skills/FreelanceSkillTests.php',
            'Modules/Freelance/app/Models/FreelanceSkillTest.php, FreelanceSkillQuestion.php, FreelanceSkillAttempt.php, FreelancerSkillBadge.php',
        ],
        api: [
            'GET  /api/admin/v1/freelance-skill-tests   (?search, only=with_test|on, per_page) · GET|PUT …/{skill}',
            'POST /api/admin/v1/freelance-skill-tests/{skill}/questions · PUT|DELETE /api/admin/v1/freelance-skill-questions/{question}',
            'GET  /api/admin/v1/freelance-skill-badges  (?skill_id, state=valid|revoked|all) · POST …/{badge}/revoke {reason?}',
        ],
        tables: ['freelance_skill_tests', 'freelance_skill_questions (prompt / options json {ar, en})', 'freelance_skill_attempts', 'freelancer_skill_badges'],
        permissions: ['freelance-catalogs.view (index, show, badges)', 'freelance-catalogs.create (storeQuestion)', 'freelance-catalogs.update (update, updateQuestion, revoke)', 'freelance-catalogs.delete (destroyQuestion)'],
        decisions: [
            {
                ar: 'الشاشة مشتركة في صلاحية "قوائم الشغل الحر" مع المهارات والتخصصات. محتاج قرار: صلاحية خاصة (freelance-skill-tests) لأنها بتعرض الإجابات الصح؟',
                en: 'The screen shares the "freelance-catalogs" permission with skills and specialties. NEEDS-DECISION: its own permission (freelance-skill-tests), since it shows the right answers?',
            },
        ],
        noTranslatableText: 'Questions and options are bilingual by design: each is stored as {ar, en} JSON and both languages are required on the same form; there are no catalog names to translate.',
    },
};
