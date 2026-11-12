/**
 * Guide: AI safety rules (admin.ai-safety-rules.index) - "when a message matches this, do that", checked before any
 * message reaches an AI provider.
 * Source of truth checked: views/ai-safety-rules/*.vue, composables/useAiControlScreens.js (useAiSafetyRules),
 * Modules/AI/app/Models/AiSafetyRule.php (+ AiSafetyRuleTranslation, Concerns/HasFlatTranslatedText.php),
 * Repositories/AiSafetyRuleRepository.php, Http/Controllers/AiSafetyRuleController.php,
 * Http/Requests/AiSafetyRuleRequest.php, Http/Resources/AiSafetyRuleResource.php, Services/AiSafetyGuard.php,
 * Services/AiChatService.php and Services/AiTaskRunner.php (what each action does), Models/AiSafetyEvent.php,
 * routes/admin.php, database/migrations/2026_11_10_150300_make_ai_safety_rules_translatable.php.
 */
export default {
    route: 'admin.ai-safety-rules.index',
    updated: '2026-11-11',

    ar: {
        summary: 'هنا قواعد الأمان: كل قاعدة جوه سياسة بتقول "لو الرسالة فيها الكلمات دي (أو درجة خطورتها عالية) اعمل كذا" - تعدّي، تمنع، تسجّل للمراجعة، أو تنضّف الكلمات.',
        audience: 'مسؤول الذكاء الاصطناعي أو الأمان. محتاج صلاحيات "قواعد الأمان": عرض، إضافة، تعديل، حذف، تغيير الحالة، حذف متعدد.',
        purpose: [
            'كل رسالة شات وكل مهمة ذكاء اصطناعي بتتفحص على القواعد المفعّلة اللي سياستها مفعّلة، من الأعلى أولوية للأقل.',
            'أول قاعدة تطابق هي اللي بتقرر، والباقي مش بيتفحص.',
            'القاعدة المقفولة أو اللي في السلة (أو سياستها مقفولة أو في السلة) مش بتتطبق.',
        ],
        sections: [
            {
                title: 'شريط الأدوات',
                items: [
                    { name: 'البحث', desc: 'باسم القاعدة (أي لغة) أو الإجراء (block…) أو اسم السياسة.' },
                    { name: 'الكل / مفعّلة / متوقفة / المحذوفات', desc: 'فلترة بالأعداد، والسلة بتظهر لو فيها حاجة.' },
                    { name: 'حذف المحدد / إضافة قاعدة', desc: 'حسب صلاحياتك.' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'الاسم', desc: 'بلغتك، وتحته ملخص الشرط ورقم القاعدة. دوس عليه يفتح التعديل.' },
                    { name: 'السياسة', desc: 'اسمها، و"متوقفة"/"محذوف" لو كده = القاعدة مش هتشتغل.' },
                    { name: 'الإجراء / الأولوية', desc: 'إيه اللي بيحصل لما تطابق، والأعلى بيتفحص الأول.' },
                    { name: 'الحالة / التاريخ / الأزرار', desc: 'زي شاشة الدول.' },
                ],
            },
            {
                title: 'فورم القاعدة',
                items: [
                    { name: 'الاسم (تاب لكل لغة)', desc: 'إجباري في كل لغة، من 2 لـ 150 حرف. بيظهر في سجل أحداث الأمان.' },
                    { name: 'سياسة الأمان', desc: 'إجباري، من السياسات اللي مش في السلة (والسياسة الحالية بتفضل لو اتنقلت للسلة).' },
                    { name: 'الإجراء', desc: 'سماح: تعدّي وتوقف الفحص. حظر: الرسالة مش بتوصل للذكاء الاصطناعي والشخص بياخد رد أمان وبيتسجل حدث. مراجعة: تعدّي وبيتسجل حدث. تنظيف: الكلمات بتتبدل بنجوم وبيتسجل حدث. طلب تأكيد: أول مرة الرسالة مش بتوصل للذكاء الاصطناعي والمساعد بيطلب من الشخص يأكد، وبيتسجل حدث؛ لو بعت نفس الرسالة تاني على طول (في نفس المحادثة، خلال 30 دقيقة) يبقى أكّد وبتكمل عادي. في المهام اللي بتشتغل مرة واحدة من غير محادثة مفيش حد يتسأل، فالنص بيتمنع زي الحظر.' },
                    { name: 'الأولوية', desc: '0 أو أكتر، فاضي = 0. الأعلى الأول.' },
                    { name: 'الحالة', desc: 'مقفولة = مش بتتطبق.' },
                    { name: 'الشرط (JSON)', desc: 'keyword_match مع keywords (أي لغة، من غير فرق في الحروف)، أو risk_score مع min_score من 0 لـ 1 (كل ربع = فئة خطر: عنف، أسلحة، إيذاء النفس، اختراق). فاضي = عمرها ما بتطابق. قيمة تقنية مش بتتترجم.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'امنع كلمة معينة',
                steps: ['دوس "إضافة قاعدة".', 'اكتب الاسم بكل اللغات واختار السياسة.', 'اختار الإجراء "حظر" وأولوية عالية.', 'اكتب الشرط: {"type": "keyword_match", "keywords": ["الكلمة", "word"]} ودوس حفظ.'],
            },
            {
                title: 'وقّف قاعدة مؤقتًا',
                steps: ['اقفل زرار الحالة - القاعدة اللي بعدها هي اللي هتتفحص.'],
            },
        ],
        affects: [
            { route: 'admin.ai-safety-policies.index', text: 'كل قاعدة تابعة لسياسة (عدد القواعد بيظهر هناك).' },
            { route: 'admin.ai-safety-events.index', text: 'الحظر والمراجعة والتنظيف بيسجلوا حدث فيه اسم القاعدة، حتى لو اتنقلت للسلة بعدين.' },
            { text: 'شات دُر بالذكاء الاصطناعي ومهام الذكاء الاصطناعي في التطبيقات وشات دُر.' },
        ],
        warnings: [
            { level: 'warning', text: 'نقل القاعدة للسلة زي إيقافها: مش بتتطبق فورًا.' },
            { level: 'warning', text: 'الحذف النهائي ممنوع طول ما فيه أحداث أمان في السجل باسمها.' },
            { level: 'danger', text: 'قاعدة "سماح" بأولوية عالية بتعدّي الرسايل اللي تطابقها من غير ما تتفحص على باقي القواعد.' },
            { level: 'info', text: '"طلب تأكيد": أي رسالة مختلفة بعد السؤال مش بتتحسب تأكيد - المساعد بيسأل تاني. التطبيقات مش محتاجة تتغير: السؤال بيوصل كرد عادي من المساعد.' },
        ],
    },

    en: {
        summary: 'Safety rules: each rule inside a policy says "when the message has these words (or a high risk score) do this" - let it through, block it, log it for review, or sanitize the words.',
        audience: 'AI or security administrator. Needs the "Safety rules" permissions: view, create, update, delete, change status, multiple delete.',
        purpose: [
            'Every chat message and AI task is checked against the active rules of active policies, highest priority first.',
            'The first matching rule decides; the rest are not checked.',
            'A switched-off or trashed rule (or one whose policy is off or trashed) is never applied.',
        ],
        sections: [
            {
                title: 'Toolbar',
                items: [
                    { name: 'Search', desc: 'By rule name (any language), action (block…) or policy name.' },
                    { name: 'All / Active / Inactive / Trash', desc: 'Filters with counts; Trash shows when it has rows.' },
                    { name: 'Delete selected / Add rule', desc: 'Per your permissions.' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Name', desc: 'In your language, with a condition summary and the rule id. Click to edit.' },
                    { name: 'Policy', desc: 'Its name; "Inactive"/"Deleted" = the rule never applies.' },
                    { name: 'Action / priority', desc: 'What happens on a match; the highest is checked first.' },
                    { name: 'Status / dates / buttons', desc: 'Like countries.' },
                ],
            },
            {
                title: 'Rule form',
                items: [
                    { name: 'Name (one tab per language)', desc: 'Required in every language, 2-150 chars. Shown in the safety events log.' },
                    { name: 'Safety policy', desc: 'Required, from the policies not in the trash (the current one stays when it was trashed).' },
                    { name: 'Action', desc: 'Allow: passes and stops checking. Block: never reaches the AI, the person gets a safety reply, an event is logged. Review: passes, an event is logged. Sanitize: the words become stars, an event is logged. Require confirmation: the first time the message never reaches the AI, the assistant asks the person to confirm and an event is logged; sending the very same message again right after (same conversation, within 30 minutes) confirms it and it goes on. One-shot tasks with no conversation have nobody to ask, so the text is held back like Block.' },
                    { name: 'Priority', desc: '0 or more, empty = 0. Highest first.' },
                    { name: 'Status', desc: 'Off = never applied.' },
                    { name: 'Condition (JSON)', desc: 'keyword_match with keywords (any language, case-insensitive), or risk_score with min_score from 0 to 1 (each quarter = one risk category: violence, weapons, self-harm, hacking). Empty = never matches. Technical, not translated.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Block a word',
                steps: ['Click "Add rule".', 'Type the name in every language and pick the policy.', 'Pick the action "Block" and a high priority.', 'Type the condition: {"type": "keyword_match", "keywords": ["word"]} and save.'],
            },
            {
                title: 'Pause a rule',
                steps: ['Switch its status off - the next rule is checked instead.'],
            },
        ],
        affects: [
            { route: 'admin.ai-safety-policies.index', text: 'Every rule belongs to a policy (its rule count shows there).' },
            { route: 'admin.ai-safety-events.index', text: 'Block, review and sanitize log an event naming the rule, even if it is trashed later.' },
            { text: 'The DORR AI chat and AI tasks in the apps and DORR chat.' },
        ],
        warnings: [
            { level: 'warning', text: 'Moving a rule to the trash is the same as switching it off: it stops applying at once.' },
            { level: 'warning', text: 'Delete for good is refused while safety events name it.' },
            { level: 'danger', text: 'A high-priority "Allow" rule lets matching messages through without checking the other rules.' },
            { level: 'info', text: '"Require confirmation": any different message after the question is not a confirmation - the assistant asks again. The apps need no change: the question arrives as a normal assistant reply.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-safety-rules/index.vue (countries-style list)',
            'resources/js/modules/admin/themes/theme-1/views/ai-safety-rules/ModalCreateAndUpdate.vue (name tabs; policy picker: ai-safety-policies/dropdown)',
            'resources/js/composables/useAiControlScreens.js (useAiSafetyRules: search in action + name + policy name), stores/aiControlScreens.js',
            'Modules/AI/app/Http/Controllers/AiSafetyRuleController.php (CatalogController; guarded by admin.screen on the route group)',
            'Modules/AI/app/Repositories/AiSafetyRuleRepository.php (TranslatableRepository; force delete blocked by safetyEvents)',
            'Modules/AI/app/Models/AiSafetyRule.php (SoftDeletes, status, is_active alias; policy withTrashed)',
            'Modules/AI/app/Services/AiSafetyGuard.php (status = true, policy status = true and not trashed, priority desc)',
        ],
        api: [
            'GET|POST       /api/admin/v1/ai-safety-rules            (?status_counts=1, search, filterColumns, trashed)',
            'GET|PUT|DELETE /api/admin/v1/ai-safety-rules/{rule}',
            'PATCH          /api/admin/v1/ai-safety-rules/{rule}/status',
            'POST           /api/admin/v1/ai-safety-rules/delete-multiple',
            'POST           /api/admin/v1/ai-safety-rules/{rule}/restore',
            'DELETE         /api/admin/v1/ai-safety-rules/{rule}/force',
        ],
        tables: [
            'ai_safety_rules (safety_policy_id, condition JSON, action, priority, status, deleted_at)',
            'ai_safety_rule_translations (ai_safety_rule_id, locale, name)',
        ],
        permissions: [
            'ai-safety-rules.view (screen, list; also reads ai-safety-policies/dropdown)',
            'ai-safety-rules.create · ai-safety-rules.update (edit, restore)',
            'ai-safety-rules.delete (trash, force delete) · ai-safety-rules.change-status · ai-safety-rules.multiple-delete',
        ],
        decisions: [
            {
                ar: 'الاسم بس اللي بيتترجم؛ الشرط والإجراء والأولوية قيم تقنية.',
                en: 'Only the name is translated; the condition, action and priority are technical values.',
            },
        ],
    },
};
