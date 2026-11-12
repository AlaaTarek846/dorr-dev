/**
 * Guide: AI provider data rules (admin.ai-provider-data-rules.index) - cleaning personal data / secrets before a call
 * leaves to a provider.
 * Source of truth checked: views/ai-provider-data-rules/*.vue, composables/useAiPrivacyScreens.js
 * (useAiProviderDataRules), stores/aiPrivacyScreens.js, Modules/AI/app/Models/AiProviderDataRule.php
 * (+ Concerns/HasIsActiveAlias.php), Repositories/AiProviderDataRuleRepository.php,
 * Http/Controllers/AiProviderDataRuleController.php, Http/Requests/AiProviderDataRuleRequest.php,
 * Http/Resources/AiProviderDataRuleResource.php, Services/AiGateway.php (applyDataRules, dataRuleFor),
 * Services/AiPiiSanitizer.php, database/seeders/AIDatabaseSeeder.php, routes/admin.php.
 */
export default {
    route: 'admin.ai-provider-data-rules.index',
    updated: '2026-11-11',

    ar: {
        summary: 'هنا قاعدة لكل مزود ذكاء اصطناعي: قبل ما رسايل اليوزر تخرج للمزود، بوابة الذكاء الاصطناعي بتخبّي منها البيانات الشخصية و/أو الأسرار حسب القاعدة.',
        audience: 'مسؤول الذكاء الاصطناعي أو الخصوصية. محتاج صلاحيات "قواعد بيانات المزودين": عرض، إضافة، تعديل، حذف، تغيير الحالة، حذف متعدد.',
        purpose: [
            'التنضيف بيتعمل على رسايل اليوزر والمساعد بس، مش على رسايل النظام بتاعتنا.',
            'مزود من غير قاعدة مفعّلة = الكلام بيوصله زي ما هو (إلا لو سياسة البيانات الشخصية قافلة "مزود خارجي" - ساعتها البيانات الشخصية بتتخفى لكل المزودين).',
            'قواعد التحويل (JSON) بتتطبق كمان، حتى لو المفتاحين مقفولين: "mask_fields" = أنواع بعينها تتخفى بس (email, phone, id_number, secrets)، و"redact_terms" = كلمات أو جمل بتتبدل بـ [redacted] من غير فرق بين الحروف الكبيرة والصغيرة. أي مفتاح تاني بيتجاهل.',
        ],
        sections: [
            {
                title: 'شريط الأدوات',
                items: [
                    { name: 'البحث', desc: 'باسم المزود أو مفتاحه.' },
                    { name: 'الكل / مفعّلة / متوقفة / المحذوفات', desc: 'فلترة سريعة بالأعداد.' },
                    { name: 'حذف المحدد / إضافة قاعدة', desc: 'حسب صلاحياتك.' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'المزود', desc: 'الاسم وتحته المفتاح ورقم القاعدة. دوس عليه يفتح التعديل.' },
                    { name: 'تنظيف البيانات الشخصية / تنظيف الأسرار', desc: 'علامة صح = بيتنضف.' },
                    { name: 'قواعد التحويل', desc: 'الـ JSON المتسجل، أو "—".' },
                    { name: 'الحالة / التاريخ / الأزرار', desc: 'زي شاشة الدول.' },
                ],
            },
            {
                title: 'فورم القاعدة',
                items: [
                    { name: 'المزود', desc: 'إجباري، من مزودين الذكاء الاصطناعي. قاعدة واحدة لكل مزود (حتى لو القديمة في السلة - رجّعها). مينفعش يتغيّر بعد الحفظ.' },
                    { name: 'تنظيف البيانات الشخصية', desc: 'الإيميلات والتليفونات وزيهم بتتخبّى.' },
                    { name: 'تنظيف الأسرار', desc: 'المفاتيح وكلمات السر والتوكنات بتتخبّى.' },
                    { name: 'قواعد التحويل (JSON)', desc: 'اختياري، فاضي = مفيش. مثال: {"mask_fields": ["email", "phone"], "redact_terms": ["مشروع الصقر"]}. نوع مش معروف في mask_fields أو كلمة أقل من حرفين أو أكتر من 100 = الحفظ بيترفض.' },
                    { name: 'الحالة', desc: 'مقفولة = المزود بيوصله الكلام زي ما هو.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'وقف التنضيف لمزود مؤقتًا',
                steps: ['اقفل زرار الحالة جنب المزود.'],
            },
            {
                title: 'إضافة قاعدة لمزود جديد',
                steps: ['دوس "إضافة قاعدة".', 'اختار المزود.', 'فعّل التنضيف اللي محتاجه ودوس حفظ.'],
            },
        ],
        affects: [
            { route: 'admin.ai-settings', text: 'كل قاعدة تابعة لمزود؛ حذف المزود بيحذف القاعدة معاه.' },
            { text: 'الشات بالذكاء الاصطناعي في التطبيقات وشات دُر: إيه اللي بيخرج من رسايل اليوزر للمزود.' },
        ],
        warnings: [
            { level: 'danger', text: 'إيقاف القاعدة أو نقلها للسلة معناه إن البيانات الشخصية والأسرار هتوصل للمزود زي ما هي.' },
            { level: 'info', text: 'الحذف النهائي من السلة متاح دايمًا، وبعده تقدر تعمل قاعدة جديدة لنفس المزود.' },
        ],
    },

    en: {
        summary: 'One rule per AI provider: before the user\'s messages leave to that provider, the AI gateway masks personal data and/or secrets in them, per the rule.',
        audience: 'AI or privacy administrator. Needs the "Provider data rules" permissions: view, create, update, delete, change status, multiple delete.',
        purpose: [
            'Cleaning applies to the user and assistant turns only, never to our own system messages.',
            'A provider with no active rule gets the text as it is (unless the personal data policy has "external provider" off - then personal data is masked for every provider).',
            'The transformation rules (JSON) apply too, even with both switches off: "mask_fields" = only those kinds are masked (email, phone, id_number, secrets), "redact_terms" = words or phrases replaced with [redacted], case-insensitive. Any other key is ignored.',
        ],
        sections: [
            {
                title: 'Toolbar',
                items: [
                    { name: 'Search', desc: 'By provider name or key.' },
                    { name: 'All / Active / Inactive / Trash', desc: 'Quick filters with counts.' },
                    { name: 'Delete selected / Add rule', desc: 'Per your permissions.' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Provider', desc: 'Name with its key and the rule id. Click to edit.' },
                    { name: 'Sanitize PII / Sanitize secrets', desc: 'A tick = cleaned.' },
                    { name: 'Transformation rules', desc: 'The stored JSON, or "—".' },
                    { name: 'Status / dates / buttons', desc: 'Like countries.' },
                ],
            },
            {
                title: 'Rule form',
                items: [
                    { name: 'Provider', desc: 'Required, from the AI providers. One rule per provider (even when the old one is in the trash - restore it). Cannot be changed after saving.' },
                    { name: 'Sanitize PII', desc: 'Emails, phone numbers and similar are masked.' },
                    { name: 'Sanitize secrets', desc: 'Keys, passwords and tokens are masked.' },
                    { name: 'Transformation rules (JSON)', desc: 'Optional, empty = none. Example: {"mask_fields": ["email", "phone"], "redact_terms": ["Project Falcon"]}. An unknown kind in mask_fields, or a term under 2 or over 100 characters, is refused on save.' },
                    { name: 'Status', desc: 'Off = the provider gets the text as it is.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Pause cleaning for a provider',
                steps: ['Switch off the status next to the provider.'],
            },
            {
                title: 'Add a rule for a new provider',
                steps: ['Click "Add rule".', 'Pick the provider.', 'Turn on the cleaning you need and save.'],
            },
        ],
        affects: [
            { route: 'admin.ai-settings', text: 'Every rule belongs to a provider; deleting the provider deletes its rule.' },
            { text: 'The AI chat in the apps and DORR chat: what leaves the user\'s messages to the provider.' },
        ],
        warnings: [
            { level: 'danger', text: 'Switching a rule off or moving it to the trash means personal data and secrets reach the provider as they are.' },
            { level: 'info', text: 'Delete for good is always allowed from the trash; then a new rule can be made for the same provider.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-provider-data-rules/index.vue (countries-style list, no translations)',
            'resources/js/modules/admin/themes/theme-1/views/ai-provider-data-rules/ModalCreateAndUpdate.vue (provider picker: GET ai-providers?all=1)',
            'resources/js/composables/useAiPrivacyScreens.js (useAiProviderDataRules: search in provider name / key), stores/aiPrivacyScreens.js',
            'Modules/AI/app/Http/Controllers/AiProviderDataRuleController.php (CatalogController; guarded by admin.screen on the route group)',
            'Modules/AI/app/Repositories/AiProviderDataRuleRepository.php (BaseRepository)',
            'Modules/AI/app/Models/AiProviderDataRule.php (SoftDeletes, status, HasIsActiveAlias)',
            'Modules/AI/app/Services/AiGateway.php (dataRuleFor: provider + status, trash excluded; applyDataRules) · AiPiiSanitizer.php (applyTransformationRules, MASK_FIELDS)',
        ],
        api: [
            'GET|POST       /api/admin/v1/ai-provider-data-rules            (?status_counts=1, search, trashed)',
            'GET|PUT|DELETE /api/admin/v1/ai-provider-data-rules/{rule}',
            'PATCH          /api/admin/v1/ai-provider-data-rules/{rule}/status',
            'POST           /api/admin/v1/ai-provider-data-rules/delete-multiple',
            'POST           /api/admin/v1/ai-provider-data-rules/{rule}/restore',
            'DELETE         /api/admin/v1/ai-provider-data-rules/{rule}/force',
        ],
        tables: ['ai_provider_data_rules (provider_id unique, sanitize_pii, sanitize_secrets, transformation_rules, status, deleted_at)'],
        permissions: [
            'ai-provider-data-rules.view (screen, list, show; the provider picker reads ai-providers)',
            'ai-provider-data-rules.create · ai-provider-data-rules.update (edit, restore)',
            'ai-provider-data-rules.delete (trash, force delete) · ai-provider-data-rules.change-status · ai-provider-data-rules.multiple-delete',
        ],
        noTranslatableText: 'A provider data rule holds no text people read: a provider, two switches and JSON transformation rules - all technical values.',
        decisions: [
            {
                ar: 'مفيش جدول ترجمة: القاعدة مفيهاش نص للقراية.',
                en: 'No translation table: a rule has no text to read.',
            },
            {
                ar: 'provider_id فريد حتى مع السلة؛ الطلب بيرفض قاعدة تانية لنفس المزود وبيقول رجّع القديمة.',
                en: 'provider_id stays unique with the trash; a second rule for the same provider is refused (restore the old one).',
            },
        ],
    },
};
