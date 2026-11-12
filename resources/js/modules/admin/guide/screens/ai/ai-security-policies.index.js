/**
 * Guide: AI security policies (admin.ai-security-policies.index) - the security requirements of the AI service.
 * Source of truth checked: views/ai-security-policies/*.vue, composables/useAiPrivacyScreens.js (useAiSecurityPolicies),
 * stores/aiPrivacyScreens.js, Modules/AI/app/Models/AiSecurityPolicy.php (+ AiSecurityPolicyTranslation,
 * Concerns/HasFlatTranslatedText.php), Repositories/AiSecurityPolicyRepository.php,
 * Http/Controllers/AiSecurityPolicyController.php, Http/Requests/AiSecurityPolicyRequest.php,
 * Http/Resources/AiSecurityPolicyResource.php, database/seeders/AIDatabaseSeeder.php, routes/admin.php
 * (grep: nothing in the engine reads ai_security_policies).
 */
export default {
    route: 'admin.ai-security-policies.index',
    updated: '2026-11-11',

    ar: {
        summary: 'هنا سياسات الأمان بتاعة الذكاء الاصطناعي: كل سياسة بتقول المطلوب من كل طلب - تسجيل دخول، تحقق من الصلاحيات، عزل بيانات كل حساب، وحد لعدد الطلبات.',
        audience: 'مسؤول الذكاء الاصطناعي أو الأمان. محتاج صلاحيات "سياسات الأمان": عرض، إضافة، تعديل، حذف، تغيير الحالة، حذف متعدد.',
        purpose: [
            'تسجيل سياسة الأمان المعتمدة للخدمة باسم ووصف بكل اللغات.',
            'تسجيل الدخول والصلاحيات وعزل بيانات كل حساب وحد الطلبات شغالين دايمًا من الكود على كل طلب (حراس الدخول، صلاحيات الشاشات، فلترة كل حساب على بياناته، throttle) ومينفعش يتقفلوا. عشان كده المفاتيح الأربعة اتشالت من الفورم والجدول: قفل أي واحد فيهم مالوش معنى ينفع يتطبق. قيمهم القديمة متخزنة زي ما هي.',
        ],
        sections: [
            {
                title: 'شريط الأدوات',
                items: [
                    { name: 'البحث', desc: 'بالاسم أو الوصف بأي لغة.' },
                    { name: 'الكل / مفعّلة / متوقفة / المحذوفات', desc: 'فلترة سريعة بالأعداد، والمحذوفات بتظهر لو السلة فيها حاجة.' },
                    { name: 'حذف المحدد / إضافة سياسة', desc: 'حسب صلاحياتك.' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'الاسم', desc: 'بلغة اللوحة، وتحته الوصف ورقم السياسة. دوس عليه يفتح التعديل.' },
                    { name: 'الحالة / التاريخ / الأزرار', desc: 'زي شاشة الدول: زرار الحالة، تعديل، نقل للسلة، ومن السلة استرجاع أو حذف نهائي.' },
                ],
            },
            {
                title: 'فورم السياسة',
                items: [
                    { name: 'تابات اللغات', desc: 'الاسم (إجباري، من 2 لـ 150 حرف) والوصف (اختياري، لحد 1000 حرف) لكل لغة متخزنة. لازم تكتب الاسم في كل اللغات.' },
                    { name: 'ملاحظة المتطلبات', desc: 'رسالة بتقول إن المتطلبات الأربعة شغالة دايمًا من الكود ومش بتتظبط هنا.' },
                    { name: 'الحالة', desc: 'مقفولة = السياسة متعلّمة إنها متوقفة.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'تسجيل سياسة جديدة',
                steps: ['دوس "إضافة سياسة".', 'اكتب الاسم والوصف في كل تاب لغة.', 'دوس حفظ.'],
            },
            {
                title: 'ترجّع سياسة اتحذفت',
                steps: ['دوس "المحذوفات".', 'دوس زرار الاسترجاع جنب السياسة.'],
            },
        ],
        affects: [
            { text: 'مفيش شاشة ولا تطبيق بيقرا السياسات دي لحد دلوقتي - هي سجل للسياسة المعتمدة.' },
        ],
        warnings: [
            { level: 'info', text: 'قفل أي مفتاح هنا مش بيوقف تسجيل الدخول ولا الصلاحيات ولا حدود الطلبات في الحقيقة - دول شغالين من الكود دايمًا.' },
            { level: 'warning', text: 'الحذف النهائي من السلة بيمسح السياسة وكل ترجماتها ومينفعش يرجع.' },
        ],
    },

    en: {
        summary: 'AI security policies: each says what every AI request must meet - sign-in, a permission check, tenant isolation and a rate limit.',
        audience: 'AI or security administrator. Needs the "Security policies" permissions: view, create, update, delete, change status, multiple delete.',
        purpose: [
            'Record the service\'s approved security policy with a name and description in every language.',
            'Sign-in, permissions, tenant isolation and rate limits always run in code for every request (auth guards, screen permissions, per-account scoping, throttle) and cannot be switched off. So the four switches were removed from the form and the table: switching one off has no meaning that could ever be enforced. Their old values stay stored as they are.',
        ],
        sections: [
            {
                title: 'Toolbar',
                items: [
                    { name: 'Search', desc: 'By name or description, in any language.' },
                    { name: 'All / Active / Inactive / Trash', desc: 'Quick filters with counts; Trash shows when it has rows.' },
                    { name: 'Delete selected / Add policy', desc: 'Per your permissions.' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Name', desc: 'In the panel language, with the description and id. Click to edit.' },
                    { name: 'Status / dates / buttons', desc: 'Like countries: status toggle, edit, move to trash; from the trash restore or delete for good.' },
                ],
            },
            {
                title: 'Policy form',
                items: [
                    { name: 'Language tabs', desc: 'Name (required, 2-150 chars) and description (optional, up to 1000) for every stored language. The name is needed in every language.' },
                    { name: 'Requirements note', desc: 'Says the four requirements always run in code and are not set here.' },
                    { name: 'Status', desc: 'Off = the policy is marked inactive.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Record a new policy',
                steps: ['Click "Add policy".', 'Type the name and description in every language tab.', 'Save.'],
            },
            {
                title: 'Bring back a deleted policy',
                steps: ['Click "Trash".', 'Click the restore button next to it.'],
            },
        ],
        affects: [
            { text: 'No screen or app reads these policies yet - they record the approved policy.' },
        ],
        warnings: [
            { level: 'info', text: 'Switching a requirement off here does not actually turn off sign-in, permissions or rate limits - those always run in code.' },
            { level: 'warning', text: 'Delete for good (from the trash) removes the policy and all its translations; it cannot be undone.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-security-policies/index.vue (countries-style list)',
            'resources/js/modules/admin/themes/theme-1/views/ai-security-policies/ModalCreateAndUpdate.vue (CatalogTranslationTabs: name, description)',
            'resources/js/composables/useAiPrivacyScreens.js (useAiSecurityPolicies), stores/aiPrivacyScreens.js',
            'Modules/AI/app/Http/Controllers/AiSecurityPolicyController.php (CatalogController; guarded by admin.screen on the route group)',
            'Modules/AI/app/Repositories/AiSecurityPolicyRepository.php (TranslatableRepository)',
            'Modules/AI/app/Models/AiSecurityPolicy.php (+ AiSecurityPolicyTranslation; SoftDeletes, status, HasFlatTranslatedText)',
        ],
        api: [
            'GET|POST       /api/admin/v1/ai-security-policies            (?status_counts=1, search, trashed)',
            'GET|PUT|DELETE /api/admin/v1/ai-security-policies/{policy}',
            'PATCH          /api/admin/v1/ai-security-policies/{policy}/status',
            'POST           /api/admin/v1/ai-security-policies/delete-multiple',
            'POST           /api/admin/v1/ai-security-policies/{policy}/restore',
            'DELETE         /api/admin/v1/ai-security-policies/{policy}/force',
        ],
        tables: [
            'ai_security_policies (authentication_required, authorization_required, tenant_isolation_required, rate_limit_enabled, status, deleted_at)',
            'ai_security_policy_translations (ai_security_policy_id, locale, name, description)',
        ],
        permissions: [
            'ai-security-policies.view (screen, list, show)',
            'ai-security-policies.create · ai-security-policies.update (edit, restore)',
            'ai-security-policies.delete (trash, force delete) · ai-security-policies.change-status · ai-security-policies.multiple-delete',
        ],
        decisions: [
            {
                ar: 'الاسم والوصف بيتترجموا (الأدمن بيقراهم)؛ المفاتيح الأربعة تقنية ومش بتتترجم.',
                en: 'Name and description are translated (admins read them); the four switches are technical and not translated.',
            },
            {
                ar: 'المفاتيح الأربعة (authentication_required, authorization_required, tenant_isolation_required, rate_limit_enabled) اتشالت من الفورم والجدول: كلها متطبقة دايمًا من الكود، وقفلها مينفعش يتطبق من غير ما يفتح ثغرة أمنية. الأعمدة لسه موجودة والـ API لسه بيقبلها ويرجعها زي ما هي.',
                en: 'The four switches (authentication_required, authorization_required, tenant_isolation_required, rate_limit_enabled) were removed from the form and the table: they are always enforced in code, and switching one off could never be applied without opening a security hole. The columns stay; the API still accepts and returns them as they are.',
            },
            {
                ar: 'مفيش حاجة بتشاور على السياسة، فالحذف النهائي مسموح دايمًا.',
                en: 'Nothing points at a policy, so delete for good is always allowed.',
            },
        ],
    },
};
