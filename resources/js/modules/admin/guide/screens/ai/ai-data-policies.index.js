/**
 * Guide: AI data policies (admin.ai-data-policies.index) - retention and log minimization of AI data.
 * Source of truth checked: views/ai-data-policies/*.vue, composables/useAiPrivacyScreens.js (useAiDataPolicies),
 * stores/aiPrivacyScreens.js, Modules/AI/app/Models/AiDataPolicy.php (activePersonal, + AiDataPolicyTranslation),
 * Repositories/AiDataPolicyRepository.php, Http/Controllers/AiDataPolicyController.php,
 * Http/Requests/AiDataPolicyRequest.php, Http/Resources/AiDataPolicyResource.php,
 * Console/Commands/EnforceAiDataRetention.php, Services/AiChatService.php + AiTaskRunner.php (minimizedForLog),
 * database/seeders/AIDatabaseSeeder.php, routes/admin.php.
 */
export default {
    route: 'admin.ai-data-policies.index',
    updated: '2026-11-11',

    ar: {
        summary: 'هنا سياسات البيانات: كل سياسة لنوع بيانات (عامة، داخلية، سرية، شخصية، سرية للغاية) بتقول تتخزن قد إيه، وهل بتتنضف من السجلات، وهل مسموح تروح لمزود خارجي.',
        audience: 'مسؤول الذكاء الاصطناعي أو الخصوصية. محتاج صلاحيات "سياسات البيانات": عرض، إضافة، تعديل، حذف، تغيير الحالة، حذف متعدد.',
        purpose: [
            'المحرك بيقرا سياسة واحدة بس: أحدث سياسة مفعّلة تصنيفها "شخصية" (مش في السلة).',
            'مدة التخزين بتاعتها: أمر ai:enforce-retention بيمسح سجلات الذكاء الاصطناعي (الطلبات وردودها وسجلات المزودين...) الأقدم من المدة دي. فاضية = مفيش مسح.',
            'تقليل البيانات بتاعها: مفتوح = الإيميلات والتليفونات والأسرار بتتشال من نسخة الرسالة اللي في السجلات. مقفول = السجل بيحفظ النص زي ما هو. لو مفيش سياسة، التنضيف شغال دايمًا.',
            'الموافقة الصريحة مفتوحة: مفيش مكان بيسجل موافقة الشخص على مشاركة بيانات حسابه، فبيانات حسابه (الاسم والإيميل والتليفون والنوع) مش بتتبعت للذكاء الاصطناعي - البلد بس. اللي هو بيكتبه بإيده بيتبعت عادي.',
            'مزود خارجي مقفول: كل مزودين الذكاء الاصطناعي خارجيين، فبيانات الحساب مش بتتبعت، والإيميلات والتليفونات وأرقام الهوية بتتخفى من رسايل الشخص والمساعد قبل أي طلب يخرج لأي مزود (فوق قاعدة بيانات المزود نفسها).',
            'لو السياسة مش متقرية لأي سبب، الشات بيشتغل بالأشد: من غير بيانات الحساب ومع إخفاء البيانات. باقي التصنيفات بتتسجل بس.',
        ],
        sections: [
            {
                title: 'شريط الأدوات',
                items: [
                    { name: 'البحث', desc: 'بالاسم أو الوصف بأي لغة، أو بالتصنيف (بالإنجليزي: personal...).' },
                    { name: 'الكل / مفعّلة / متوقفة / المحذوفات', desc: 'فلترة سريعة بالأعداد.' },
                    { name: 'حذف المحدد / إضافة سياسة', desc: 'حسب صلاحياتك.' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'الاسم', desc: 'بلغة اللوحة، وتحته الوصف والرقم.' },
                    { name: 'التصنيف / مدة التخزين', desc: '"من غير حد" = المدة فاضية.' },
                    { name: 'الموافقة / التقليل / مزود خارجي', desc: 'علامة صح = مفعّل.' },
                    { name: 'الحالة / التاريخ / الأزرار', desc: 'زي شاشة الدول.' },
                ],
            },
            {
                title: 'فورم السياسة',
                items: [
                    { name: 'تابات اللغات', desc: 'الاسم (إجباري، 2 لـ 150 حرف) والوصف (اختياري، لحد 1000) لكل لغة متخزنة.' },
                    { name: 'تصنيف البيانات', desc: 'واحد من الخمسة. الافتراضي "داخلية".' },
                    { name: 'مدة التخزين (أيام)', desc: 'رقم صحيح 0 أو أكتر، أو فاضي = من غير حد.' },
                    { name: 'المفاتيح الثلاثة / الحالة', desc: 'زي ما اتشرح فوق. مقفولة = المحرك بيتجاهل السياسة.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'خلّي سجلات الذكاء الاصطناعي تتمسح بعد 90 يوم',
                steps: ['افتح سياسة "شخصية" المفعّلة (أو أضف واحدة).', 'اكتب 90 في مدة التخزين ودوس حفظ.', 'الأمر ai:enforce-retention هيمسح الأقدم في تشغيله الجاي.'],
            },
            {
                title: 'وقف المسح التلقائي',
                steps: ['اقفل حالة سياسة "شخصية" (أو امسح مدة التخزين).'],
            },
        ],
        affects: [
            { route: 'admin.ai-requests.index', text: 'مدة التخزين بتمسح الطلبات القديمة (والردود والاستهلاك والتحققات معاها).' },
            { route: 'admin.ai-provider-logs.index', text: 'سجلات المزودين القديمة بتتمسح بنفس المدة.' },
            { text: 'نص الرسايل في سجلات الطلبات والردود (متنضف أو لأ). الرسايل نفسها في المحادثات مش بتتأثر.' },
        ],
        warnings: [
            { level: 'danger', text: 'تقليل مدة التخزين بيمسح سجلات قديمة للأبد في التشغيل الجاي للأمر.' },
            { level: 'warning', text: 'نقل السياسة للسلة زي إيقافها: المسح بيقف والتنضيف بيرجع للافتراضي (شغال).' },
            { level: 'info', text: 'لو فيه أكتر من سياسة "شخصية" مفعّلة، الأحدث (أكبر رقم) هي اللي بتتطبق.' },
            { level: 'warning', text: 'قفل "مزود خارجي" أو فتح "الموافقة" بيخلي المساعد ميعرفش اسم الشخص ولا بياناته، فممكن يسأله عنها.' },
        ],
    },

    en: {
        summary: 'Data policies: each policy for a class of data (public, internal, confidential, personal, secret) says how long it is kept, whether it is cleaned out of the logs, and whether it may go to an external provider.',
        audience: 'AI or privacy administrator. Needs the "Data policies" permissions: view, create, update, delete, change status, multiple delete.',
        purpose: [
            'The engine reads only one policy: the newest active "Personal" one (not in the trash).',
            'Its retention: the ai:enforce-retention command deletes AI logs (requests and their responses, provider logs…) older than that many days. Empty = nothing is deleted.',
            'Its minimization: on = emails, phones and secrets are removed from the copy of the message kept in the logs. Off = the log keeps the text as it is. With no policy, cleaning is always on.',
            'Consent required on: nothing records a person\'s consent to share their account data, so their account details (name, e-mail, phone, gender) are not sent to the AI - only the country. What they type themselves is sent as usual.',
            'External provider off: every AI provider is external, so the account details are not sent, and e-mails, phone numbers and id numbers are masked in the person\'s and the assistant\'s turns before any call leaves to any provider (on top of the provider\'s own data rule).',
            'If the policy cannot be read for any reason, chat runs with the strict answer: no account details, masking on. The other classes are recorded only.',
        ],
        sections: [
            {
                title: 'Toolbar',
                items: [
                    { name: 'Search', desc: 'By name or description in any language, or by classification (personal…).' },
                    { name: 'All / Active / Inactive / Trash', desc: 'Quick filters with counts.' },
                    { name: 'Delete selected / Add policy', desc: 'Per your permissions.' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Name', desc: 'In the panel language, with the description and id.' },
                    { name: 'Classification / retention', desc: '"No limit" = retention is empty.' },
                    { name: 'Consent / minimization / external provider', desc: 'A tick = on.' },
                    { name: 'Status / dates / buttons', desc: 'Like countries.' },
                ],
            },
            {
                title: 'Policy form',
                items: [
                    { name: 'Language tabs', desc: 'Name (required, 2-150 chars) and description (optional, up to 1000) for every stored language.' },
                    { name: 'Data classification', desc: 'One of the five. Default "Internal".' },
                    { name: 'Retention (days)', desc: 'A whole number 0 or more, or empty = no limit.' },
                    { name: 'The three switches / status', desc: 'As above. Off = the engine ignores the policy.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Delete AI logs after 90 days',
                steps: ['Open the active "Personal" policy (or add one).', 'Type 90 in retention and save.', 'ai:enforce-retention deletes older rows on its next run.'],
            },
            {
                title: 'Stop the automatic deletion',
                steps: ['Switch the "Personal" policy off (or clear its retention).'],
            },
        ],
        affects: [
            { route: 'admin.ai-requests.index', text: 'Retention deletes old requests (with their responses, usage and verifications).' },
            { route: 'admin.ai-provider-logs.index', text: 'Old provider logs are deleted with the same window.' },
            { text: 'The message text in the request / response logs (cleaned or not). The messages in the conversations themselves are not touched.' },
        ],
        warnings: [
            { level: 'danger', text: 'Lowering retention deletes old logs for good on the command\'s next run.' },
            { level: 'warning', text: 'Moving the policy to the trash is the same as switching it off: deletion stops and cleaning goes back to its default (on).' },
            { level: 'info', text: 'With more than one active "Personal" policy, the newest (highest id) applies.' },
            { level: 'warning', text: 'Switching "external provider" off or "consent" on means the assistant does not know the person\'s name or details, so it may ask for them.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-data-policies/index.vue (countries-style list)',
            'resources/js/modules/admin/themes/theme-1/views/ai-data-policies/ModalCreateAndUpdate.vue (CatalogTranslationTabs: name, description)',
            'resources/js/composables/useAiPrivacyScreens.js (useAiDataPolicies), stores/aiPrivacyScreens.js',
            'Modules/AI/app/Http/Controllers/AiDataPolicyController.php (CatalogController; guarded by admin.screen on the route group)',
            'Modules/AI/app/Repositories/AiDataPolicyRepository.php (TranslatableRepository)',
            'Modules/AI/app/Models/AiDataPolicy.php (activePersonal(): personal + status, trash excluded)',
            'Modules/AI/app/Console/Commands/EnforceAiDataRetention.php · Services/AiChatService.php + AiTaskRunner.php (minimizedForLog)',
            'Modules/AI/app/Services/AiPersonalDataPolicy.php (consent / external provider) · AiGateway.php (applyDataRules) · AiChatService.php (ownerProfileContext)',
        ],
        api: [
            'GET|POST       /api/admin/v1/ai-data-policies            (?status_counts=1, search, trashed)',
            'GET|PUT|DELETE /api/admin/v1/ai-data-policies/{policy}',
            'PATCH          /api/admin/v1/ai-data-policies/{policy}/status',
            'POST           /api/admin/v1/ai-data-policies/delete-multiple',
            'POST           /api/admin/v1/ai-data-policies/{policy}/restore',
            'DELETE         /api/admin/v1/ai-data-policies/{policy}/force',
        ],
        tables: [
            'ai_data_policies (data_classification, retention_days, consent_required, minimization_enabled, external_provider_allowed, status, deleted_at)',
            'ai_data_policy_translations (ai_data_policy_id, locale, name, description)',
        ],
        permissions: [
            'ai-data-policies.view (screen, list, show)',
            'ai-data-policies.create · ai-data-policies.update (edit, restore)',
            'ai-data-policies.delete (trash, force delete) · ai-data-policies.change-status · ai-data-policies.multiple-delete',
        ],
        decisions: [
            {
                ar: 'الاسم والوصف بيتترجموا؛ التصنيف والمدة والمفاتيح تقنية.',
                en: 'Name and description are translated; classification, retention and switches are technical.',
            },
            {
                ar: 'السياسة اللي في السلة بتتعامل زي المتوقفة بالظبط.',
                en: 'A trashed policy behaves exactly like a switched-off one.',
            },
            {
                ar: 'مفيش شاشة ولا API لموافقة الشخص في الشات، فـ"الموافقة مطلوبة" معناها إن النظام ميبعتش من نفسه بيانات الحساب؛ لو اتعملت موافقة حقيقية بعدين، تتقري في AiPersonalDataPolicy::allowsOwnerProfile().',
                en: 'There is no consent screen or API in the chat, so "consent required" means the system does not send account details by itself; if real consent is added later, read it in AiPersonalDataPolicy::allowsOwnerProfile().',
            },
        ],
    },
};
