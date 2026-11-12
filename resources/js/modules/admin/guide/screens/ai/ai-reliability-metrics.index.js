/**
 * Guide: AI reliability metrics (admin.ai-reliability-metrics.index).
 * Source of truth checked: views/ai-reliability-metrics/index.vue, Modules/AI/routes/admin.php
 * (admin/v1/ai-reliability-metrics), Http/Controllers/AiReliabilityMetricController.php,
 * Services/AiReliabilityMetricService.php, Repositories/AiReliabilityMetricRepository.php,
 * Http/Resources/AiReliabilityMetricResource.php, Models/AiReliabilityMetric.php,
 * database/migrations/2026_09_22_160300_create_ai_reliability_metrics_table.php;
 * writer Services/Monitoring/AiReliabilityAggregator.php via Console/Commands/AggregateAiReliabilityMetrics.php
 * (ai:aggregate-reliability, scheduled hourly in Providers/AIServiceProvider.php), source Services/AiGateway.php::logCall.
 */
export default {
    route: 'admin.ai-reliability-metrics.index',
    updated: '2026-11-11',

    ar: {
        summary: 'ملخص أداء كل مزود لكل ساعة: نسبة النجاح، ونسبة الفشل، وزمن الاستجابة p95. بيتحسب أوتوماتيك كل ساعة من سجلات المزودين. الشاشة للقراءة بس.',
        audience: 'المسؤول الفني أو مدير المنصة. محتاج صلاحية "ai-reliability-metrics.view" (عرض بس).',
        purpose: [
            'تديك صورة على كل ساعة بدل ما تقرا سجلات المزودين صف صف.',
            'أمر مجدول (ai:aggregate-reliability) بيشتغل كل ساعة (الدقيقة 5) ويحسب الساعة اللي فاتت من "سجلات المزودين": كل مكالمة حقيقية لمزود = صف هناك، والمكالمة الناجحة = من غير كود خطأ.',
            'لكل مزود بيتكتب صف لكل المهام (نوع المهمة "-")، وصف لكل نوع مهمة (intent) اتطلب في الساعة دي.',
            'ساعة مفيهاش مكالمات لمزود = مفيش صف له.',
        ],
        sections: [
            {
                title: 'الجدول (الأحدث فترة فوق)',
                items: [
                    { name: 'المزود', desc: 'المزود اللي المقياس بتاعه.' },
                    { name: 'نوع المهمة', desc: 'النية (intent) اللي المقياس محسوب عليها، زي الشات أو البرمجة. "-" يعني كل المهام مع بعض.' },
                    { name: 'الفترة', desc: 'من وقت لوقت: ساعة كاملة.' },
                    { name: 'نسبة النجاح', desc: 'نسبة المكالمات اللي نجحت في الساعة (%).' },
                    { name: 'نسبة الفشل', desc: 'نسبة المكالمات اللي فشلت (خطأ من المزود، أو مفيش اتصال) (%). المكالمات اللي الـ Circuit Breaker منعها مش بتتحسب لأنها ما اتبعتتش أصلًا.' },
                    { name: 'زمن الاستجابة p95', desc: '95% من المكالمات ردت في الوقت ده أو أقل (ملّي ثانية).' },
                ],
            },
            {
                title: 'أسفل الجدول',
                items: [
                    { name: 'الصفحات وعدد الصفوف', desc: 'تنقّل بين الصفحات وتختار كام صف في الصفحة. مفيش بحث ولا فلاتر.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'أعرف مزود بيتعب إمتى',
                steps: [
                    'دوّر على صفوف المزود ونسبة الفشل فيها.',
                    'ساعة نسبة فشلها عالية: افتح "سجلات المزودين" في نفس الوقت واقرا كود HTTP ورسالة الخطأ.',
                    'افتح "صحة المزودين" علشان تشوف لو الـ Circuit Breaker اتقفل في الوقت ده.',
                ],
            },
            {
                title: 'محتاج أحسب ساعات فاتت (مثلًا بعد ما السيرفر وقف)',
                steps: ['المسؤول الفني يشغّل: php artisan ai:aggregate-reliability --hours=24', 'الأمر بيعيد حساب آخر 24 ساعة، والساعة اللي اتحسبت قبل كده صفها بيتحدّث مش بيتكرر.'],
            },
        ],
        affects: [
            { text: 'الشاشة دي مش بتغيّر حاجة، ومفيش حاجة في النظام بتقرا الجدول ده في التوجيه.' },
            { route: 'admin.ai-provider-logs.index', text: 'سجلات المزودين: الأرقام الخام اللي المقاييس بتتحسب منها.' },
            { route: 'admin.ai-provider-health.index', text: 'صحة المزودين: الحالة اللحظية لكل مزود (Circuit Breaker).' },
            { route: 'admin.ai-failovers.index', text: 'الفيل أوفر: مرات الانتقال لمزود بديل.' },
        ],
        warnings: [
            { level: 'info', text: 'الأرقام بتظهر بعد ما الساعة تخلص (أول 5 دقايق من الساعة اللي بعدها)، ومحتاجة الـ scheduler شغال على السيرفر.' },
            { level: 'info', text: 'سجلات المزودين القديمة بتتمسح حسب مدة الاحتفاظ، بس المقاييس المحسوبة بتفضل (مفيهاش بيانات شخصية).' },
        ],
    },

    en: {
        summary: 'Each provider\'s performance per hour: success rate, error rate and p95 latency, computed automatically every hour from the provider logs. Read-only.',
        audience: 'Technical admin or platform manager. Needs "ai-reliability-metrics.view" (view only).',
        purpose: [
            'Summarises each hour instead of reading provider logs row by row.',
            'A scheduled command (ai:aggregate-reliability) runs every hour (minute 5) and computes the previous hour from "Provider logs": every real provider call is one row there, and a successful call has no error code.',
            'Per provider it writes one row for all intents (intent "-") and one row per intent requested in that hour.',
            'An hour with no calls to a provider has no row for it.',
        ],
        sections: [
            {
                title: 'Table (latest period first)',
                items: [
                    { name: 'Provider', desc: 'The provider measured.' },
                    { name: 'Intent', desc: 'The intent the metric covers, e.g. chat or coding. "-" means all intents together.' },
                    { name: 'Period', desc: 'From – to: one full hour.' },
                    { name: 'Success rate', desc: 'Share of calls that succeeded in the hour (%).' },
                    { name: 'Error rate', desc: 'Share of calls that failed (provider error or no connection) (%). Calls skipped by the circuit breaker are not counted — they were never sent.' },
                    { name: 'p95 latency', desc: '95% of calls answered within this time or less (ms).' },
                ],
            },
            {
                title: 'Below the table',
                items: [
                    { name: 'Pages and rows per page', desc: 'Move between pages and pick the page size. No search or filters.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Find when a provider struggles',
                steps: ['Look at the provider\'s rows and their error rate.', 'For a bad hour open "Provider logs" at that time and read the HTTP code and error message.', 'Open "Provider health" to see whether the circuit breaker opened then.'],
            },
            {
                title: 'Compute past hours (e.g. after the server was down)',
                steps: ['The technical admin runs: php artisan ai:aggregate-reliability --hours=24', 'It recomputes the last 24 hours; an hour computed before has its row updated, not duplicated.'],
            },
        ],
        affects: [
            { text: 'This screen changes nothing, and routing does not read this table.' },
            { route: 'admin.ai-provider-logs.index', text: 'Provider logs: the raw numbers these metrics are computed from.' },
            { route: 'admin.ai-provider-health.index', text: 'Provider health: each provider\'s live state (circuit breaker).' },
            { route: 'admin.ai-failovers.index', text: 'Failovers: switches to a fallback provider.' },
        ],
        warnings: [
            { level: 'info', text: 'Numbers appear after the hour ends (in the first 5 minutes of the next hour) and need the scheduler running on the server.' },
            { level: 'info', text: 'Old provider logs are purged by retention, but computed metrics stay (they hold no personal data).' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-reliability-metrics/index.vue',
            'Modules/AI/app/Http/Controllers/AiReliabilityMetricController.php',
            'Modules/AI/app/Services/AiReliabilityMetricService.php',
            'Modules/AI/app/Repositories/AiReliabilityMetricRepository.php',
            'Modules/AI/app/Http/Resources/AiReliabilityMetricResource.php',
            'Modules/AI/app/Models/AiReliabilityMetric.php',
            'Writer: Modules/AI/app/Services/Monitoring/AiReliabilityAggregator.php ← Console/Commands/AggregateAiReliabilityMetrics.php (ai:aggregate-reliability {--hours=1}, hourlyAt(5))',
        ],
        api: [
            'GET /api/admin/v1/ai-reliability-metrics (paginated, latest period_start first; with provider, intent, plan)',
            'GET /api/admin/v1/ai-reliability-metrics/{metric}',
        ],
        tables: ['ai_reliability_metrics', 'ai_provider_logs (source: error_code null = success, response_time_ms)', 'ai_requests (intent_id)', 'ai_providers', 'ai_intents', 'ai_plans'],
        permissions: ['ai-reliability-metrics.view'],
        decisions: [
            {
                ar: 'model_key وregion وplan بيفضلوا فاضيين: صف سجل المزود يعرف المزود بس، وموديل الطلب هو اللي رد في الآخر مش اللي جرّبته كل محاولة. Percentiles بطريقة nearest-rank.',
                en: 'model_key, region and plan stay null: a provider-log row only knows the provider, and the request\'s model is the one that finally answered, not each attempt\'s. Percentiles are nearest-rank.',
            },
            {
                ar: 'الـ API بيرجّع model_key وregion وplan وlatency_p50_ms وlatency_p99_ms، بس الشاشة مش بتعرضهم.',
                en: 'The API returns model_key, region, plan, latency_p50_ms and latency_p99_ms, which the screen does not show.',
            },
        ],
    },
};
