/**
 * Guide: AI provider health (admin.ai-provider-health.index).
 * Source of truth checked: views/ai-provider-health/index.vue, Modules/AI/routes/admin.php (admin/v1/ai-provider-health),
 * Http/Controllers/AiProviderHealthController.php, Services/AiProviderHealthService.php,
 * Repositories/AiProviderHealthRepository.php, Http/Resources/AiProviderHealthResource.php, Models/AiProviderHealth.php,
 * Services/AiCircuitBreaker.php (the only writer), Services/AiChatService.php (dispatchWithFallback),
 * Services/AiTaskRunner.php, Modules/AI/config/config.php (circuit_breaker),
 * database/migrations/2026_09_22_160100_create_ai_provider_health_table.php, Console/Commands/EnforceAiDataRetention.php.
 */
export default {
    route: 'admin.ai-provider-health.index',
    updated: '2026-11-11',

    ar: {
        summary: 'سجل تغيّر حالة كل مزود ذكاء اصطناعي: سليم، متدهور، مقفول مؤقتًا، أو بيتعافى. صف جديد بيتكتب بس لما الحالة تتغير، ومن آخر صف للمزود النظام بيقرر يكلمه ولا يعدّيه للبديل. الشاشة للقراءة بس.',
        audience: 'المسؤول الفني أو مدير المنصة. محتاج صلاحية "ai-provider-health.view" (عرض بس).',
        purpose: [
            'تشوف المزود شغال كويس ولا بيفشل، من غير ما تستنى شكوى من اليوزر.',
            'الصفوف بيكتبها الـ circuit breaker (AiCircuitBreaker) بعد المكالمات الحقيقية للمزود من شات المساعد أو المهام الداخلية (AiTaskRunner: نصوص، تحويل الفويس لنص، صور)، بس لما الحالة تتغير: مزود سليم بينجح مش بيكتب حاجة، وأول فشل بيكتب صف degraded، والفشل اللي بعده بيحدّث نفس الصف (عدد الأخطاء)، ووصول الحد بيكتب صف unhealthy.',
            'لما المزود يفشل 3 مرات ورا بعض (الافتراضي)، بيتكتب صف unhealthy والمزود بيتقفل 60 ثانية (الافتراضي). في المدة دي الطلبات بتروح للبديل من غير ما تكلمه.',
            'بعد المدة، بيتكتب صف recovering (نص مفتوح) والطلبات بتجرب المزود تاني: أول نجاح يرجّعه healthy، وأول فشل يقفله تاني على طول (صف unhealthy).',
        ],
        sections: [
            {
                title: 'الجدول (الأحدث فوق)',
                items: [
                    { name: 'المزود', desc: 'اسم المزود. لو المزود اتمسح، صفوفه بتتمسح معاه.' },
                    { name: 'نوع الفحص', desc: 'دلوقتي دايمًا functional (يعني مكالمة حقيقية). connectivity وlatency وavailability موجودين في الجدول بس مفيش كود بيكتبهم.' },
                    { name: 'درجة الصحة', desc: 'من 0 لـ 1. healthy = 1. degraded = 1 ناقص (عدد الأخطاء ورا بعض ÷ الحد)، مثلًا بعد خطأ واحد من 3 = 0.67. unhealthy = 0.' },
                    { name: 'الحالة', desc: 'أخضر healthy = رجع يشتغل (أو عمره ما فشل: مفيش صف خالص). أصفر degraded = فشل بس لسه تحت الحد. أحمر unhealthy = وصل الحد واتقفل مؤقتًا. أزرق recovering = مدة القفل خلصت والطلبات بتجربه تاني.' },
                    { name: 'التاريخ', desc: 'وقت ما الحالة دي بدأت (الصف degraded أو unhealthy بيتحدّث في مكانه مع كل فشل زيادة).' },
                ],
            },
            {
                title: 'أسفل الجدول',
                items: [
                    { name: 'الصفحات وعدد الصفوف', desc: 'تنقّل بين الصفحات وتختار كام صف في الصفحة. مفيش بحث ولا فلتر بالمزود.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'أعرف حالة مزود دلوقتي',
                steps: [
                    'الجدول مترتب الأحدث فوق، فدوّر على أول صف للمزود ده. لو مفيش صفوف خالص: المزود عمره ما فشل.',
                    'لو healthy: المزود شغال.',
                    'لو recovering: القفل خلص والنظام بيجربه تاني.',
                    'لو degraded: فيه أخطاء بدأت. تابع "سجلات المزودين" علشان تعرف نوعها.',
                    'لو unhealthy: المزود مقفول لدقيقة تقريبًا والطلبات بتروح للبديل. افتح "الفيل أوفر" وهتلاقي صفوف بسبب health_threshold.',
                ],
            },
            {
                title: 'مزود بيفضل يتقفل ويفتح',
                steps: [
                    'لو شايف healthy وunhealthy بيتبدلوا كتير، المزود مش مستقر.',
                    'افتح "إعدادات الذكاء الاصطناعي" ودوس "اختبار الاتصال" على المزود.',
                    'لو المشكلة مستمرة: وقّف المزود أو نزّله في ترتيب قواعد التوجيه.',
                ],
            },
        ],
        affects: [
            { text: 'الشاشة نفسها مش بتغيّر حاجة. بس الصفوف اللي فيها هي اللي بتخلي النظام يعدّي المزود المقفول في شات المساعد والمهام الداخلية وموجّه النوايا.' },
            { route: 'admin.ai-failovers.index', text: 'الفيل أوفر: لما المزود يبقى unhealthy، الطلبات بتنتقل للبديل بسبب health_threshold.' },
            { route: 'admin.ai-provider-logs.index', text: 'سجلات المزودين: تفاصيل المكالمات اللي نتج عنها الحالة.' },
            { route: 'admin.ai-audit-events.index', text: 'أحداث التدقيق: كل مرة مزود مقفول اتعدّى بيتسجل حدث "circuit breaker opened".' },
            { route: 'admin.ai-settings', text: 'إعدادات الذكاء الاصطناعي: اختبار الاتصال أو إيقاف المزود.' },
        ],
        warnings: [
            { level: 'info', text: 'ده مش فحص دوري: الصف بيتكتب بس لما فيه طلب حقيقي. مزود مفيش عليه طلبات مش هيكون له صفوف جديدة، وآخر حالة ليه ممكن تكون قديمة.' },
            { level: 'info', text: 'صف بس مع كل تغيير في الحالة، والصفوف الأقدم من مدة الاحتفاظ بتتمسح يوميًا (ai:enforce-retention)، ما عدا آخر صف لكل مزود لأنه حالته الحالية.' },
            { level: 'info', text: 'الحد (3 أخطاء) ومدة القفل (60 ثانية) وتشغيل الخاصية نفسها من ملف البيئة: AI_CIRCUIT_BREAKER_FAILURE_THRESHOLD وAI_CIRCUIT_BREAKER_OPEN_SECONDS وAI_CIRCUIT_BREAKER_ENABLED. مش من لوحة التحكم.' },
            { level: 'info', text: '"اختبار الاتصال" في إعدادات الذكاء الاصطناعي مش بيكتب صف هنا.' },
        ],
    },

    en: {
        summary: 'Each AI provider\'s state changes: healthy, degraded, paused or recovering. A new row is written only when the state changes, and the provider\'s latest row decides whether the system calls it or skips to the fallback. Read-only.',
        audience: 'Technical admin or platform manager. Needs "ai-provider-health.view" (view only).',
        purpose: [
            'See whether a provider works or fails before users complain.',
            'Rows are written by the circuit breaker (AiCircuitBreaker) after real provider calls from the assistant chat or internal tasks (AiTaskRunner: text, speech-to-text, images), only when the state changes: a healthy provider succeeding writes nothing, the first failure writes a degraded row, further failures update that same row (failure count), and reaching the threshold writes an unhealthy row.',
            'After 3 failures in a row (default) an unhealthy row is written and the provider is paused for 60 s (default); meanwhile requests go to the fallback without calling it.',
            'After the pause a recovering row (half-open) is written and requests try it again: the first success makes it healthy, the first failure pauses it again straight away (unhealthy row).',
        ],
        sections: [
            {
                title: 'Table (newest first)',
                items: [
                    { name: 'Provider', desc: 'The provider\'s name. Deleting a provider deletes its rows.' },
                    { name: 'Check type', desc: 'Always functional today (a real call). connectivity, latency and availability exist in the schema but nothing writes them.' },
                    { name: 'Health score', desc: '0 to 1. healthy = 1; degraded = 1 − (failures in a row ÷ threshold), e.g. 0.67 after 1 of 3; unhealthy = 0.' },
                    { name: 'Status', desc: 'Green healthy = working again (or never failed: no row at all). Yellow degraded = failing but under the threshold. Red unhealthy = threshold reached, paused. Blue recovering = the pause is over and requests are trying it again.' },
                    { name: 'Date', desc: 'When this state started (a degraded or unhealthy row is updated in place on each further failure).' },
                ],
            },
            {
                title: 'Below the table',
                items: [
                    { name: 'Pages and rows per page', desc: 'Move between pages and pick the page size. No search or provider filter.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Check a provider\'s current state',
                steps: [
                    'The table is newest first: find the first row for that provider. No rows at all: it never failed.',
                    'healthy: it works.',
                    'recovering: the pause is over and the system is trying it again.',
                    'degraded: errors started; check "Provider logs" for what kind.',
                    'unhealthy: paused for about a minute, requests go to the fallback; "Failovers" will show health_threshold rows.',
                ],
            },
            {
                title: 'A provider keeps flipping',
                steps: [
                    'healthy and unhealthy alternating often means the provider is unstable.',
                    'Open "AI settings" and run "Test connection" on it.',
                    'If it continues: disable the provider or move it down in the routing rules.',
                ],
            },
        ],
        affects: [
            { text: 'The screen changes nothing, but its rows are what make the system skip a paused provider in the assistant chat, internal tasks and the intent router.' },
            { route: 'admin.ai-failovers.index', text: 'Failovers: while a provider is unhealthy, requests move to the fallback with reason health_threshold.' },
            { route: 'admin.ai-provider-logs.index', text: 'Provider logs: the calls behind each state.' },
            { route: 'admin.ai-audit-events.index', text: 'Audit events: every skip of a paused provider records a "circuit breaker opened" event.' },
            { route: 'admin.ai-settings', text: 'AI settings: test the connection or disable the provider.' },
        ],
        warnings: [
            { level: 'info', text: 'Not a periodic check: rows are written only on real requests. A provider with no traffic gets no new rows, so its latest state can be old.' },
            { level: 'info', text: 'One row per state change only; rows older than the retention window are purged daily (ai:enforce-retention), except each provider\'s latest row, which is its current state.' },
            { level: 'info', text: 'Threshold (3), pause (60 s) and the on/off switch come from the environment: AI_CIRCUIT_BREAKER_FAILURE_THRESHOLD, AI_CIRCUIT_BREAKER_OPEN_SECONDS, AI_CIRCUIT_BREAKER_ENABLED — not from the panel.' },
            { level: 'info', text: '"Test connection" in AI settings does not write a row here.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-provider-health/index.vue',
            'Modules/AI/app/Http/Controllers/AiProviderHealthController.php',
            'Modules/AI/app/Services/AiProviderHealthService.php',
            'Modules/AI/app/Repositories/AiProviderHealthRepository.php',
            'Modules/AI/app/Http/Resources/AiProviderHealthResource.php',
            'Modules/AI/app/Models/AiProviderHealth.php',
            'Modules/AI/app/Services/AiCircuitBreaker.php',
            'Modules/AI/app/Services/AiChatService.php (dispatchWithFallback)',
            'Modules/AI/app/Services/AiTaskRunner.php',
            'Modules/AI/config/config.php (circuit_breaker)',
        ],
        api: [
            'GET /api/admin/v1/ai-provider-health (paginated, newest first; with provider)',
            'GET /api/admin/v1/ai-provider-health/{health}',
        ],
        tables: ['ai_provider_health', 'ai_providers'],
        permissions: ['ai-provider-health.view'],
        decisions: [
            {
                ar: 'النص الفرعي للشاشة في اللغات بيقول "فحوصات دورية" و"طبقة التوجيه بتبص عليها قبل ما تختار مزود"، بس مفيش فحص دوري، والـ AiRoutingEngine مش بيقرا الجدول. اللي بيقراه هو الـ circuit breaker وقت الإرسال، وبيبص على آخر حالة unhealthy بس. NEEDS-DECISION: نصحح النص؟',
                en: 'The locale subtitle says "periodic checks" that "the routing layer reads before picking a provider", but there is no periodic check and AiRoutingEngine never reads this table; only the circuit breaker reads it at dispatch time (latest unhealthy row only). NEEDS-DECISION: fix the wording?',
            },
            {
                ar: 'details (عدد الأخطاء ورا بعض وopen_until) بيرجع في الـ API بس الشاشة مش بتعرضه.',
                en: 'details (consecutive_failures, open_until) is returned by the API but not shown.',
            },
            {
                ar: 'بيكتب في الجدول AiChatService وAiTaskRunner بس (شات، تحويل فويس لنص، صور). الفيديو والـ embeddings وتحويل النص لصوت من مسارات تانية مش بتكتب صفوف. وكمان AiIntentRouterService بيقرا الحالة ويوقف موجّه النوايا لو المزود مقفول.',
                en: 'Only AiChatService and AiTaskRunner write here (chat, speech-to-text, images); video, embeddings and text-to-speech on other paths write no rows. AiIntentRouterService also reads the state and skips the intent router while the provider is paused.',
            },
        ],
    },
};
