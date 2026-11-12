/**
 * Guide: AI routing policies (admin.ai-routing-policies.index) - which set of routing rules applies to a chat message.
 * Source of truth checked: views/ai-routing-policies/*.vue, composables/useAiRoutingScreens.js (useAiRoutingPolicies),
 * Modules/AI/app/Models/AiRoutingPolicy.php (+ AiRoutingPolicyTranslation, Concerns/HasFlatTranslatedText.php),
 * Repositories/AiRoutingPolicyRepository.php, Http/Controllers/AiRoutingPolicyController.php,
 * Http/Requests/AiRoutingPolicyRequest.php, Http/Resources/AiRoutingPolicyResource.php, Services/AiRoutingEngine.php,
 * Services/AiChatService.php (fallback_enabled), Support/AiAdminScreens.php (readers), routes/admin.php.
 */
export default {
    route: 'admin.ai-routing-policies.index',
    updated: '2026-11-11',

    ar: {
        summary: 'هنا سياسات التوجيه: كل سياسة بتقول قواعد التوجيه بتاعتها تنطبق على مين (كل الرسايل أو دولة معينة). لكل رسالة الشات بياخد أعلى سياسة مفعّلة في الأولوية تنطبق عليها، وبعدين يجرّب قواعدها.',
        audience: 'مسؤول الذكاء الاصطناعي. محتاج صلاحيات "سياسات التوجيه": عرض، إضافة، تعديل، حذف، تغيير الحالة، حذف متعدد.',
        purpose: [
            'تقسيم التوجيه: سياسة عامة لكل الرسايل، وسياسة خاصة لدولة.',
            'لو مفيش سياسة نفعت بالنطاق، الشات بياخد السياسة الافتراضية للبوابة (لو مفعّلة ومش في السلة).',
            'لو مفيش سياسة خالص أو مفيش قاعدة تنفع، الشات بيروح للمزود الافتراضي المفعّل. لو مفيش سياسة تنفع (ولا الافتراضية لأنها متوقفة أو في السلة)، الشات مش بيقف: بيشتغل بالإعدادات الافتراضية المدمجة (البديل والاستراتيجية من إعدادات السيرفر ai.routing.defaults) وبيكتب تحذير في اللوج - ومش بيستخدم قواعد السياسة اللي انت قفلتها.',
        ],
        sections: [
            {
                title: 'شريط الأدوات',
                items: [
                    { name: 'البحث', desc: 'بالاسم (في أي لغة) أو النطاق أو كود الدولة أو مفتاح الخدمة.' },
                    { name: 'الكل / مفعّلة / متوقفة', desc: 'فلترة سريعة وجنب كل واحدة العدد.' },
                    { name: 'المحذوفات', desc: 'بتظهر لو فيه سياسات في السلة: ترجّع أو تحذف نهائي.' },
                    { name: 'حذف المحدد / إضافة سياسة', desc: 'حسب صلاحياتك ("حذف متعدد" و"إضافة").' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'الاسم', desc: 'بلغة لوحة التحكم وتحته رقم السياسة. دوس عليه يفتح التعديل.' },
                    { name: 'النطاق', desc: 'عام / حسب الدولة (وتحته الكود) / حسب الخدمة (وتحته المفتاح).' },
                    { name: 'الاستراتيجية والخطة', desc: 'زي ما اتحفظوا.' },
                    { name: 'الأولوية', desc: 'وتحتها "البديل شغّال" لو التجربة على المزود اللي بعده مفتوحة.' },
                    { name: 'القواعد', desc: 'عدد قواعد التوجيه اللي في السياسة (مش في السلة). صفر = السياسة مش هتبعت لحد.' },
                    { name: 'الحالة / التاريخ / الأزرار', desc: 'زي شاشة الدول: تشغيل وإيقاف، تعديل، سلة، ترجيع، حذف نهائي.' },
                ],
            },
            {
                title: 'فورم السياسة',
                items: [
                    { name: 'تاب لكل لغة', desc: 'الاسم إجباري في كل لغة (2 لـ 150 حرف).' },
                    { name: 'نوع النطاق', desc: 'عام = كل الرسايل. حسب الدولة = لو كود دولة اليوزر يساوي الكود. حسب الخدمة = المحرك بيعدّيها حاليًا (الرسالة مفيهاش خدمة).' },
                    { name: 'كود الدولة', desc: 'حرفين (مثلًا SA)، بيتحفظ كبتل. فاضي = مفيش.' },
                    { name: 'مفتاح الخدمة', desc: 'لحد 100 حرف، بيتحفظ بس.' },
                    { name: 'الخطة', desc: 'من قائمة الباقات (حتى المتوقفة). بتتحفظ مع السياسة.' },
                    { name: 'استراتيجية الاختيار', desc: 'بترتب القواعد اللي تنفع (قواعد النية الأول، وبعدين العامة): الأولوية = أعلى قاعدة الأول. بالتناوب = القواعد بتتبادل الدور، كل رسالة على واحدة. الأسرع استجابة = المزود اللي متوسط وقت رده في آخر 24 ساعة (المحاولات الناجحة بس) أقل الأول، واللي مالوش سجلات بعدهم بالأولوية. الباقيين بيفضلوا بدائل لو البديل شغال.' },
                    { name: 'الأولوية', desc: 'رقم 0 أو أكتر، فاضي = 0. الأعلى بيكسب لو أكتر من سياسة نفعت.' },
                    { name: 'تفعيل الخطة البديلة', desc: 'مفتوح = لو أول مزود فشل الشات بيجرّب اللي بعده.' },
                    { name: 'الحالة', desc: 'مقفولة = مش هتستخدم في التوجيه خالص.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'سياسة خاصة بالسعودية',
                steps: ['دوس "إضافة سياسة".', 'اكتب الاسم في كل تاب لغة.', 'اختار النطاق "حسب الدولة" واكتب SA.', 'خلي الأولوية أعلى من السياسة العامة، ودوس حفظ.', 'روح "قواعد التوجيه" وضيف قواعد للسياسة دي.'],
            },
            {
                title: 'إيقاف سياسة مؤقتًا',
                steps: ['اقفل زرار الحالة في الصف - الشات هياخد السياسة اللي بعدها.'],
            },
        ],
        affects: [
            { route: 'admin.ai-routing-rules.index', text: 'قواعد التوجيه: كل قاعدة تابعة لسياسة من هنا.' },
            { route: 'admin.ai-gateways.index', text: 'البوابات: السياسة الافتراضية للبوابة بتتختار من هنا.' },
            { text: 'الشات بالذكاء الاصطناعي في التطبيقات وشات دُر: أي مزود وموديل بيرد على كل رسالة.' },
        ],
        warnings: [
            { level: 'warning', text: 'نقل سياسة للسلة زي إيقافها: الشات مش هيستخدمها لا بالنطاق ولا كسياسة افتراضية لبوابة. قواعدها بتفضل موجودة لو رجّعتها.' },
            { level: 'warning', text: 'الحذف النهائي مش هيتم طول ما فيها قواعد (حتى في السلة) - لأن قاعدة البيانات هتمسحهم معاها - أو بوابة مستخدماها كافتراضية.' },
            { level: 'info', text: 'مفتاح الخدمة بيتحفظ بس؛ المحرك لسه مش بيستخدمه. "الأقل تكلفة" اتشالت من الاختيارات: مفيش سعر متسجل لأي موديل أو مزود يتقارن بيه، وأي سياسة قديمة عليها بتتوجه بالأولوية.' },
            { level: 'info', text: 'الاسم إجباري في كل لغة متفعّلة. لو ضفت لغة جديدة، افتح كل سياسة واكتب لها الاسم.' },
        ],
    },

    en: {
        summary: 'Routing policies: each says which messages its routing rules apply to (every message, or one country). For each message the chat takes the highest-priority active policy that matches, then tries its rules.',
        audience: 'AI administrator. Needs the "Routing policies" permissions: view, create, update, delete, change status, multiple delete.',
        purpose: [
            'Split routing: a global policy for every message, a specific one per country.',
            'When no policy matches by scope, the chat takes the gateway\'s default policy (if on and not in the trash).',
            'With no policy, or no usable rule, the chat uses the active default provider. When no policy is usable (not even the default, because it is off or trashed) the chat does not stop: it runs on the built-in defaults (fallback and strategy from the server setting ai.routing.defaults) and writes a warning to the log - it never uses the rules of the policy you switched off.',
        ],
        sections: [
            {
                title: 'Toolbar',
                items: [
                    { name: 'Search', desc: 'By name (any language), scope, country code or service key.' },
                    { name: 'All / Active / Inactive', desc: 'Quick filters with counts.' },
                    { name: 'Trash', desc: 'Shown when policies are in the trash: restore or delete for good.' },
                    { name: 'Delete selected / Add policy', desc: 'Per your permissions ("multiple delete", "create").' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Name', desc: 'In the panel language with the policy id; click to edit.' },
                    { name: 'Scope', desc: 'Global / By country (code below) / By service (key below).' },
                    { name: 'Strategy and plan', desc: 'As saved.' },
                    { name: 'Priority', desc: 'With "Fallback on" when trying the next provider is enabled.' },
                    { name: 'Rules', desc: 'How many routing rules the policy has (not trashed). Zero = it routes nowhere.' },
                    { name: 'Status / dates / buttons', desc: 'Like countries: on/off, edit, trash, restore, delete for good.' },
                ],
            },
            {
                title: 'Policy form',
                items: [
                    { name: 'One tab per language', desc: 'Name required in every language (2–150 chars).' },
                    { name: 'Scope type', desc: 'Global = every message. By country = when the user\'s country code equals the code. By service = skipped by the engine for now (a chat message has no service).' },
                    { name: 'Country code', desc: 'Two letters (e.g. SA), saved upper-case. Empty = none.' },
                    { name: 'Service key', desc: 'Up to 100 chars, stored only.' },
                    { name: 'Plan', desc: 'From the plans list (paused ones too). Stored with the policy.' },
                    { name: 'Selection strategy', desc: 'Orders the usable rules (intent rules first, then the generic ones): Priority = the highest rule first. Round robin = the rules take turns being first, one message each. Latency optimized = the provider with the lowest average response time over the last 24 hours (successful calls only) first, the ones with no recent calls after them by priority. The others stay as fallbacks when fallback is on.' },
                    { name: 'Priority', desc: '0 or more, empty = 0. The highest wins when several policies match.' },
                    { name: 'Fallback enabled', desc: 'On = if the first provider fails the chat tries the next one.' },
                    { name: 'Status', desc: 'Off = never used for routing.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'A policy for Saudi Arabia',
                steps: ['Click "Add policy".', 'Write the name in every language tab.', 'Pick the scope "By country" and type SA.', 'Give it a higher priority than the global policy and save.', 'Go to "Routing rules" and add rules to this policy.'],
            },
            {
                title: 'Pause a policy',
                steps: ['Switch its status off in the row - the chat takes the next matching policy.'],
            },
        ],
        affects: [
            { route: 'admin.ai-routing-rules.index', text: 'Routing rules: every rule belongs to a policy from here.' },
            { route: 'admin.ai-gateways.index', text: 'Gateways pick their default policy from here.' },
            { text: 'The AI chat in the apps and DORR chat: which provider and model answers each message.' },
        ],
        warnings: [
            { level: 'warning', text: 'Moving a policy to the trash is the same as switching it off: the chat uses it neither by scope nor as a gateway default. Its rules stay for a restore.' },
            { level: 'warning', text: 'Delete for good is refused while it has rules (even in the trash) - the database would delete them with it - or a gateway uses it as its default.' },
            { level: 'info', text: 'The service key is stored only; the engine does not use it yet. "Cost optimized" was removed from the options: no model or provider has a price to compare, and an old policy using it routes by priority.' },
            { level: 'info', text: 'The name is required in every active language. After adding a platform language, open each policy and write its name.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-routing-policies/index.vue (countries-style list)',
            'resources/js/modules/admin/themes/theme-1/views/ai-routing-policies/ModalCreateAndUpdate.vue (useCatalogTranslationFields: name; plan picker = ai-plans/dropdown)',
            'resources/js/composables/useAiRoutingScreens.js (useAiRoutingPolicies), stores/aiRoutingScreens.js',
            'Modules/AI/app/Http/Controllers/AiRoutingPolicyController.php (CatalogController; guarded by admin.screen on the route group)',
            'Modules/AI/app/Repositories/AiRoutingPolicyRepository.php (TranslatableRepository; withCount rules; dropdown; force-delete rules)',
            'Modules/AI/app/Models/AiRoutingPolicy.php + AiRoutingPolicyTranslation.php + Concerns/HasFlatTranslatedText.php',
            'Modules/AI/app/Services/AiRoutingEngine.php (status, priority, scope, gateway default, applySelectionStrategy, built-in defaults = config ai.routing.defaults, policy_source)',
        ],
        api: [
            'GET|POST       /api/admin/v1/ai-routing-policies            (?status_counts=1, search, filterColumns, trashed)',
            'GET            /api/admin/v1/ai-routing-policies/dropdown   (id, name in X-Locale, scope_type, status; not trashed; also ai-gateways.view, ai-routing-rules.view)',
            'GET|PUT|DELETE /api/admin/v1/ai-routing-policies/{policy}',
            'PATCH          /api/admin/v1/ai-routing-policies/{policy}/status',
            'POST           /api/admin/v1/ai-routing-policies/delete-multiple',
            'POST           /api/admin/v1/ai-routing-policies/{policy}/restore',
            'DELETE         /api/admin/v1/ai-routing-policies/{policy}/force',
        ],
        tables: ['ai_routing_policies (scope_type, country_code, service_key, plan_id, selection_strategy, fallback_enabled, priority, status, deleted_at)', 'ai_routing_policy_translations (name)', 'ai_routing_rules.routing_policy_id (cascade)', 'ai_gateways.default_policy_id (null on delete)'],
        permissions: [
            'ai-routing-policies.view (screen, list, dropdown) - ai-gateways.view and ai-routing-rules.view may read it too (AiAdminScreens::READERS)',
            'ai-routing-policies.create · ai-routing-policies.update (edit, restore)',
            'ai-routing-policies.delete (trash, force delete) · ai-routing-policies.change-status · ai-routing-policies.multiple-delete',
        ],
        decisions: [
            {
                ar: 'الاسم بس اللي بيتترجم؛ النطاق والأكواد والاستراتيجية قيم تقنية.',
                en: 'Only the name is translated; scope, codes and strategy are technical values.',
            },
            {
                ar: '"cost_optimized" اتشالت من الفورم والتحقق: مفيش أسعار للموديلات في المشروع (ai_usage.total_cost مش بيتكتب)، فمالهاش معنى ينفع يتطبق.',
                en: '"cost_optimized" was removed from the form and validation: the project has no model prices (ai_usage.total_cost is never written), so it has no meaning that can be applied.',
            },
            {
                ar: 'السياسة الافتراضية للبوابة بقت لازم تكون مفعّلة ومش في السلة عشان تستخدم (قبل كده المتوقفة كانت بتستخدم كبديل).',
                en: 'A gateway\'s default policy is now used only while it is on and not in the trash (before, a switched-off one was still used as the fallback).',
            },
        ],
    },
};
