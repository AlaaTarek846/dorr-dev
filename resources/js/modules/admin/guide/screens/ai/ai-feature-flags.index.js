/**
 * Guide: AI feature flags (admin.ai-feature-flags.index) - the admin's kill switches for providers, models and the
 * DORR AI chat, by country / intent / environment.
 * Source of truth checked: views/ai-feature-flags/*.vue, composables/useAiControlScreens.js (useAiFeatureFlags),
 * Modules/AI/app/Models/AiFeatureFlag.php (+ AiFeatureFlagTranslation, Concerns/HasFlatTranslatedText.php),
 * Repositories/AiFeatureFlagRepository.php, Http/Controllers/AiFeatureFlagController.php,
 * Http/Requests/AiFeatureFlagRequest.php, Http/Resources/AiFeatureFlagResource.php, Services/AiFeatureFlagGate.php,
 * Services/AiRoutingEngine.php (provider / model checks, domain = intent key), Services/AiChatService.php
 * (isServiceAllowed ai_chat), routes/admin.php, database/migrations/2026_11_10_150000_make_ai_feature_flags_translatable.php.
 */
export default {
    route: 'admin.ai-feature-flags.index',
    updated: '2026-11-10',

    ar: {
        summary: 'هنا مفاتيح الإيقاف (feature flags): كل flag بيقفل مزود أو موديل أو شات دُر بالذكاء الاصطناعي كله لدولة أو نوع طلب أو بيئة، من غير ما تنزّل كود.',
        audience: 'مسؤول الذكاء الاصطناعي. محتاج صلاحيات "خواص التشغيل": عرض، إضافة، تعديل، حذف، تغيير الحالة، حذف متعدد.',
        purpose: [
            'الـ flag مفتاح إيقاف مش قايمة مسموحات: أي حاجة مفيش عليها flag مقفول شغالة عادي.',
            'الحالة مقفولة = الإيقاف شغال: الهدف بيتقفل في كل مكان النطاق بيطابقه. الحالة مفتوحة = الـ flag مش بيعمل حاجة.',
            'التغيير بيتطبق فورًا (الكاش بيتمسح مع كل حفظ أو حذف أو استرجاع).',
        ],
        sections: [
            {
                title: 'شريط الأدوات',
                items: [
                    { name: 'البحث', desc: 'بالمفتاح أو الوصف (أي لغة) أو اسم/مفتاح المزود أو الموديل أو الأداة أو الدولة أو نوع الطلب أو البيئة.' },
                    { name: 'الكل / مفعّلة / مقفولة / المحذوفات', desc: 'فلترة بالأعداد. "مقفولة" = الـ flags اللي قافلة هدفها دلوقتي. السلة بتظهر لو فيها حاجة.' },
                    { name: 'حذف المحدد / إضافة flag', desc: 'حسب صلاحياتك.' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'المفتاح', desc: 'وتحته الوصف بلغتك ورقم الـ flag. دوس عليه يفتح التعديل.' },
                    { name: 'الهدف', desc: 'مزود / موديل / أداة / خدمة كاملة، وتحته المزود والموديل أو "شات دُر بالذكاء الاصطناعي".' },
                    { name: 'النطاق', desc: 'الدولة / نوع الطلب، أو "كل الدول" / "كل أنواع الطلبات".' },
                    { name: 'البيئة', desc: 'لازم تساوي APP_ENV بتاع السيرفر.' },
                    { name: 'مفعّل', desc: 'زرار الحالة: مقفول = الإيقاف شغال.' },
                    { name: 'التاريخ / الأزرار', desc: 'زي شاشة الدول: تعديل وحذف، وفي السلة استرجاع وحذف نهائي.' },
                ],
            },
            {
                title: 'فورم الـ flag',
                items: [
                    { name: 'الوصف (تاب لكل لغة)', desc: 'اختياري، لحد 1000 حرف، بيظهر للأدمن بس.' },
                    { name: 'المفتاح', desc: 'إجباري، لحد 150 حرف. اسم للتعريف بس - البوابة مش بتقراه، ومش لازم يكون فريد.' },
                    { name: 'الهدف', desc: 'مزود، موديل، أداة، أو خدمة كاملة. الحقول اللي تحت بتتغير حسبه، واللي مش تبعه بيتمسح عند الحفظ.' },
                    { name: 'المزود', desc: 'للمزود والموديل. فاضي = كل المزودين.' },
                    { name: 'الموديل', desc: 'للموديل، زي ما المزود بيسميه بالظبط. فاضي = كل الموديلات.' },
                    { name: 'الأداة', desc: 'بيتحفظ بس: مفيش أداة بتسأل البوابة لسه.' },
                    { name: 'الخدمة', desc: 'حاليًا شات دُر بالذكاء الاصطناعي بس (ai_chat).' },
                    { name: 'الدولة', desc: 'حرفين (بيتحفظوا كابيتال، مثلًا SA). فاضي = كل الدول.' },
                    { name: 'نوع الطلب', desc: 'مفتاح نوع الطلب اللي الرسالة اتصنفت بيه (chat، support…). فاضي = الكل. في flag الخدمة سيبه فاضي، وإلا مش هيطابق أبدًا.' },
                    { name: 'البيئة', desc: 'لحد 20 حرف، فاضي = production. لو مش زي APP_ENV الـ flag مش هيتطبق.' },
                    { name: 'مفعّل', desc: 'مقفول = الإيقاف شغال. مفتوح = الـ flag مش بيعمل حاجة.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'اقفل شات الذكاء الاصطناعي في دولة',
                steps: ['دوس "إضافة flag".', 'اكتب مفتاح زي ai-chat-off-sa والوصف بكل اللغات.', 'اختار الهدف "خدمة كاملة" والخدمة "شات دُر بالذكاء الاصطناعي".', 'اكتب الدولة SA وسيب نوع الطلب فاضي.', 'اقفل زرار "مفعّل" ودوس حفظ - التطبيقات هتقول "مش متاح" هناك فورًا.'],
            },
            {
                title: 'وقّف مزود فيه عطل في دولة',
                steps: ['أضف flag هدفه "مزود" واختار المزود.', 'اكتب الدولة، واقفل "مفعّل" واحفظ - التوجيه هيروح للبديل.', 'لما العطل يخلص افتح "مفعّل" أو انقل الـ flag للسلة.'],
            },
        ],
        affects: [
            { route: 'admin.ai-routing-rules.index', text: 'قواعد التوجيه بتتجاهل أي مزود أو موديل مقفول للدولة ونوع الطلب.' },
            { route: 'admin.ai-settings', text: 'المزود المقفول بـ flag مش بيرد حتى لو مفعّل وليه مفتاح.' },
            { text: 'شات دُر بالذكاء الاصطناعي في التطبيقات: flag خدمة ai_chat مقفول لدولة بيخلي الشات "مش متاح" للناس اللي فيها.' },
        ],
        warnings: [
            { level: 'danger', text: 'الحالة هنا معكوسة عن باقي الشاشات: "مقفولة" معناها الإيقاف شغال والهدف متقفل.' },
            { level: 'warning', text: 'نقل الـ flag للسلة بيلغيه: الهدف بيرجع يشتغل فورًا. ولو استرجعته الإيقاف بيرجع.' },
            { level: 'warning', text: 'flag خدمة فيه نوع طلب عمره ما بيطابق - سيب نوع الطلب فاضي.' },
            { level: 'info', text: 'flags الأدوات بتتحفظ بس لحد دلوقتي. والحذف النهائي متاح من السلة في أي وقت.' },
        ],
    },

    en: {
        summary: 'Feature flags are kill switches: each flag turns a provider, a model or the whole DORR AI chat off for a country, an intent or an environment - no code deploy needed.',
        audience: 'AI administrator. Needs the "Feature flags" permissions: view, create, update, delete, change status, multiple delete.',
        purpose: [
            'A flag is a kill switch, not an allowlist: anything without a switched-off flag works as normal.',
            'Status off = the kill switch is on: the target is turned off wherever the scope matches. Status on = the flag does nothing.',
            'A change applies at once (the cache is cleared on every save, trash or restore).',
        ],
        sections: [
            {
                title: 'Toolbar',
                items: [
                    { name: 'Search', desc: 'By key, description (any language), provider name/key, model, tool, country, intent or environment.' },
                    { name: 'All / Enabled / Switched off / Trash', desc: 'Filters with counts. "Switched off" = the flags turning their target off right now. Trash shows when it has rows.' },
                    { name: 'Delete selected / Add flag', desc: 'Per your permissions.' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Key', desc: 'With the description in your language and the flag id. Click to edit.' },
                    { name: 'Target', desc: 'Provider / model / tool / whole service, with the provider and model or "DORR AI chat".' },
                    { name: 'Scope', desc: 'Country / intent, or "All countries" / "All intents".' },
                    { name: 'Environment', desc: 'Must equal the server\'s APP_ENV.' },
                    { name: 'Enabled', desc: 'The status switch: off = the kill switch is on.' },
                    { name: 'Dates / buttons', desc: 'Like countries: edit and delete; in the trash, restore and delete for good.' },
                ],
            },
            {
                title: 'Flag form',
                items: [
                    { name: 'Description (one tab per language)', desc: 'Optional, up to 1000 chars, admins only.' },
                    { name: 'Key', desc: 'Required, up to 150 chars. A name to recognise it - the gate does not read it, and it need not be unique.' },
                    { name: 'Target', desc: 'Provider, model, tool or whole service. The fields below follow it; the others are cleared on save.' },
                    { name: 'Provider', desc: 'For provider and model flags. Empty = every provider.' },
                    { name: 'Model', desc: 'For model flags, exactly as the provider names it. Empty = every model.' },
                    { name: 'Tool', desc: 'Stored only: no tool asks the gate yet.' },
                    { name: 'Service', desc: 'Today only the DORR AI chat (ai_chat).' },
                    { name: 'Country', desc: 'Two letters (stored upper case, e.g. SA). Empty = every country.' },
                    { name: 'Intent', desc: 'The intent key the message was classified with (chat, support…). Empty = all. Leave it empty on a service flag, or it never matches.' },
                    { name: 'Environment', desc: 'Up to 20 chars, empty = production. If it differs from APP_ENV the flag never applies.' },
                    { name: 'Enabled', desc: 'Off = the kill switch is on. On = the flag does nothing.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Turn the AI chat off in a country',
                steps: ['Click "Add flag".', 'Type a key such as ai-chat-off-sa and the description in every language.', 'Pick the target "Whole service" and the service "DORR AI chat".', 'Type the country SA and leave the intent empty.', 'Switch "Enabled" off and save - the apps show "not available" there at once.'],
            },
            {
                title: 'Pause a provider that is down in a country',
                steps: ['Add a flag with the target "Provider" and pick the provider.', 'Type the country, switch "Enabled" off and save - routing moves to the fallback.', 'When it is fixed, switch "Enabled" on or move the flag to the trash.'],
            },
        ],
        affects: [
            { route: 'admin.ai-routing-rules.index', text: 'Routing rules skip a provider or model switched off for the country and intent.' },
            { route: 'admin.ai-settings', text: 'A provider switched off by a flag never answers, even when enabled and with a key.' },
            { text: 'The DORR AI chat in the apps: a switched-off ai_chat service flag for a country makes the chat "not available" for people there.' },
        ],
        warnings: [
            { level: 'danger', text: 'The status works the other way round from other screens: "Switched off" means the kill switch is on and the target is off.' },
            { level: 'warning', text: 'Moving a flag to the trash cancels it: its target works again at once. Restoring it brings the kill switch back.' },
            { level: 'warning', text: 'A service flag with an intent never matches - leave the intent empty.' },
            { level: 'info', text: 'Tool flags are stored only, for now. Delete for good is always allowed from the trash.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-feature-flags/index.vue (countries-style list)',
            'resources/js/modules/admin/themes/theme-1/views/ai-feature-flags/ModalCreateAndUpdate.vue (description tabs; providers from ai-providers)',
            'resources/js/composables/useAiControlScreens.js (useAiFeatureFlags), stores/aiControlScreens.js',
            'Modules/AI/app/Http/Controllers/AiFeatureFlagController.php (CatalogController; guarded by admin.screen on the route group)',
            'Modules/AI/app/Repositories/AiFeatureFlagRepository.php (TranslatableRepository)',
            'Modules/AI/app/Models/AiFeatureFlag.php (SoftDeletes, status = the old is_enabled, is_enabled alias, cache flush on change)',
            'Modules/AI/app/Services/AiFeatureFlagGate.php (switched-off flags where status = false, trashed excluded, 30 s cache)',
        ],
        api: [
            'GET|POST       /api/admin/v1/ai-feature-flags            (?status_counts=1, search, filterColumns, trashed)',
            'GET|PUT|DELETE /api/admin/v1/ai-feature-flags/{flag}',
            'PATCH          /api/admin/v1/ai-feature-flags/{flag}/status',
            'POST           /api/admin/v1/ai-feature-flags/delete-multiple',
            'POST           /api/admin/v1/ai-feature-flags/{flag}/restore',
            'DELETE         /api/admin/v1/ai-feature-flags/{flag}/force',
        ],
        tables: [
            'ai_feature_flags (key, target_type, provider_id, model_key, tool_key, country_code, domain, environment, status, deleted_at)',
            'ai_feature_flag_translations (ai_feature_flag_id, locale, description)',
        ],
        permissions: [
            'ai-feature-flags.view (screen, list)',
            'ai-feature-flags.create · ai-feature-flags.update (edit, restore)',
            'ai-feature-flags.delete (trash, force delete) · ai-feature-flags.change-status · ai-feature-flags.multiple-delete',
        ],
        decisions: [
            {
                ar: 'الوصف بس اللي بيتترجم؛ المفتاح والنطاق قيم تقنية. is_enabled بقى status واتساب كـ alias في الموديل والـ API.',
                en: 'Only the description is translated; key and scope are technical. is_enabled became status and stays as an alias in the model and the API.',
            },
            {
                ar: 'الـ flag في السلة مش بيتطبق (زي ما كان الحذف قبل كده)، يعني الهدف بيرجع يشتغل.',
                en: 'A trashed flag is never applied (as a deleted one before), so its target works again.',
            },
        ],
    },
};
