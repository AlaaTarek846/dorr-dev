/**
 * Guide: AI failovers (admin.ai-failovers.index).
 * Source of truth checked: views/ai-failovers/index.vue, Modules/AI/routes/admin.php (admin/v1/ai-failovers),
 * Http/Controllers/AiFailoverController.php, Services/AiFailoverService.php, Repositories/AiFailoverRepository.php,
 * Http/Resources/AiFailoverResource.php, Models/AiFailover.php, Services/AiChatService.php (dispatchWithFallback),
 * Services/AiTaskRunner.php (fallback loop), Services/AiCircuitBreaker.php, Console/Commands/EnforceAiDataRetention.php,
 * database/migrations/2026_09_22_160200_create_ai_failovers_table.php, Support/AiAdminScreens.php.
 */
export default {
    route: 'admin.ai-failovers.index',
    updated: '2026-11-10',

    ar: {
        summary: 'سجل بكل مرة المزود اللي اتختار للطلب فشل، والنظام نقل الطلب لوحده للمزود اللي بعده في القايمة. الشاشة للقراءة بس.',
        audience: 'المسؤول الفني أو مدير المنصة. محتاج صلاحية "ai-failovers.view" (عرض بس، مفيش إضافة ولا تعديل ولا حذف).',
        purpose: [
            'تعرف أي مزود بيفشل كتير ومين اللي بيشيل الشغل بداله.',
            'تفرّق بين فشل حقيقي من المزود (provider_error) وبين إن النظام قفل المزود مؤقتًا علشان فشل كذا مرة ورا بعض (health_threshold).',
            'الصف بيتكتب بس لو فيه مزود تاني بعده في قايمة التوجيه، والسياسة مسموح فيها بالبديل. لو مفيش بديل، الطلب بيفشل ومفيش صف هنا.',
            'بيتكتب من شات المساعد (AiChatService) ومن المهام الداخلية زي كبسولة السياق (AiTaskRunner).',
        ],
        sections: [
            {
                title: 'الجدول (الأحدث فوق)',
                items: [
                    { name: 'المزود الأساسي', desc: 'المزود اللي فشل في المحاولة دي. "-" لو المزود اتمسح بعد كده.' },
                    { name: 'المزود البديل', desc: 'المزود اللي الطلب اتنقل له بعدها. ده مش معناه إنه نجح، ممكن يفشل هو كمان ويتكتب له صف تاني.' },
                    { name: 'السبب', desc: 'provider_error = المزود رجّع خطأ أو مردّش. health_threshold = المزود كان مقفول مؤقتًا (الـ circuit breaker مفتوح) فالنظام عدّاه من غير ما يكلمه. القيم timeout وunavailable وrate_limit موجودة في الجدول بس الكود دلوقتي مش بيكتبها.' },
                    { name: 'رقم المحاولة', desc: 'ترتيب المزود ده في قايمة المرشحين للطلب: 1 = المزود الأول، 2 = البديل الأول اللي فشل هو كمان، وهكذا.' },
                    { name: 'التاريخ', desc: 'وقت ما النظام قرر ينقل الطلب.' },
                ],
            },
            {
                title: 'أسفل الجدول',
                items: [
                    { name: 'الصفحات وعدد الصفوف', desc: 'تنقّل بين الصفحات وتختار كام صف في الصفحة. مفيش بحث ولا فلاتر في الشاشة دي.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'مزود بيفشل كتير: أعرف السبب إزاي؟',
                steps: [
                    'هنا: شوف المزود اللي اسمه متكرر في "المزود الأساسي" والسبب جنبه.',
                    'لو السبب health_threshold: افتح "صحة المزودين" هتلاقي آخر حالة للمزود unhealthy، يعني اتقفل مؤقتًا (60 ثانية افتراضيًا) بعد 3 أخطاء ورا بعض.',
                    'افتح "سجلات المزودين" وشوف نفس المزود في نفس الوقت: زمن الاستجابة والخطأ.',
                    'افتح "إعدادات الذكاء الاصطناعي" ودوس "اختبار الاتصال" على المزود: لو فشل يبقى غالبًا المفتاح أو الرصيد أو الموديل.',
                    'لو المشكلة هتطول: وقّف المزود أو غيّر ترتيبه في قواعد التوجيه علشان الطلبات تروح للبديل من الأول.',
                ],
            },
            {
                title: 'أتأكد إن البديل شغال',
                steps: [
                    'لو نفس الطلب ليه صفين (محاولة 1 ثم 2) في نفس الثانية تقريبًا، يبقى البديل الأول فشل هو كمان.',
                    'شوف "صحة المزودين" للمزود البديل: لو آخر حالة healthy يبقى هو اللي رد.',
                ],
            },
        ],
        affects: [
            { text: 'الشاشة دي مش بتغيّر حاجة. هي بتعرض قرارات النظام بس.' },
            { route: 'admin.ai-provider-health.index', text: 'صحة المزودين: الفشل نفسه بيتسجل هناك، ومنها النظام بيعرف المزود مقفول ولا لأ.' },
            { route: 'admin.ai-provider-logs.index', text: 'سجلات المزودين: تفاصيل كل مكالمة فعلية للمزود (الوقت والخطأ).' },
            { route: 'admin.ai-audit-events.index', text: 'أحداث التدقيق: كل مرة النظام عدّى مزود مقفول بيتسجل حدث "circuit breaker opened".' },
            { route: 'admin.ai-routing-rules.index', text: 'قواعد التوجيه: هي اللي بتحدد ترتيب المزودين، يعني مين الأساسي ومين البديل.' },
            { route: 'admin.ai-routing-policies.index', text: 'سياسات التوجيه: لو البديل مقفول في السياسة، مفيش انتقال ومفيش صفوف هنا.' },
            { route: 'admin.ai-settings', text: 'إعدادات الذكاء الاصطناعي: علشان تختبر المزود أو توقفه.' },
        ],
        warnings: [
            { level: 'info', text: 'صفوف كتير هنا مش معناها إن اليوزر شاف خطأ. لو البديل رد، اليوزر خد رده عادي.' },
            { level: 'warning', text: 'لو الشاشة فاضية والمستخدمين بيشتكوا من أخطاء، غالبًا مفيش مزود بديل متفعّل، أو البديل مقفول في سياسة التوجيه.' },
            { level: 'info', text: 'أمر الاحتفاظ بالبيانات (ai:enforce-retention) بيشتغل كل يوم وبيمسح الصفوف الأقدم من مدة الاحتفاظ في سياسة البيانات الشخصية المفعّلة.' },
        ],
    },

    en: {
        summary: 'A log of every time the provider picked for a request failed and the system moved the request to the next provider in the list on its own. Read-only.',
        audience: 'Technical admin or platform manager. Needs "ai-failovers.view" (view only; no create, edit or delete).',
        purpose: [
            'See which provider fails often and which one picks up its work.',
            'Tell a real provider failure (provider_error) apart from the system skipping a provider it has paused after repeated failures (health_threshold).',
            'A row is written only when there is another candidate after it and the routing policy allows fallback. With no fallback the request just fails and nothing is logged here.',
            'Written by the assistant chat (AiChatService) and by internal tasks such as the context capsule (AiTaskRunner).',
        ],
        sections: [
            {
                title: 'Table (newest first)',
                items: [
                    { name: 'Primary provider', desc: 'The provider that failed on this attempt. "-" if the provider was deleted later.' },
                    { name: 'Fallback provider', desc: 'The provider the request moved to next. It may fail too and get its own row.' },
                    { name: 'Reason', desc: 'provider_error = the provider returned an error or no answer. health_threshold = the provider was paused (circuit breaker open), so it was skipped without a call. timeout, unavailable and rate_limit exist in the schema but are not written by the current code.' },
                    { name: 'Attempt number', desc: 'This provider\'s position in the request\'s candidate list: 1 = the first provider, 2 = the first fallback that also failed, and so on.' },
                    { name: 'Date', desc: 'When the system decided to move the request.' },
                ],
            },
            {
                title: 'Below the table',
                items: [
                    { name: 'Pages and rows per page', desc: 'Move between pages and pick the page size. There is no search or filter on this screen.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'A provider keeps failing: find out why',
                steps: [
                    'Here: find the provider that keeps appearing as "Primary provider" and its reason.',
                    'If the reason is health_threshold: open "Provider health"; its latest state will be unhealthy, i.e. paused (60 s by default) after 3 failures in a row.',
                    'Open "Provider logs" for the same provider and time: response time and error.',
                    'Open "AI settings" and run "Test connection" on the provider: a failure usually means key, balance or model.',
                    'If it will last: disable the provider or reorder the routing rules so requests go to the fallback first.',
                ],
            },
            {
                title: 'Check the fallback works',
                steps: [
                    'Two rows for one request (attempt 1 then 2) at almost the same second mean the first fallback failed too.',
                    'Check "Provider health" for the fallback: a latest healthy state means it answered.',
                ],
            },
        ],
        affects: [
            { text: 'This screen changes nothing; it only shows the system\'s decisions.' },
            { route: 'admin.ai-provider-health.index', text: 'Provider health: the failure itself is recorded there, and it decides whether a provider is paused.' },
            { route: 'admin.ai-provider-logs.index', text: 'Provider logs: details of each real call to the provider (time and error).' },
            { route: 'admin.ai-audit-events.index', text: 'Audit events: every skip of a paused provider records a "circuit breaker opened" event.' },
            { route: 'admin.ai-routing-rules.index', text: 'Routing rules: they set the provider order, i.e. who is primary and who is the fallback.' },
            { route: 'admin.ai-routing-policies.index', text: 'Routing policies: with fallback disabled there is no switch and no rows here.' },
            { route: 'admin.ai-settings', text: 'AI settings: test or disable the provider.' },
        ],
        warnings: [
            { level: 'info', text: 'Many rows do not mean users saw errors: if the fallback answered, the user got a normal reply.' },
            { level: 'warning', text: 'An empty screen while users report errors usually means there is no enabled fallback provider, or fallback is disabled in the routing policy.' },
            { level: 'info', text: 'The daily ai:enforce-retention command deletes rows older than the retention days of the active personal-data policy.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-failovers/index.vue',
            'Modules/AI/app/Http/Controllers/AiFailoverController.php',
            'Modules/AI/app/Services/AiFailoverService.php',
            'Modules/AI/app/Repositories/AiFailoverRepository.php',
            'Modules/AI/app/Http/Resources/AiFailoverResource.php',
            'Modules/AI/app/Models/AiFailover.php',
            'Modules/AI/app/Services/AiChatService.php (dispatchWithFallback)',
            'Modules/AI/app/Services/AiTaskRunner.php',
            'Modules/AI/app/Services/AiCircuitBreaker.php',
            'Modules/AI/app/Console/Commands/EnforceAiDataRetention.php',
        ],
        api: [
            'GET /api/admin/v1/ai-failovers (paginated, newest first; with primaryProvider, fallbackProvider, request)',
            'GET /api/admin/v1/ai-failovers/{failover}',
        ],
        tables: ['ai_failovers', 'ai_providers', 'ai_requests'],
        permissions: ['ai-failovers.view'],
        decisions: [
            {
                ar: 'الـ API بيرجّع reason (رسالة الخطأ) وrequest_id، بس الشاشة مش بتعرضهم، فالأدمن مش شايف تفاصيل الفشل ولا يقدر يربط الصف بالطلب. NEEDS-DECISION: نضيف عمود أو نافذة تفاصيل؟',
                en: 'The API returns reason (the error message) and request_id but the screen shows neither, so the admin cannot see the failure detail or link the row to its request. NEEDS-DECISION: add a column or a details modal?',
            },
            {
                ar: 'trigger_type بيتكتب بقيمتين بس (provider_error وhealth_threshold). timeout وunavailable وrate_limit متعرّفين في الموديل ومش مستخدمين. مش bug، بس الخطأ مش بيتصنف.',
                en: 'Only two trigger_type values are written (provider_error, health_threshold); timeout, unavailable and rate_limit are defined but unused. Not a bug, but failures are not classified further.',
            },
            {
                ar: 'AiTaskRunner مش بيكتب صف لو الطلب من غير AiRequest، فبعض المهام الداخلية ممكن تنتقل لبديل من غير ما يظهر هنا.',
                en: 'AiTaskRunner skips the row when there is no AiRequest, so some internal tasks can fail over without appearing here.',
            },
            {
                ar: 'مفيش بحث ولا فلتر بالمزود أو السبب أو التاريخ في الشاشة.',
                en: 'No search or filter by provider, reason or date on the screen.',
            },
        ],
    },
};
