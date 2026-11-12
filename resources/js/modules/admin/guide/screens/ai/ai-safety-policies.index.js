/**
 * Guide: AI safety policies (admin.ai-safety-policies.index) - the groups of safety rules every chat message is
 * checked against.
 * Source of truth checked: views/ai-safety-policies/*.vue, composables/useAiControlScreens.js (useAiSafetyPolicies),
 * Modules/AI/app/Models/AiSafetyPolicy.php (+ AiSafetyPolicyTranslation, Concerns/HasFlatTranslatedText.php),
 * Repositories/AiSafetyPolicyRepository.php (dropdown, force-delete blocks), Http/Controllers/AiSafetyPolicyController.php,
 * Http/Requests/AiSafetyPolicyRequest.php, Http/Resources/AiSafetyPolicyResource.php, Services/AiSafetyGuard.php,
 * Models/AiSafetyEvent.php, Support/AiAdminScreens.php (readers), routes/admin.php,
 * database/migrations/2026_11_10_150200_make_ai_safety_policies_translatable.php.
 */
export default {
    route: 'admin.ai-safety-policies.index',
    updated: '2026-11-10',

    ar: {
        summary: 'هنا سياسات الأمان: كل سياسة مجموعة قواعد أمان (في شاشة "قواعد الأمان") بتتفحص عليها كل رسالة قبل ما توصل لمزود الذكاء الاصطناعي.',
        audience: 'مسؤول الذكاء الاصطناعي أو الأمان. محتاج صلاحيات "سياسات الأمان": عرض، إضافة، تعديل، حذف، تغيير الحالة، حذف متعدد.',
        purpose: [
            'السياسة نفسها مش بتمنع حاجة؛ قواعدها هي اللي بتقرر. السياسة بتشغّل أو بتوقف قواعدها كلها مرة واحدة.',
            'السياسة المقفولة أو اللي في السلة: ولا قاعدة من قواعدها بتتطبق.',
        ],
        sections: [
            {
                title: 'شريط الأدوات',
                items: [
                    { name: 'البحث', desc: 'بالاسم أو الوصف (أي لغة) أو مستوى الخطورة.' },
                    { name: 'الكل / مفعّلة / متوقفة / المحذوفات', desc: 'فلترة بالأعداد، والسلة بتظهر لو فيها حاجة.' },
                    { name: 'حذف المحدد / إضافة سياسة', desc: 'حسب صلاحياتك.' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'الاسم', desc: 'بلغتك، وتحته الوصف ورقم السياسة. دوس عليه يفتح التعديل.' },
                    { name: 'مستوى الخطورة', desc: 'منخفض / متوسط / عالي / حرج.' },
                    { name: 'ينطبق على', desc: 'السياقات المختارة (شات، Agent، أداة، كود).' },
                    { name: 'القواعد', desc: 'عدد قواعدها اللي مش في السلة.' },
                    { name: 'الحالة / التاريخ / الأزرار', desc: 'زي شاشة الدول.' },
                ],
            },
            {
                title: 'فورم السياسة',
                items: [
                    { name: 'الاسم (تاب لكل لغة)', desc: 'إجباري في كل لغة، من 2 لـ 150 حرف. بيظهر في القواعد وسجل أحداث الأمان.' },
                    { name: 'الوصف (تاب لكل لغة)', desc: 'اختياري، لحد 1000 حرف.' },
                    { name: 'مستوى الخطورة', desc: 'للأدمن بس؛ الحارس مش بيقراه.' },
                    { name: 'ينطبق على', desc: 'بيتحفظ للتوضيح؛ الحارس بيفحص كل رسالة شات وكل مهمة ذكاء اصطناعي حاليًا.' },
                    { name: 'الحالة', desc: 'مقفولة = ولا قاعدة من قواعدها بتتطبق.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'وقّف كل قواعد سياسة مرة واحدة',
                steps: ['اقفل زرار الحالة في صف السياسة - قواعدها كلها بتبطل فورًا.'],
            },
            {
                title: 'امسح سياسة نهائيًا',
                steps: ['انقل قواعدها للسلة وامسحها نهائيًا من "قواعد الأمان" الأول.', 'امسح السياسة (بتروح السلة)، وبعدين من السلة "حذف نهائي".', 'لو ليها أحداث أمان في السجل، الحذف النهائي مش هيتم - سيبها في السلة.'],
            },
        ],
        affects: [
            { route: 'admin.ai-safety-rules.index', text: 'كل قاعدة تابعة لسياسة، وفورم القاعدة بيختار من السياسات اللي مش في السلة.' },
            { route: 'admin.ai-safety-events.index', text: 'سجل أحداث الأمان بيعرض اسم السياسة حتى لو في السلة.' },
            { text: 'شات دُر بالذكاء الاصطناعي ومهام الذكاء الاصطناعي: الرسايل اللي بتتمنع أو بتتنضف أو بتتسجل للمراجعة.' },
        ],
        warnings: [
            { level: 'warning', text: 'نقل السياسة للسلة زي إيقافها: قواعدها بتبطل فورًا وبتفضل موجودة لو رجعتها.' },
            { level: 'warning', text: 'الحذف النهائي ممنوع طول ما ليها قواعد (حتى في السلة) - كانت هتتمسح معاها - أو أحداث أمان في السجل.' },
        ],
    },

    en: {
        summary: 'Safety policies: each policy groups safety rules (on the "Safety rules" screen) that every message is checked against before it reaches an AI provider.',
        audience: 'AI or security administrator. Needs the "Safety policies" permissions: view, create, update, delete, change status, multiple delete.',
        purpose: [
            'A policy blocks nothing itself; its rules decide. The policy switches all its rules on or off at once.',
            'A switched-off or trashed policy: none of its rules is applied.',
        ],
        sections: [
            {
                title: 'Toolbar',
                items: [
                    { name: 'Search', desc: 'By name, description (any language) or risk level.' },
                    { name: 'All / Active / Inactive / Trash', desc: 'Filters with counts; Trash shows when it has rows.' },
                    { name: 'Delete selected / Add policy', desc: 'Per your permissions.' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Name', desc: 'In your language, with the description and the policy id. Click to edit.' },
                    { name: 'Risk level', desc: 'Low / medium / high / critical.' },
                    { name: 'Applies to', desc: 'The contexts picked (chat, agent, tool, code).' },
                    { name: 'Rules', desc: 'How many of its rules are not in the trash.' },
                    { name: 'Status / dates / buttons', desc: 'Like countries.' },
                ],
            },
            {
                title: 'Policy form',
                items: [
                    { name: 'Name (one tab per language)', desc: 'Required in every language, 2-150 chars. Shown on the rules and in the safety events log.' },
                    { name: 'Description (one tab per language)', desc: 'Optional, up to 1000 chars.' },
                    { name: 'Risk level', desc: 'For admins; the guard does not read it.' },
                    { name: 'Applies to', desc: 'Stored for reference; the guard checks every chat message and AI task today.' },
                    { name: 'Status', desc: 'Off = none of its rules is applied.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Pause all the rules of a policy',
                steps: ['Switch the policy off - all its rules stop at once.'],
            },
            {
                title: 'Delete a policy for good',
                steps: ['First move its rules to the trash and delete them for good on "Safety rules".', 'Delete the policy (it goes to the trash), then "Delete for good" from the trash.', 'If safety events name it, delete for good is refused - keep it in the trash.'],
            },
        ],
        affects: [
            { route: 'admin.ai-safety-rules.index', text: 'Every rule belongs to a policy; the rule form picks from the policies not in the trash.' },
            { route: 'admin.ai-safety-events.index', text: 'The safety events log still names a policy in the trash.' },
            { text: 'The DORR AI chat and AI tasks: the messages that are blocked, sanitized or logged for review.' },
        ],
        warnings: [
            { level: 'warning', text: 'Moving a policy to the trash is the same as switching it off: its rules stop at once and stay for a restore.' },
            { level: 'warning', text: 'Delete for good is refused while it has rules (the trash included) - they would be deleted with it - or safety events name it.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-safety-policies/index.vue (countries-style list)',
            'resources/js/modules/admin/themes/theme-1/views/ai-safety-policies/ModalCreateAndUpdate.vue (name / description tabs)',
            'resources/js/composables/useAiControlScreens.js (useAiSafetyPolicies), stores/aiControlScreens.js',
            'Modules/AI/app/Http/Controllers/AiSafetyPolicyController.php (CatalogController; guarded by admin.screen on the route group)',
            'Modules/AI/app/Repositories/AiSafetyPolicyRepository.php (TranslatableRepository; dropdown; force delete blocked by safetyRules / safetyEvents)',
            'Modules/AI/app/Models/AiSafetyPolicy.php (SoftDeletes, status, is_active alias)',
            'Modules/AI/app/Services/AiSafetyGuard.php (rules whose policy has status = true and is not trashed)',
        ],
        api: [
            'GET|POST       /api/admin/v1/ai-safety-policies            (?status_counts=1, search, filterColumns, trashed)',
            'GET            /api/admin/v1/ai-safety-policies/dropdown   (the safety rules form; not trashed, paused ones too)',
            'GET|PUT|DELETE /api/admin/v1/ai-safety-policies/{policy}',
            'PATCH          /api/admin/v1/ai-safety-policies/{policy}/status',
            'POST           /api/admin/v1/ai-safety-policies/delete-multiple',
            'POST           /api/admin/v1/ai-safety-policies/{policy}/restore',
            'DELETE         /api/admin/v1/ai-safety-policies/{policy}/force',
        ],
        tables: [
            'ai_safety_policies (risk_level, applies_to JSON, status, deleted_at)',
            'ai_safety_policy_translations (ai_safety_policy_id, locale, name, description)',
        ],
        permissions: [
            'ai-safety-policies.view (screen, list, dropdown) - ai-safety-rules.view may also read it (AiAdminScreens::READERS)',
            'ai-safety-policies.create · ai-safety-policies.update (edit, restore)',
            'ai-safety-policies.delete (trash, force delete) · ai-safety-policies.change-status · ai-safety-policies.multiple-delete',
        ],
        decisions: [
            {
                ar: 'الاسم والوصف بس اللي بيتترجموا؛ مستوى الخطورة والسياقات قيم تقنية.',
                en: 'Only the name and description are translated; the risk level and contexts are technical values.',
            },
        ],
    },
};
