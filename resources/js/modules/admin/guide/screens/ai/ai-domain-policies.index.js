/**
 * Guide: AI domain policies (admin.ai-domain-policies.index) - how DORR AI answers legal, health, education, code,
 * marketing and general questions.
 * Source of truth checked: views/ai-domain-policies/*.vue, composables/useAiControlScreens.js (useAiDomainPolicies),
 * Modules/AI/app/Models/AiDomainPolicy.php (+ AiDomainPolicyTranslation, Concerns/HasFlatTranslatedText.php),
 * Repositories/AiDomainPolicyRepository.php, Http/Controllers/AiDomainPolicyController.php,
 * Http/Requests/AiDomainPolicyRequest.php, Http/Resources/AiDomainPolicyResource.php, Services/AiDomainClassifier.php,
 * Services/AiDomainPipelineService.php, Services/AiChatService.php (triage, guidance, sandbox),
 * Services/AiBenchmarkRunner.php, routes/admin.php, database/migrations/2026_11_10_150100_make_ai_domain_policies_translatable.php.
 */
export default {
    route: 'admin.ai-domain-policies.index',
    updated: '2026-11-10',

    ar: {
        summary: 'هنا سياسات المسارات: لكل مسار (قانوني، صحي، تعليمي، برمجي، تسويق، معلومات عامة) إزاي الشات يرد - تعليمات زيادة للموديل، فرز طوارئ، سؤال عن الدولة، تشغيل الكود في sandbox، ونص تنويه.',
        audience: 'مسؤول الذكاء الاصطناعي. محتاج صلاحيات "سياسات المسارات": عرض، إضافة، تعديل، حذف، تغيير الحالة، حذف متعدد.',
        purpose: [
            'الشات بيدور في الرسالة على كلمات كل مسار (ثابتة في الكود)، وأول سياسة مفعّلة لمسار مطابق هي اللي بتتطبق. لو مفيش، بيستخدم "معلومات عامة" لو مفعّلة.',
            'السياسة المقفولة أو اللي في السلة مش بتتطبق خالص.',
            'سياسة واحدة بس لكل مسار.',
        ],
        sections: [
            {
                title: 'شريط الأدوات',
                items: [
                    { name: 'البحث', desc: 'بالاسم أو الوصف (أي لغة) أو مفتاح المسار.' },
                    { name: 'الكل / مفعّلة / متوقفة / المحذوفات', desc: 'فلترة بالأعداد، والسلة بتظهر لو فيها حاجة.' },
                    { name: 'حذف المحدد / إضافة سياسة', desc: 'حسب صلاحياتك.' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'الاسم', desc: 'بلغتك، وتحته المسار ومفتاحه ورقم السياسة. دوس عليه يفتح التعديل.' },
                    { name: 'مستوى الخطورة', desc: 'منخفض / متوسط / عالي / حرج.' },
                    { name: 'الضوابط', desc: 'الضوابط المفعّلة: تحديد الاختصاص، الفرز، الـ sandbox، القايمة المعتمدة.' },
                    { name: 'الحالة / التاريخ / الأزرار', desc: 'زي شاشة الدول.' },
                ],
            },
            {
                title: 'فورم السياسة',
                items: [
                    { name: 'الاسم (تاب لكل لغة)', desc: 'إجباري في كل لغة، من 2 لـ 150 حرف. للأدمن بس.' },
                    { name: 'الوصف (تاب لكل لغة)', desc: 'اختياري، لحد 1000 حرف.' },
                    { name: 'المسار', desc: 'إجباري، واحد من الستة، ومش بيتغير بعد الحفظ. مينفعش مسار ليه سياسة (حتى في السلة).' },
                    { name: 'مستوى الخطورة', desc: 'للتوضيح؛ الشات مش بيقراه.' },
                    { name: 'يتطلب تحديد الاختصاص', desc: 'لو الرسالة مفيهاش دولة، الموديل بيتقاله يسأل الأول.' },
                    { name: 'يتطلب الفرز', desc: 'كلمات الطوارئ بتاخد رد أمان جاهز قبل أي ذكاء اصطناعي، وباقي الأسئلة تعليمة "من غير تشخيص".' },
                    { name: 'يتطلب Sandbox', desc: 'الكود في الرد بيتشغل في الـ sandbox ولو فشل بيرجع للموديل يصلحه.' },
                    { name: 'القايمة المعتمدة', desc: 'بتتحفظ بس - الشات مش بيقراها لسه.' },
                    { name: 'إضافة لتعليمات النظام', desc: 'لحد 2000 حرف، بتتبعت للموديل زي ما هي (تقنية، مش بتتترجم).' },
                    { name: 'نص التنويه', desc: 'لحد 1000 حرف. الموديل بيتقاله يختم بيه ويترجمه للغة الرد، فبيتكتب مرة واحدة.' },
                    { name: 'الحالة', desc: 'مقفولة = الشات بيتجاهل المسار ده.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'وقّف تعليمات المسار التسويقي مؤقتًا',
                steps: ['اقفل زرار الحالة في صف "مسار التسويق" - الرسايل دي هتتعامل كـ "معلومات عامة" لو مفعّلة.'],
            },
            {
                title: 'غيّر نص التنويه القانوني',
                steps: ['افتح "المسار القانوني".', 'عدّل "نص التنويه" (مرة واحدة، الموديل بيترجمه).', 'دوس حفظ.'],
            },
        ],
        affects: [
            { text: 'شات دُر بالذكاء الاصطناعي في التطبيقات وشات دُر: التعليمات الزيادة، رد الطوارئ الصحي، السؤال عن الدولة، وتشغيل الكود.' },
            { route: 'admin.ai-benchmark-runs.index', text: 'اختبارات الجودة بتستخدم "إضافة لتعليمات النظام" بتاعة المسار المفعّل.' },
        ],
        warnings: [
            { level: 'warning', text: 'نقل السياسة للسلة زي إيقافها: الشات بيبطل يطبقها فورًا. والحذف النهائي متاح من السلة في أي وقت.' },
            { level: 'danger', text: 'إيقاف المسار الصحي بيوقف رد الطوارئ الجاهز - الرسايل الطارئة هتروح للموديل عادي.' },
            { level: 'info', text: 'كلمات التعرف على كل مسار في الكود (AiDomainClassifier)، مش في الشاشة دي.' },
        ],
    },

    en: {
        summary: 'Domain policies: for each domain (legal, health, education, code, marketing, general info) how the chat answers - extra model instructions, emergency triage, asking the country, running code in the sandbox, and a disclaimer.',
        audience: 'AI administrator. Needs the "Domain policies" permissions: view, create, update, delete, change status, multiple delete.',
        purpose: [
            'The chat looks for each domain\'s keywords in the message (fixed in code); the first active policy of a matching domain applies. With no match it uses "General info" when active.',
            'A switched-off or trashed policy is never applied.',
            'One policy per domain.',
        ],
        sections: [
            {
                title: 'Toolbar',
                items: [
                    { name: 'Search', desc: 'By name, description (any language) or domain key.' },
                    { name: 'All / Active / Inactive / Trash', desc: 'Filters with counts; Trash shows when it has rows.' },
                    { name: 'Delete selected / Add policy', desc: 'Per your permissions.' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Name', desc: 'In your language, with the domain, its key and the policy id. Click to edit.' },
                    { name: 'Risk level', desc: 'Low / medium / high / critical.' },
                    { name: 'Controls', desc: 'The switches that are on: jurisdiction, triage, sandbox, allowlist.' },
                    { name: 'Status / dates / buttons', desc: 'Like countries.' },
                ],
            },
            {
                title: 'Policy form',
                items: [
                    { name: 'Name (one tab per language)', desc: 'Required in every language, 2-150 chars. Admins only.' },
                    { name: 'Description (one tab per language)', desc: 'Optional, up to 1000 chars.' },
                    { name: 'Domain', desc: 'Required, one of the six, fixed after saving. A domain that has a policy (even in the trash) can\'t get another.' },
                    { name: 'Risk level', desc: 'For reference; the chat does not read it.' },
                    { name: 'Requires jurisdiction', desc: 'When the message names no country, the model is told to ask first.' },
                    { name: 'Requires triage', desc: 'Emergency words get a ready safe reply before any AI call; other questions get a no-diagnosis instruction.' },
                    { name: 'Sandbox required', desc: 'The code in the reply runs in the sandbox and goes back to the model for a fix when it fails.' },
                    { name: 'Allowlist enforced', desc: 'Stored only - the chat does not read it yet.' },
                    { name: 'System prompt addition', desc: 'Up to 2000 chars, sent to the model as is (technical, not translated).' },
                    { name: 'Disclaimer text', desc: 'Up to 1000 chars. The model is told to end with it, translated into the reply\'s language - written once.' },
                    { name: 'Status', desc: 'Off = the chat ignores this domain.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Pause the marketing instructions',
                steps: ['Switch off the "Marketing" row - those messages are treated as "General info" when it is active.'],
            },
            {
                title: 'Change the legal disclaimer',
                steps: ['Open "Legal".', 'Edit "Disclaimer text" (once - the model translates it).', 'Save.'],
            },
        ],
        affects: [
            { text: 'The DORR AI chat in the apps and DORR chat: extra instructions, the health emergency reply, asking the country, running code.' },
            { route: 'admin.ai-benchmark-runs.index', text: 'Benchmark runs use the active domain\'s system prompt addition.' },
        ],
        warnings: [
            { level: 'warning', text: 'Moving a policy to the trash is the same as switching it off: the chat stops applying it at once. Delete for good is always allowed from the trash.' },
            { level: 'danger', text: 'Switching off the health domain also stops the ready emergency reply - urgent messages go to the model as usual.' },
            { level: 'info', text: 'Each domain\'s keywords live in code (AiDomainClassifier), not on this screen.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-domain-policies/index.vue (countries-style list)',
            'resources/js/modules/admin/themes/theme-1/views/ai-domain-policies/ModalCreateAndUpdate.vue (name / description tabs)',
            'resources/js/composables/useAiControlScreens.js (useAiDomainPolicies), stores/aiControlScreens.js',
            'Modules/AI/app/Http/Controllers/AiDomainPolicyController.php (CatalogController; guarded by admin.screen on the route group)',
            'Modules/AI/app/Repositories/AiDomainPolicyRepository.php (TranslatableRepository)',
            'Modules/AI/app/Models/AiDomainPolicy.php (SoftDeletes, status, is_active alias)',
            'Modules/AI/app/Services/AiDomainClassifier.php (where status, trashed excluded) → AiDomainPipelineService',
        ],
        api: [
            'GET|POST       /api/admin/v1/ai-domain-policies            (?status_counts=1, search, filterColumns, trashed)',
            'GET|PUT|DELETE /api/admin/v1/ai-domain-policies/{policy}',
            'PATCH          /api/admin/v1/ai-domain-policies/{policy}/status',
            'POST           /api/admin/v1/ai-domain-policies/delete-multiple',
            'POST           /api/admin/v1/ai-domain-policies/{policy}/restore',
            'DELETE         /api/admin/v1/ai-domain-policies/{policy}/force',
        ],
        tables: [
            'ai_domain_policies (domain_key unique, risk_level, the four switches, system_prompt_addition, disclaimer_text, status, deleted_at)',
            'ai_domain_policy_translations (ai_domain_policy_id, locale, name, description)',
        ],
        permissions: [
            'ai-domain-policies.view (screen, list)',
            'ai-domain-policies.create · ai-domain-policies.update (edit, restore)',
            'ai-domain-policies.delete (trash, force delete) · ai-domain-policies.change-status · ai-domain-policies.multiple-delete',
        ],
        decisions: [
            {
                ar: 'الاسم والوصف بس اللي بيتترجموا. التعليمات ونص التنويه للموديل (هو بيترجم التنويه بنفسه)، فمش بيتترجموا.',
                en: 'Only the name and description are translated. The instructions and the disclaimer go to the model (it translates the disclaimer itself), so they are not.',
            },
        ],
    },
};
