/**
 * Guide: AI intents (admin.ai-intents.index) - the kinds of chat request routing rules can target.
 * Source of truth checked: views/ai-intents/*.vue, composables/useAiRoutingScreens.js (useAiIntents),
 * Modules/AI/app/Models/AiIntent.php (+ AiIntentTranslation, Concerns/HasFlatTranslatedText.php),
 * Repositories/AiIntentRepository.php, Http/Controllers/AiIntentController.php, Http/Requests/AiIntentRequest.php,
 * Http/Resources/AiIntentResource.php, Services/AiIntentClassifier.php, Services/AiRoutingEngine.php,
 * Support/AiAdminScreens.php (readers), routes/admin.php.
 */
export default {
    route: 'admin.ai-intents.index',
    updated: '2026-11-10',

    ar: {
        summary: 'هنا أنواع الطلبات (Intents) اللي الشات بيصنّف عليها كل رسالة: محادثة عامة، دعم فني، توليد محتوى، توصيات. قواعد التوجيه بتستخدمها عشان تبعت كل نوع لمزود أو موديل معين.',
        audience: 'مسؤول الذكاء الاصطناعي. محتاج صلاحيات "أهداف الذكاء الاصطناعي": عرض، إضافة، تعديل، حذف، تغيير الحالة، حذف متعدد.',
        purpose: [
            'كل رسالة شات بيتدوّر فيها على كلمات معينة؛ لو لقى كلمة من كلمات نوع، الرسالة بتتصنف عليه، ولو ملقاش بتتصنف "chat".',
            'المفتاح (key) هو اللي المصنّف بيدوّر عليه، والاسم والوصف للأدمن بس ومكتوبين بكل لغة.',
            'قواعد التوجيه اللي على نوع معين بتتجرّب قبل قواعد "أي هدف".',
        ],
        sections: [
            {
                title: 'شريط الأدوات',
                items: [
                    { name: 'البحث', desc: 'بالاسم أو الوصف (في أي لغة) أو بالمفتاح.' },
                    { name: 'الكل / مفعّلة / متوقفة', desc: 'فلترة سريعة وجنب كل واحدة العدد.' },
                    { name: 'المحذوفات', desc: 'بتظهر لو فيه أهداف في السلة: ترجّع أو تحذف نهائي.' },
                    { name: 'حذف المحدد', desc: 'تحدد كذا هدف وتنقلهم للسلة مرة واحدة (صلاحية "حذف متعدد").' },
                    { name: 'إضافة هدف', desc: 'بيفتح فورم هدف جديد (صلاحية "إضافة").' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'الاسم', desc: 'بلغة لوحة التحكم وتحته رقم الهدف. دوس عليه يفتح التعديل.' },
                    { name: 'المفتاح', desc: 'الكود التقني اللي المصنّف بيدوّر عليه.' },
                    { name: 'الوصف', desc: 'بلغة لوحة التحكم.' },
                    { name: 'الحالة', desc: 'زرار تشغيل وإيقاف (صلاحية "تغيير الحالة"). من غيرها بتظهر شارة بس.' },
                    { name: 'تاريخ الإنشاء', desc: 'وتحته آخر تعديل، وفي السلة تاريخ الحذف.' },
                    { name: '✏️ / 🗑️', desc: 'تعديل، أو نقل للسلة. في السلة: ↩️ ترجيع و🗑️ حذف نهائي.' },
                ],
            },
            {
                title: 'فورم الهدف',
                items: [
                    { name: 'تاب لكل لغة', desc: 'الاسم إجباري في كل لغة (2 لـ 150 حرف)، والوصف اختياري (لحد 1000). التاب بيحمر لو فيه غلط.' },
                    { name: 'المفتاح', desc: 'إجباري ومميز (لحد 100 حرف)، حتى مع اللي في السلة. التصنيف التلقائي بيعرف بس: chat و support و content_generation و recommendation؛ أي مفتاح تاني مش هيتصنف عليه حاجة لوحده.' },
                    { name: 'الحالة', desc: 'مقفولة = مفيش رسالة هتتصنف عليه.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'ترجمة اسم هدف',
                steps: ['دوس على اسم الهدف.', 'انقل بين تابات اللغات وعدّل الاسم والوصف.', 'دوس حفظ.'],
            },
            {
                title: 'إيقاف نوع مؤقتًا',
                steps: ['اقفل زرار الحالة في الصف.', 'الرسايل اللي كانت بتتصنف عليه هتتصنف "chat"، والقواعد اللي عليه هتبطل.'],
            },
            {
                title: 'ترجيع هدف من السلة',
                steps: ['دوس "المحذوفات".', 'دوس ↩️ ترجيع على الهدف.'],
            },
        ],
        affects: [
            { route: 'admin.ai-routing-rules.index', text: 'قواعد التوجيه: بتختار الهدف من هنا (القائمة بتظهر لصاحب صلاحية قواعد التوجيه كمان).' },
            { route: 'admin.ai-requests.index', text: 'سجل الطلبات: كل طلب بيتسجل عليه الهدف اللي اتصنف عليه.' },
            { route: 'admin.ai-reliability-metrics.index', text: 'مقاييس الاعتمادية بتتحسب لكل هدف.' },
        ],
        warnings: [
            { level: 'warning', text: 'نقل هدف للسلة زي إيقافه بالظبط: مش هيتصنف عليه، والقواعد اللي عليه مش هتشتغل. القواعد نفسها بتفضل موجودة.' },
            { level: 'warning', text: 'الحذف النهائي مش هيتم طول ما فيه قاعدة توجيه (حتى في السلة) أو طلبات شات متسجلة عليه - الشاشة هتقولك السبب.' },
            { level: 'warning', text: 'لو أوقفت "chat" ومفيش كلمة من نوع تاني في الرسالة، الرسالة مش هتتصنف خالص وهتمشي على قواعد "أي هدف" بس.' },
            { level: 'info', text: 'الاسم إجباري في كل لغة متفعّلة. لو ضفت لغة جديدة، افتح كل هدف واكتب له الاسم.' },
        ],
    },

    en: {
        summary: 'The kinds of request (intents) the chat tags every message with: general chat, support, content generation, recommendations. Routing rules use them to send each kind to a given provider or model.',
        audience: 'AI administrator. Needs the "AI intents" permissions: view, create, update, delete, change status, multiple delete.',
        purpose: [
            'Every chat message is checked for keywords; the first intent whose keyword is found tags it, otherwise it is tagged "chat".',
            'The key is what the classifier looks up; the name and description are for admins only and written per language.',
            'Routing rules aimed at a specific intent are tried before "any intent" rules.',
        ],
        sections: [
            {
                title: 'Toolbar',
                items: [
                    { name: 'Search', desc: 'By name or description (any language) or key.' },
                    { name: 'All / Active / Inactive', desc: 'Quick filters with counts.' },
                    { name: 'Trash', desc: 'Shown when intents are in the trash: restore or delete for good.' },
                    { name: 'Delete selected', desc: 'Move several checked intents to the trash at once ("multiple delete").' },
                    { name: 'Add intent', desc: 'Opens a new intent form ("create").' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Name', desc: 'In the panel language with the intent id; click to edit.' },
                    { name: 'Key', desc: 'The technical code the classifier looks up.' },
                    { name: 'Description', desc: 'In the panel language.' },
                    { name: 'Status', desc: 'On/off toggle ("change status"); a badge without it.' },
                    { name: 'Created at', desc: 'With last update, and the deletion date in the trash.' },
                    { name: '✏️ / 🗑️', desc: 'Edit, or move to the trash. In the trash: ↩️ restore and 🗑️ delete for good.' },
                ],
            },
            {
                title: 'Intent form',
                items: [
                    { name: 'One tab per language', desc: 'Name required in every language (2–150 chars), description optional (up to 1000). A tab turns red on errors.' },
                    { name: 'Key', desc: 'Required and unique (up to 100), the trash included. Automatic classification only knows chat, support, content_generation and recommendation; any other key is never picked by itself.' },
                    { name: 'Status', desc: 'Off = no message is tagged with it.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Translate an intent name',
                steps: ['Click the intent name.', 'Switch between the language tabs and edit the name and description.', 'Save.'],
            },
            {
                title: 'Pause an intent',
                steps: ['Switch its status off in the row.', 'Messages it used to tag fall back to "chat", and the rules aimed at it stop applying.'],
            },
            {
                title: 'Restore an intent from the trash',
                steps: ['Click "Trash".', 'Click ↩️ restore on the intent.'],
            },
        ],
        affects: [
            { route: 'admin.ai-routing-rules.index', text: 'Routing rules pick their intent from here (holders of the routing rules screen may read this list too).' },
            { route: 'admin.ai-requests.index', text: 'Requests log: every request records the intent it was tagged with.' },
            { route: 'admin.ai-reliability-metrics.index', text: 'Reliability metrics are computed per intent.' },
        ],
        warnings: [
            { level: 'warning', text: 'Moving an intent to the trash is the same as switching it off: it is never classified and the rules aimed at it stop applying. The rules themselves stay.' },
            { level: 'warning', text: 'Delete for good is refused while a routing rule (even in the trash) or logged chat requests point at it - the screen tells you why.' },
            { level: 'warning', text: 'If "chat" is off and no other keyword matches, the message gets no intent and only "any intent" rules apply.' },
            { level: 'info', text: 'The name is required in every active language. After adding a platform language, open each intent and write its name.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-intents/index.vue (countries-style list)',
            'resources/js/modules/admin/themes/theme-1/views/ai-intents/ModalCreateAndUpdate.vue (useCatalogTranslationFields: name, description)',
            'resources/js/composables/useAiRoutingScreens.js (useAiIntents), stores/aiRoutingScreens.js',
            'Modules/AI/app/Http/Controllers/AiIntentController.php (CatalogController; guarded by admin.screen on the route group)',
            'Modules/AI/app/Repositories/AiIntentRepository.php (TranslatableRepository; dropdown; force-delete rules)',
            'Modules/AI/app/Models/AiIntent.php + AiIntentTranslation.php + Concerns/HasFlatTranslatedText.php',
            'Modules/AI/app/Services/AiIntentClassifier.php (where key + status), Services/AiRoutingEngine.php',
        ],
        api: [
            'GET|POST       /api/admin/v1/ai-intents            (?status_counts=1, search, filterColumns, trashed)',
            'GET            /api/admin/v1/ai-intents/dropdown   (id, key, name in X-Locale, status; not trashed; also ai-routing-rules.view)',
            'GET|PUT|DELETE /api/admin/v1/ai-intents/{intent}',
            'PATCH          /api/admin/v1/ai-intents/{intent}/status',
            'POST           /api/admin/v1/ai-intents/delete-multiple',
            'POST           /api/admin/v1/ai-intents/{intent}/restore',
            'DELETE         /api/admin/v1/ai-intents/{intent}/force',
        ],
        tables: ['ai_intents (key, status, deleted_at)', 'ai_intent_translations (name, description)', 'ai_routing_rules.intent_id (null on delete)', 'ai_requests.intent_id, ai_reliability_metrics.intent_id'],
        permissions: [
            'ai-intents.view (screen, list, dropdown) - ai-routing-rules.view may read it too (AiAdminScreens::READERS)',
            'ai-intents.create · ai-intents.update (edit, restore)',
            'ai-intents.delete (trash, force delete) · ai-intents.change-status · ai-intents.multiple-delete',
        ],
        decisions: [
            {
                ar: 'الاسم والوصف بس اللي بيتترجموا (اللي الأدمن بيقراه)؛ المفتاح كود تقني ثابت.',
                en: 'Only the name and description are translated (what admins read); the key is a fixed technical code.',
            },
            {
                ar: 'الهدف اللي في السلة زي المتوقف: المصنّف مش بيرجّعه، والقواعد بتفضل شايفاه (withTrashed) عشان الاسم يظهر.',
                en: 'A trashed intent behaves like a switched-off one: the classifier never returns it, and rules still see it (withTrashed) to show its name.',
            },
        ],
    },
};
