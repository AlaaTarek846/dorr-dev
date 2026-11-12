/**
 * Guide: AI settings (admin.ai-settings).
 * Source of truth checked: views/ai-settings/index.vue, components/settings/AiProviderCard.vue,
 * Modules/AI/app/Services/AiProviderService.php, Repositories/AiProviderRepository.php.
 */
export default {
    route: 'admin.ai-settings',
    updated: '2026-11-10',

    ar: {
        summary: 'هنا بتوصّل المنصة بشركات الذكاء الاصطناعي (OpenAI وAnthropic وGoogle وGroq): بتحط المفتاح، وتختار الموديل، وتختبر الاتصال، وتحدد مين المزود الافتراضي.',
        audience: 'مدير المنصة أو المسؤول الفني. بتتظبط مرة في الأول، وبعد كده لما تحب تغيّر مزود أو مفتاح. فتح الشاشة محتاج صلاحية "إعدادات الذكاء الاصطناعي: عرض"، وأي تغيير محتاج "تعديل".',
        purpose: [
            'أول تشغيل: من غير مزود واحد على الأقل متفعّل وعنده مفتاح، المساعد مش هيرد على حد، واليوزر هيشوف رسالة إن مفيش نموذج متفعّل.',
            'تغيير المزود أو الموديل: مثلًا تنقل الشات من OpenAI لـ Anthropic، أو تجرب موديل أحدث.',
            'تجديد المفتاح لو اتسرّب أو انتهى.',
            'ميزات الشات العادي بتعتمد عليها كمان: ترجمة الرسايل، وتحويل الفويس لنص، والتلخيص، والردود المقترحة.',
        ],
        sections: [
            {
                title: 'كارت المزود (واحد لكل مزود)',
                items: [
                    { name: 'مجاني / مدفوع', desc: 'المزود عنده خطة مجانية ولا لأ.' },
                    { name: 'شارة "افتراضي"', desc: 'ده المزود اللي بيرد على الشات لما يكون فيه أكتر من مزود متفعّل.' },
                    { name: 'رابط التوثيق', desc: 'يوديك لموقع المزود علشان تجيب المفتاح.' },
                    { name: 'مفعّل / متوقف', desc: 'زرار تشغيل المزود. مش هيتفعّل من غير مفتاح.' },
                    { name: 'مفتاح API', desc: 'بيتحفظ مشفّر، ومش بيظهر منه غير آخر حروف. فيه زرار يظهره أو يخفيه، وزرار يمسحه.' },
                    { name: 'الموديل', desc: 'اختار من القايمة (مكتوب جنبها لو "مباشرة من المزود" ولا "تقديرية")، أو اختار "موديل مخصص" واكتب اسمه.' },
                    { name: 'Temperature', desc: 'من 0 لـ 2. كل ما يقل الرد بيبقى أدق وأثبت، وكل ما يزيد بيبقى أكتر إبداعًا. لو فاضي هيستخدم قيمة المزود.' },
                    { name: 'Max tokens', desc: 'أقصى طول للرد (من 1 لمليون). لو فاضي هيستخدم قيمة المزود.' },
                    { name: 'إعدادات متقدمة', desc: 'Base URL لو بتستخدم بروكسي أو سيرفر متوافق، وOrganization لـ OpenAI.' },
                    { name: 'آخر اختبار', desc: 'إمتى آخر مرة اتعمل فيها اختبار اتصال.' },
                    { name: 'تعيين كافتراضي', desc: 'يخلّي المزود ده هو الأساسي. مش هيشتغل غير لو المزود متفعّل وعنده مفتاح.' },
                    { name: 'اختبار الاتصال', desc: 'يبعت طلب حقيقي للمزود ويقولك المفتاح شغال ولا لأ.' },
                    { name: 'حفظ', desc: 'يحفظ تعديلات الكارت.' },
                ],
            },
            {
                title: 'الموديلات المتصلة (تحت كل كارت)',
                items: [
                    { name: 'إضافة موديل', desc: 'تسجّل موديل بإيدك.' },
                    { name: 'مزامنة', desc: 'تجيب الموديلات الحقيقية من المزود دلوقتي. الجديد بيتضاف، واللي اختفى بيتعلّم "مُهمل" من غير ما حاجة تتمسح.' },
                    { name: 'إعادة تصنيف', desc: 'تطلب من الـ AI بتاع المزود نفسه يتأكد من قدرات كل موديل (صور، تفكير، برمجة...).' },
                    { name: 'تطبيع', desc: 'يعيد حساب فئة كل موديل من اسمه من غير ما يكلّم المزود. بيصلّح الموديلات اللي اتسجلت بفئة غلط.' },
                    { name: 'إعدادات لكل موديل', desc: 'Temperature وMax tokens خاصين بالموديل. لو فاضيين هياخد إعداد المزود اللي فوق.' },
                    { name: 'إخفاء الإصدارات المؤرخة', desc: 'يخفي النسخ زي gpt-4o-2024-08-06 ويسيب الموديل الأساسي بس.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'تشغيل مزود لأول مرة',
                steps: [
                    'دوس "رابط التوثيق" وطلّع مفتاح API من موقع المزود.',
                    'حط المفتاح في خانة "مفتاح API" واختار الموديل.',
                    'دوس "اختبار الاتصال". لو نجح هيظهر وقت "آخر اختبار".',
                    'فعّل المزود ودوس "حفظ".',
                    'لو عندك أكتر من مزود متفعّل، دوس "تعيين كافتراضي" على اللي عايزه يرد على الشات.',
                    'دوس "مزامنة" تحت في "الموديلات المتصلة" علشان السجل يبقى محدّث.',
                ],
            },
            {
                title: 'تغيير مفتاح API',
                steps: [
                    'اكتب المفتاح الجديد فوق القديم.',
                    'دوس "اختبار الاتصال" واتأكد إنه نجح.',
                    'دوس "حفظ".',
                ],
            },
        ],
        affects: [
            { text: 'شات المساعد عند اليوزر والكابتن (ويب وAndroid وiOS): مين بيرد.' },
            { text: 'أدوات الشات العادي: ترجمة وتحويل الفويس لنص وتلخيص وردود مقترحة. تحويل الفويس محتاج OpenAI أو Groq أو Google.' },
            { route: 'admin.ai-routing-rules.index', text: 'قواعد التوجيه وسياسات التوجيه بيختاروا من المزودين المتفعّلين هنا بس.' },
            { route: 'admin.ai-provider-health.index', text: 'صحة المزودين بتراقب المزودين دول.' },
            { route: 'admin.ai-failovers.index', text: 'الفيل أوفر: لو مزود وقع بيتحوّل لمزود تاني متفعّل.' },
        ],
        warnings: [
            { level: 'danger', text: 'لو مفيش أي مزود متفعّل وعنده مفتاح، المساعد بيقف عند كل اليوزرز.' },
            { level: 'warning', text: 'لو عندك أكتر من مزود متفعّل، دايمًا حدد واحد افتراضي. من غيره المسار البسيط مش هيلاقي مزود واضح، وكل حاجة بتعتمد على محرك التوجيه.' },
            { level: 'info', text: 'لو مسحت مفتاح المزود الافتراضي أو وقفته، علامة "افتراضي" بتتشال منه لوحدها.' },
            { level: 'info', text: 'موديلات o1/o3/o4 وموديلات الصور والصوت مش بتقبل تغيير Temperature. ده قيد من المزود، مش عطل.' },
            { level: 'info', text: 'Anthropic مش بيدعم تحويل الفويس لنص، فالنظام بيستخدم أول مزود متفعّل يدعمه.' },
            { level: 'info', text: 'لو عندك صلاحية العرض بس، الكروت بتفتح للقراءة: كل الحقول والأزرار مقفولة، ومحدش يقدر يغيّر مفتاح أو مزود.' },
        ],
    },

    en: {
        summary: 'Connect the platform to AI companies (OpenAI, Anthropic, Google, Groq): add the key, pick the model, test the connection, and choose the default provider.',
        audience: 'The platform owner or technical admin. Set once at launch, then whenever a provider or key changes. Opening the screen needs "AI settings: view"; any change needs "update".',
        purpose: [
            'First launch: with no enabled provider that has a key, the assistant answers no one and users see a "no model enabled" message.',
            'Switching provider or model, e.g. moving chat from OpenAI to Anthropic or trying a newer model.',
            'Rotating a leaked or expired key.',
            'Regular chat tools rely on it too: message translation, voice-to-text, summaries, suggested replies.',
        ],
        sections: [
            {
                title: 'Provider card (one per provider)',
                items: [
                    { name: 'Free / Paid', desc: 'Whether the provider has a free tier.' },
                    { name: '"Default" badge', desc: 'The provider that answers chat when more than one is enabled.' },
                    { name: 'Docs link', desc: 'Opens the provider site to get a key.' },
                    { name: 'Enabled / Disabled', desc: 'Turns the provider on. Cannot be enabled without a key.' },
                    { name: 'API key', desc: 'Stored encrypted; only the last characters are shown. Show/hide and clear buttons.' },
                    { name: 'Model', desc: 'Pick from the list (marked "live from provider" or "estimated") or choose "custom model" and type it.' },
                    { name: 'Temperature', desc: '0 to 2. Lower = more precise and stable, higher = more creative. Empty = provider default.' },
                    { name: 'Max tokens', desc: 'Longest answer (1 to 1,000,000). Empty = provider default.' },
                    { name: 'Advanced', desc: 'Base URL for a proxy or compatible server; Organization for OpenAI.' },
                    { name: 'Last tested', desc: 'When the connection was last tested.' },
                    { name: 'Set as default', desc: 'Makes this the main provider. Only works when enabled and keyed.' },
                    { name: 'Test connection', desc: 'Sends a real request and tells you whether the key works.' },
                    { name: 'Save', desc: 'Saves the card.' },
                ],
            },
            {
                title: 'Connected models (under each card)',
                items: [
                    { name: 'Add model', desc: 'Register a model by hand.' },
                    { name: 'Sync', desc: 'Fetch the real model list now. New ones are added, missing ones become "deprecated"; nothing is deleted.' },
                    { name: 'Reclassify', desc: 'Ask the provider’s own AI to confirm each model’s abilities (vision, reasoning, coding…).' },
                    { name: 'Normalize', desc: 'Recompute each model’s category from its name without calling the provider; fixes wrongly categorised models.' },
                    { name: 'Per-model settings', desc: 'Temperature and max tokens for one model. Empty = the provider setting above.' },
                    { name: 'Hide dated snapshots', desc: 'Hides versions like gpt-4o-2024-08-06 and keeps the base model.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Turn on a provider for the first time',
                steps: [
                    'Open the docs link and create an API key on the provider site.',
                    'Paste it in "API key" and choose the model.',
                    'Click "Test connection". On success "Last tested" updates.',
                    'Enable the provider and click "Save".',
                    'With more than one enabled provider, click "Set as default" on the one that should answer chat.',
                    'Click "Sync" under "Connected models" to refresh the registry.',
                ],
            },
            {
                title: 'Rotate an API key',
                steps: ['Type the new key over the old one.', 'Click "Test connection" and make sure it passes.', 'Click "Save".'],
            },
        ],
        affects: [
            { text: 'The assistant chat for users and captains (web, Android, iOS): who answers.' },
            { text: 'Regular chat tools: translation, voice-to-text, summaries, suggested replies. Voice-to-text needs OpenAI, Groq or Google.' },
            { route: 'admin.ai-routing-rules.index', text: 'Routing rules and policies only pick from providers enabled here.' },
            { route: 'admin.ai-provider-health.index', text: 'Provider health monitors these providers.' },
            { route: 'admin.ai-failovers.index', text: 'Failover switches to another enabled provider when one is down.' },
        ],
        warnings: [
            { level: 'danger', text: 'With no enabled provider that has a key, the assistant stops for every user.' },
            { level: 'warning', text: 'With several enabled providers, always set a default; otherwise the simple path finds no clear provider and everything relies on the routing engine.' },
            { level: 'info', text: 'Clearing the key of, or disabling, the default provider removes its "default" flag automatically.' },
            { level: 'info', text: 'o1/o3/o4 and image/audio models do not accept a custom temperature — a provider limit, not a bug.' },
            { level: 'info', text: 'Anthropic has no voice-to-text, so the system uses the first enabled provider that does.' },
            { level: 'info', text: 'With the view permission only, the cards are read-only: every field and button is disabled.' },
        ],
    },

    // Developer notes: shown collapsed at the bottom of the panel.
    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-settings/index.vue',
            'resources/js/components/settings/AiProviderCard.vue',
            'Modules/AI/app/Services/AiProviderService.php',
            'Modules/AI/app/Repositories/AiProviderRepository.php (resolveActiveForChat, resolveForTranscription)',
        ],
        api: [
            'GET  /api/admin/v1/ai-providers',
            'POST /api/admin/v1/ai-providers/{provider}',
            'POST /api/admin/v1/ai-providers/{provider}/test',
            'POST /api/admin/v1/ai-providers/{provider}/set-default',
            '/api/admin/v1/ai-providers/{provider}/models (index, store, update, destroy, sync, reclassify, normalize)',
        ],
        tables: ['ai_providers', 'ai_provider_models'],
        permissions: ['ai-settings.view (screen)', 'ai-settings.update (save, test, default, models)', 'reading /ai-providers stays open: dropdowns in feature flags / routing rules / provider data rules'],
        decisions: [],
    },
};
