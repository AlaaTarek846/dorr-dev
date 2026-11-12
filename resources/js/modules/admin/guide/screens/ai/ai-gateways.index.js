/**
 * Guide: AI gateways (admin.ai-gateways.index) - the entry the chat goes through and its default routing policy.
 * Source of truth checked: views/ai-gateways/*.vue, composables/useAiRoutingScreens.js (useAiGateways),
 * Modules/AI/app/Models/AiGateway.php (+ AiGatewayTranslation, Concerns/HasFlatTranslatedText.php),
 * Repositories/AiGatewayRepository.php, Http/Controllers/AiGatewayController.php, Http/Requests/AiGatewayRequest.php,
 * Http/Resources/AiGatewayResource.php, Services/AiRoutingEngine.php, Services/AiChatService.php (gateway_id),
 * Models/AiRequest.php, routes/admin.php.
 */
export default {
    route: 'admin.ai-gateways.index',
    updated: '2026-11-10',

    ar: {
        summary: 'هنا بوابات الذكاء الاصطناعي. الشات بياخد أول بوابة "إنتاج" مفعّلة (أصغر رقم)، بيسجّلها على كل طلب، ولو مفيش سياسة توجيه نفعت للرسالة بيستخدم السياسة الافتراضية بتاعتها.',
        audience: 'مسؤول الذكاء الاصطناعي. محتاج صلاحيات "بوابات الذكاء الاصطناعي": عرض، إضافة، تعديل، حذف، تغيير الحالة، حذف متعدد.',
        purpose: [
            'تحديد السياسة الاحتياطية لما مفيش سياسة تنطبق بالنطاق.',
            'بوابات "تجريبية" و"تطوير" بتتحفظ للتجربة، والشات مش بيستخدمها.',
        ],
        sections: [
            {
                title: 'شريط الأدوات',
                items: [
                    { name: 'البحث', desc: 'بالاسم (في أي لغة) أو البيئة.' },
                    { name: 'الكل / مفعّلة / متوقفة / المحذوفات', desc: 'فلترة سريعة بالأعداد، والسلة بتظهر لو فيها حاجة.' },
                    { name: 'حذف المحدد / إضافة بوابة', desc: 'حسب صلاحياتك.' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'الاسم', desc: 'بلغة لوحة التحكم وتحته رقم البوابة. دوس عليه يفتح التعديل.' },
                    { name: 'البيئة', desc: 'الإنتاج (أخضر) / التجريبية / التطوير.' },
                    { name: 'سياسة التوجيه الافتراضية', desc: 'وجنبها "متوقفة" أو "محذوف" لو كده - ساعتها المحرك مش بيستخدمها.' },
                    { name: 'الحالة / التاريخ / الأزرار', desc: 'زي شاشة الدول.' },
                ],
            },
            {
                title: 'فورم البوابة',
                items: [
                    { name: 'تاب لكل لغة', desc: 'الاسم إجباري في كل لغة (2 لـ 150 حرف).' },
                    { name: 'البيئة', desc: 'الإنتاج / التجريبية / التطوير. الشات بيدوّر على "الإنتاج" بس.' },
                    { name: 'سياسة التوجيه الافتراضية', desc: 'من قائمة السياسات (مش اللي في السلة؛ إلا لو هي أصلًا سياسة البوابة دي). فاضي = مفيش.' },
                    { name: 'الحالة', desc: 'مقفولة = الشات مش بيستخدم البوابة.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'تغيير السياسة الاحتياطية',
                steps: ['دوس على اسم البوابة الإنتاج.', 'اختار السياسة الافتراضية الجديدة.', 'دوس حفظ.'],
            },
        ],
        affects: [
            { route: 'admin.ai-routing-policies.index', text: 'السياسة الافتراضية بتتختار من سياسات التوجيه.' },
            { route: 'admin.ai-requests.index', text: 'سجل الطلبات: كل طلب بيتسجل عليه البوابة.' },
        ],
        warnings: [
            { level: 'warning', text: 'لو فيه أكتر من بوابة إنتاج مفعّلة، الشات بياخد أصغرهم رقمًا بس.' },
            { level: 'warning', text: 'نقل بوابة للسلة زي إيقافها: الشات مش هيستخدمها.' },
            { level: 'warning', text: 'الحذف النهائي مش هيتم بعد ما طلبات شات اتسجلت عليها (سجل الطلبات).' },
            { level: 'info', text: 'الاسم إجباري في كل لغة متفعّلة. لو ضفت لغة جديدة، افتح كل بوابة واكتب لها الاسم.' },
        ],
    },

    en: {
        summary: 'AI gateways. The chat takes the first switched-on "Production" gateway (lowest id), records it on every request, and when no routing policy matches a message it uses that gateway\'s default policy.',
        audience: 'AI administrator. Needs the "AI gateways" permissions: view, create, update, delete, change status, multiple delete.',
        purpose: [
            'Set the fallback policy for messages no policy matches by scope.',
            '"Staging" and "Development" gateways are kept for testing; the chat does not use them.',
        ],
        sections: [
            {
                title: 'Toolbar',
                items: [
                    { name: 'Search', desc: 'By name (any language) or environment.' },
                    { name: 'All / Active / Inactive / Trash', desc: 'Quick filters with counts; Trash shows when it has rows.' },
                    { name: 'Delete selected / Add gateway', desc: 'Per your permissions.' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Name', desc: 'In the panel language with the gateway id; click to edit.' },
                    { name: 'Environment', desc: 'Production (green) / Staging / Development.' },
                    { name: 'Default routing policy', desc: 'Marked "Inactive" or "Deleted" when so - then the engine skips it.' },
                    { name: 'Status / dates / buttons', desc: 'Like countries.' },
                ],
            },
            {
                title: 'Gateway form',
                items: [
                    { name: 'One tab per language', desc: 'Name required in every language (2–150 chars).' },
                    { name: 'Environment', desc: 'Production / Staging / Development. The chat only looks for Production.' },
                    { name: 'Default routing policy', desc: 'From the policies list (not trashed ones, unless it already is this gateway\'s). Empty = none.' },
                    { name: 'Status', desc: 'Off = the chat does not use the gateway.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Change the fallback policy',
                steps: ['Click the production gateway name.', 'Pick the new default policy.', 'Save.'],
            },
        ],
        affects: [
            { route: 'admin.ai-routing-policies.index', text: 'The default policy is picked from the routing policies.' },
            { route: 'admin.ai-requests.index', text: 'Requests log: every request records its gateway.' },
        ],
        warnings: [
            { level: 'warning', text: 'With several active production gateways, the chat only takes the one with the lowest id.' },
            { level: 'warning', text: 'Moving a gateway to the trash is the same as switching it off: the chat does not use it.' },
            { level: 'warning', text: 'Delete for good is refused once chat requests were logged through it.' },
            { level: 'info', text: 'The name is required in every active language. After adding a platform language, open each gateway and write its name.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-gateways/index.vue (countries-style list)',
            'resources/js/modules/admin/themes/theme-1/views/ai-gateways/ModalCreateAndUpdate.vue (useCatalogTranslationFields: name; policy picker = ai-routing-policies/dropdown)',
            'resources/js/composables/useAiRoutingScreens.js (useAiGateways), stores/aiRoutingScreens.js',
            'Modules/AI/app/Http/Controllers/AiGatewayController.php (CatalogController; guarded by admin.screen on the route group)',
            'Modules/AI/app/Repositories/AiGatewayRepository.php (TranslatableRepository; force delete blocked by requests)',
            'Modules/AI/app/Models/AiGateway.php + AiGatewayTranslation.php + Concerns/HasFlatTranslatedText.php',
            'Modules/AI/app/Services/AiRoutingEngine.php (first production gateway with status, default policy check)',
        ],
        api: [
            'GET|POST       /api/admin/v1/ai-gateways            (?status_counts=1, search, filterColumns, trashed)',
            'GET|PUT|DELETE /api/admin/v1/ai-gateways/{gateway}',
            'PATCH          /api/admin/v1/ai-gateways/{gateway}/status',
            'POST           /api/admin/v1/ai-gateways/delete-multiple',
            'POST           /api/admin/v1/ai-gateways/{gateway}/restore',
            'DELETE         /api/admin/v1/ai-gateways/{gateway}/force',
        ],
        tables: ['ai_gateways (environment, default_policy_id, status, deleted_at)', 'ai_gateway_translations (name)', 'ai_requests.gateway_id (null on delete)'],
        permissions: [
            'ai-gateways.view (screen, list; also reads ai-routing-policies/dropdown)',
            'ai-gateways.create · ai-gateways.update (edit, restore)',
            'ai-gateways.delete (trash, force delete) · ai-gateways.change-status · ai-gateways.multiple-delete',
        ],
        decisions: [
            {
                ar: 'الاسم بس اللي بيتترجم؛ البيئة والسياسة قيم تقنية. ترتيب القائمة بالرقم عشان أول بوابة إنتاج هي اللي بتشتغل.',
                en: 'Only the name is translated; environment and policy are technical. The list is ordered by id because the first production gateway is the one used.',
            },
        ],
    },
};
