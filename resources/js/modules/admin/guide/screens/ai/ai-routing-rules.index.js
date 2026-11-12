/**
 * Guide: AI routing rules (admin.ai-routing-rules.index) - which provider / model answers, per policy and intent.
 * Source of truth checked: views/ai-routing-rules/*.vue, composables/useAiRoutingScreens.js (useAiRoutingRules),
 * Modules/AI/app/Models/AiRoutingRule.php (+ Concerns/HasIsActiveAlias.php), Repositories/AiRoutingRuleRepository.php,
 * Http/Controllers/AiRoutingRuleController.php, Http/Requests/AiRoutingRuleRequest.php,
 * Http/Resources/AiRoutingRuleResource.php, Services/AiRoutingEngine.php, Models/AiProvider.php (isUsableForChat),
 * Support/AiAdminScreens.php (readers), routes/admin.php.
 */
export default {
    route: 'admin.ai-routing-rules.index',
    updated: '2026-11-10',

    ar: {
        summary: 'هنا قواعد التوجيه: كل قاعدة جوه سياسة بتقول "الرسايل من النوع ده (أو أي نوع) تروح للمزود ده والموديل ده". الشات بيجرّب القواعد بالترتيب، وأول مزود شغّال هو اللي بيرد والباقي بدائل.',
        audience: 'مسؤول الذكاء الاصطناعي. محتاج صلاحيات "قواعد التوجيه": عرض، إضافة، تعديل، حذف، تغيير الحالة، حذف متعدد.',
        purpose: [
            'جوه السياسة: قواعد الهدف بتاع الرسالة بالظبط الأول، وبعدها قواعد "أي هدف"، وكل مجموعة من الأعلى أولوية للأقل.',
            'القاعدة بتستخدم بس لو المزود مفعّل وليه مفتاح API ومسموح بخواص التشغيل (feature flags) للدولة والهدف.',
            'لو مفيش ولا قاعدة تنفع، الشات بيروح للمزود الافتراضي المفعّل.',
        ],
        sections: [
            {
                title: 'شريط الأدوات',
                items: [
                    { name: 'البحث', desc: 'بمفتاح الموديل، أو اسم/مفتاح المزود، أو مفتاح/اسم الهدف، أو اسم السياسة.' },
                    { name: 'الكل / مفعّلة / متوقفة / المحذوفات', desc: 'فلترة سريعة بالأعداد، والسلة بتظهر لو فيها حاجة.' },
                    { name: 'حذف المحدد / إضافة قاعدة', desc: 'حسب صلاحياتك.' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'السياسة', desc: 'وتحتها رقم القاعدة. دوس عليها يفتح التعديل. "متوقفة"/"محذوف" = القاعدة مش هتشتغل.' },
                    { name: 'الهدف', desc: 'الاسم وتحته المفتاح، أو "أي هدف". هدف متوقف/محذوف = القاعدة مش هتشتغل.' },
                    { name: 'المزود / مفتاح النموذج', desc: '"افتراضي المزود" = الموديل الافتراضي للمزود.' },
                    { name: 'الأولوية', desc: 'الأعلى بيتجرّب الأول جوه السياسة.' },
                    { name: 'الحالة / التاريخ / الأزرار', desc: 'زي شاشة الدول.' },
                ],
            },
            {
                title: 'فورم القاعدة',
                items: [
                    { name: 'سياسة التوجيه', desc: 'إجباري. من السياسات اللي مش في السلة.' },
                    { name: 'الهدف', desc: 'فاضي = أي هدف. من الأهداف اللي مش في السلة.' },
                    { name: 'المزود', desc: 'من مزودين الذكاء الاصطناعي. من غير مزود القاعدة مش هتبعت لحد.' },
                    { name: 'مفتاح النموذج', desc: 'لحد 150 حرف، زي ما هو عند المزود. فاضي = الافتراضي.' },
                    { name: 'الأولوية', desc: '0 أو أكتر، فاضي = 0.' },
                    { name: 'أقصى زمن استجابة / أقل جودة', desc: 'بيتحفظوا في selection_config، لكن المحرك لسه مش بيقراهم.' },
                    { name: 'الحالة', desc: 'مقفولة = الشات مش بيستخدم القاعدة.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'خلي أسئلة الدعم تروح لموديل معين',
                steps: ['دوس "إضافة قاعدة".', 'اختار السياسة والهدف "دعم فني".', 'اختار المزود واكتب مفتاح الموديل.', 'خلي الأولوية أعلى من باقي القواعد ودوس حفظ.'],
            },
            {
                title: 'إيقاف قاعدة مؤقتًا',
                steps: ['اقفل زرار الحالة - الشات هيجرّب القاعدة اللي بعدها.'],
            },
        ],
        affects: [
            { route: 'admin.ai-routing-policies.index', text: 'كل قاعدة تابعة لسياسة (عدد القواعد بيظهر هناك).' },
            { route: 'admin.ai-intents.index', text: 'الهدف بيتختار من أهداف الذكاء الاصطناعي.' },
            { text: 'الشات بالذكاء الاصطناعي في التطبيقات وشات دُر: مين بيرد على كل رسالة، ومين البديل لو فشل.' },
        ],
        warnings: [
            { level: 'warning', text: 'نقل قاعدة للسلة زي إيقافها: الشات مش هيستخدمها. والحذف النهائي متاح من السلة في أي وقت.' },
            { level: 'warning', text: 'قاعدة على هدف أو سياسة متوقفين أو في السلة مش هتشتغل حتى لو هي مفعّلة.' },
            { level: 'info', text: 'أقصى زمن الاستجابة وأقل جودة بيتحفظوا بس لحد دلوقتي.' },
        ],
    },

    en: {
        summary: 'Routing rules: each rule inside a policy says "messages of this intent (or any) go to this provider and model". The chat tries the rules in order; the first usable provider answers and the rest are fallbacks.',
        audience: 'AI administrator. Needs the "Routing rules" permissions: view, create, update, delete, change status, multiple delete.',
        purpose: [
            'Inside the policy: rules for the message\'s exact intent first, then "any intent" rules, each group from the highest priority down.',
            'A rule is used only while its provider is enabled, has an API key, and is allowed by the feature flags for the country and intent.',
            'When no rule is usable, the chat uses the active default provider.',
        ],
        sections: [
            {
                title: 'Toolbar',
                items: [
                    { name: 'Search', desc: 'By model key, provider name/key, intent key/name or policy name.' },
                    { name: 'All / Active / Inactive / Trash', desc: 'Quick filters with counts; Trash shows when it has rows.' },
                    { name: 'Delete selected / Add rule', desc: 'Per your permissions.' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Policy', desc: 'With the rule id; click to edit. "Inactive"/"Deleted" = the rule never applies.' },
                    { name: 'Intent', desc: 'Name with its key, or "Any intent". An inactive/deleted intent = the rule never applies.' },
                    { name: 'Provider / model key', desc: '"Provider default" = the provider\'s default model.' },
                    { name: 'Priority', desc: 'The highest is tried first within the policy.' },
                    { name: 'Status / dates / buttons', desc: 'Like countries.' },
                ],
            },
            {
                title: 'Rule form',
                items: [
                    { name: 'Routing policy', desc: 'Required. From the policies not in the trash.' },
                    { name: 'Intent', desc: 'Empty = any intent. From the intents not in the trash.' },
                    { name: 'Provider', desc: 'From the AI providers. Without one the rule routes nowhere.' },
                    { name: 'Model key', desc: 'Up to 150 chars, as the provider names it. Empty = its default.' },
                    { name: 'Priority', desc: '0 or more, empty = 0.' },
                    { name: 'Max latency / min quality', desc: 'Stored in selection_config; the engine does not read them yet.' },
                    { name: 'Status', desc: 'Off = the chat does not use the rule.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Send support questions to a given model',
                steps: ['Click "Add rule".', 'Pick the policy and the "Support" intent.', 'Pick the provider and type the model key.', 'Give it a higher priority than the other rules and save.'],
            },
            {
                title: 'Pause a rule',
                steps: ['Switch its status off - the chat tries the next rule.'],
            },
        ],
        affects: [
            { route: 'admin.ai-routing-policies.index', text: 'Every rule belongs to a policy (its rule count shows there).' },
            { route: 'admin.ai-intents.index', text: 'The intent is picked from the AI intents.' },
            { text: 'The AI chat in the apps and DORR chat: who answers each message, and who takes over if it fails.' },
        ],
        warnings: [
            { level: 'warning', text: 'Moving a rule to the trash is the same as switching it off. Delete for good is always allowed from the trash.' },
            { level: 'warning', text: 'A rule whose intent or policy is off or in the trash never applies, even when the rule itself is on.' },
            { level: 'info', text: 'Max latency and min quality are stored only, for now.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-routing-rules/index.vue (countries-style list, no translations)',
            'resources/js/modules/admin/themes/theme-1/views/ai-routing-rules/ModalCreateAndUpdate.vue (pickers: ai-routing-policies/dropdown, ai-intents/dropdown, ai-providers)',
            'resources/js/composables/useAiRoutingScreens.js (useAiRoutingRules: search in model_key + provider / intent / policy), stores/aiRoutingScreens.js',
            'Modules/AI/app/Http/Controllers/AiRoutingRuleController.php (CatalogController; guarded by admin.screen on the route group)',
            'Modules/AI/app/Repositories/AiRoutingRuleRepository.php (BaseRepository)',
            'Modules/AI/app/Models/AiRoutingRule.php (SoftDeletes, status; policy/intent withTrashed)',
            'Modules/AI/app/Services/AiRoutingEngine.php (rules where status, ordered by priority, intent first)',
        ],
        api: [
            'GET|POST       /api/admin/v1/ai-routing-rules            (?status_counts=1, search, filterColumns, trashed)',
            'GET|PUT|DELETE /api/admin/v1/ai-routing-rules/{rule}',
            'PATCH          /api/admin/v1/ai-routing-rules/{rule}/status',
            'POST           /api/admin/v1/ai-routing-rules/delete-multiple',
            'POST           /api/admin/v1/ai-routing-rules/{rule}/restore',
            'DELETE         /api/admin/v1/ai-routing-rules/{rule}/force',
        ],
        tables: ['ai_routing_rules (routing_policy_id, intent_id, provider_id, model_key, priority, selection_config, status, deleted_at)'],
        permissions: [
            'ai-routing-rules.view (screen, list; also reads ai-routing-policies/dropdown and ai-intents/dropdown)',
            'ai-routing-rules.create · ai-routing-rules.update (edit, restore)',
            'ai-routing-rules.delete (trash, force delete) · ai-routing-rules.change-status · ai-routing-rules.multiple-delete',
        ],
        noTranslatableText: 'A routing rule holds no text people read: a policy, an intent, a provider, a model id, a priority and JSON settings - all technical values.',
        decisions: [
            {
                ar: 'مفيش جدول ترجمة: القاعدة مفيهاش نص للقراية.',
                en: 'No translation table: a rule has no text to read.',
            },
        ],
    },
};
