/**
 * Guide: AI provider logs (admin.ai-provider-logs.index).
 * Source of truth checked: views/ai-provider-logs/index.vue, Modules/AI/routes/admin.php (admin/v1/ai-provider-logs),
 * Http/Controllers/AiProviderLogController.php, Services/AiProviderLogService.php, Repositories/AiProviderLogRepository.php,
 * Http/Resources/AiProviderLogResource.php, Models/AiProviderLog.php, Services/AiGateway.php (logCall and its callers),
 * Console/Commands/EnforceAiDataRetention.php, Providers/AIServiceProvider.php (daily schedule),
 * database/migrations/2026_09_22_160000_create_ai_provider_logs_table.php.
 */
export default {
    route: 'admin.ai-provider-logs.index',
    updated: '2026-11-11',

    ar: {
        summary: 'سجل تقني بكل مكالمة فعلية النظام عملها لمزود ذكاء اصطناعي: مين المزود، خدت قد إيه، ونجحت ولا فشلت. الشاشة للقراءة بس.',
        audience: 'المسؤول الفني. محتاج صلاحية "ai-provider-logs.view" (عرض بس).',
        purpose: [
            'تعرف المزود بطيء ولا سريع، وبيفشل قد إيه.',
            'الصف بيكتبه AiGateway بعد كل مكالمة: شات، صور (توليد وتعديل)، بداية فيديو، embeddings، تحويل فويس لنص، تحويل نص لصوت، وجلسة الصوت المباشر.',
            'بيتكتب بس لو المكالمة مربوطة بطلب ذكاء اصطناعي (AiRequest). المكالمات اللي من غير طلب، زي "اختبار الاتصال"، مش بتتسجل.',
            'مفيش أي محتوى للرسايل هنا: لا السؤال ولا الرد. بس الوقت والحالة والخطأ.',
        ],
        sections: [
            {
                title: 'الجدول (الأحدث فوق)',
                items: [
                    { name: 'المزود', desc: 'المزود اللي اتكلم. "-" لو المزود اتمسح.' },
                    { name: 'معرف الربط', desc: 'correlation_id بتاع طلب الذكاء الاصطناعي. كل المكالمات اللي ليها نفس المعرف تبع نفس الطلب (مثلًا المحاولة الأولى والبديل).' },
                    { name: 'كود الرد', desc: 'كود HTTP اللي المزود رد بيه فعلًا. أخضر 2xx، أحمر 400 وأكتر (مثلًا 401 مفتاح غلط، 429 تجاوز حد المزود، 500 عطل عنده)، رمادي "-" لو مفيش رد HTTP أصلًا: مفيش اتصال، أو مفيش مفتاح، أو المزود مش بيدعم العملية.' },
                    { name: 'زمن الاستجابة', desc: 'المدة من أول ما النظام بعت لحد ما الرد رجع، بالملّي ثانية.' },
                    { name: 'الخطأ', desc: 'فاضي ("-") = المكالمة نجحت. provider_error = المزود فشل. نص رسالة الخطأ بيرجع في الـ API بس مش ظاهر في الجدول.' },
                    { name: 'التاريخ', desc: 'وقت المكالمة.' },
                ],
            },
            {
                title: 'أسفل الجدول',
                items: [
                    { name: 'الصفحات وعدد الصفوف', desc: 'تنقّل بين الصفحات وتختار كام صف في الصفحة. مفيش بحث ولا فلتر بالمزود أو بالخطأ.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'مزود بيفشل: أتأكد من السجلات',
                steps: [
                    'ابدأ من "صحة المزودين" أو "الفيل أوفر" علشان تعرف المزود والوقت.',
                    'هنا: دوّر على صفوف المزود ده حوالين نفس الوقت.',
                    'لو "الخطأ" = provider_error في صفوف كتير ورا بعض، المزود نفسه بيرفض أو مش بيرد.',
                    'لو الزمن عالي جدًا قبل الفشل، غالبًا timeout من المزود.',
                    'من "إعدادات الذكاء الاصطناعي" دوس "اختبار الاتصال" علشان تشوف رسالة الخطأ الحقيقية.',
                ],
            },
            {
                title: 'المساعد بطيء: مين السبب؟',
                steps: [
                    'قارن "زمن الاستجابة" بين المزودين في نفس الفترة.',
                    'لو مزود واحد أبطأ بكتير، غيّر ترتيبه في قواعد التوجيه أو غيّر الموديل بتاعه من الإعدادات.',
                ],
            },
        ],
        affects: [
            { text: 'الشاشة دي مش بتغيّر حاجة.' },
            { route: 'admin.ai-requests.index', text: 'طلبات الذكاء الاصطناعي: كل صف هنا تبع طلب من هناك (نفس correlation_id).' },
            { route: 'admin.ai-provider-health.index', text: 'صحة المزودين: الحالة اللي النظام استنتجها من نجاح أو فشل المكالمات دي.' },
            { route: 'admin.ai-failovers.index', text: 'الفيل أوفر: لما المكالمة تفشل وفيه بديل، الانتقال بيتسجل هناك.' },
            { route: 'admin.ai-data-policies.index', text: 'سياسات البيانات: مدة الاحتفاظ في سياسة البيانات الشخصية المفعّلة هي اللي بتحدد إمتى السجلات القديمة تتمسح.' },
        ],
        warnings: [
            { level: 'info', text: 'أمر ai:enforce-retention بيشتغل كل يوم وبيمسح السجلات الأقدم من مدة الاحتفاظ. لو مفيش سياسة مفعّلة بمدة، مفيش حاجة بتتمسح.' },
            { level: 'info', text: 'لو تسجيل الصف نفسه فشل، المكالمة بتكمل عادي واليوزر مش بيتأثر، بس الصف مش هيظهر هنا.' },
        ],
    },

    en: {
        summary: 'A technical log of every real call the system made to an AI provider: which provider, how long it took, and whether it worked. Read-only.',
        audience: 'Technical admin. Needs "ai-provider-logs.view" (view only).',
        purpose: [
            'See whether a provider is slow or fast and how often it fails.',
            'Written by AiGateway after every call: chat, image generate/edit, video start, embeddings, speech-to-text, text-to-speech and realtime voice sessions.',
            'Only written when the call belongs to an AI request (AiRequest); calls without one, such as "Test connection", are not logged.',
            'No message content is stored — neither prompt nor reply — only timing, status and error.',
        ],
        sections: [
            {
                title: 'Table (newest first)',
                items: [
                    { name: 'Provider', desc: 'The provider called. "-" if the provider was deleted.' },
                    { name: 'Correlation ID', desc: 'The AI request\'s correlation_id. Calls sharing it belong to the same request (e.g. first attempt and fallback).' },
                    { name: 'HTTP status', desc: 'The HTTP code the provider really answered with: green 2xx, red 400+ (e.g. 401 bad key, 429 provider rate limit, 500 provider fault), grey "-" when there was no HTTP answer at all: no connection, no key, or the provider does not support the call.' },
                    { name: 'Response time', desc: 'Milliseconds from sending to getting the answer back.' },
                    { name: 'Error', desc: 'Empty ("-") = success. provider_error = the provider failed. The error message text is in the API but not in the table.' },
                    { name: 'Date', desc: 'When the call happened.' },
                ],
            },
            {
                title: 'Below the table',
                items: [
                    { name: 'Pages and rows per page', desc: 'Move between pages and pick the page size. No search or provider/error filter.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'A provider is failing: check the logs',
                steps: [
                    'Start from "Provider health" or "Failovers" to get the provider and the time.',
                    'Here: find that provider\'s rows around that time.',
                    'Many provider_error rows in a row mean the provider itself is refusing or not answering.',
                    'A very high time before a failure usually means a provider timeout.',
                    'In "AI settings", run "Test connection" to see the real error message.',
                ],
            },
            {
                title: 'The assistant is slow: which provider?',
                steps: [
                    'Compare "Response time" across providers in the same period.',
                    'If one is much slower, move it down in the routing rules or change its model in settings.',
                ],
            },
        ],
        affects: [
            { text: 'This screen changes nothing.' },
            { route: 'admin.ai-requests.index', text: 'AI requests: each row here belongs to a request there (same correlation_id).' },
            { route: 'admin.ai-provider-health.index', text: 'Provider health: the state derived from these calls succeeding or failing.' },
            { route: 'admin.ai-failovers.index', text: 'Failovers: when a call fails and a fallback exists, the switch is recorded there.' },
            { route: 'admin.ai-data-policies.index', text: 'Data policies: the retention days of the active personal-data policy decide when old logs are deleted.' },
        ],
        warnings: [
            { level: 'info', text: 'ai:enforce-retention runs daily and deletes logs older than the retention days. With no active policy with days set, nothing is deleted.' },
            { level: 'info', text: 'If writing the row fails, the call continues and the user is not affected — the row just will not appear here.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-provider-logs/index.vue',
            'Modules/AI/app/Http/Controllers/AiProviderLogController.php',
            'Modules/AI/app/Services/AiProviderLogService.php',
            'Modules/AI/app/Repositories/AiProviderLogRepository.php',
            'Modules/AI/app/Http/Resources/AiProviderLogResource.php',
            'Modules/AI/app/Models/AiProviderLog.php',
            'Modules/AI/app/Services/AiGateway.php (logCall)',
            'Modules/AI/app/Console/Commands/EnforceAiDataRetention.php',
        ],
        api: [
            'GET /api/admin/v1/ai-provider-logs (paginated, newest first; with provider, request)',
            'GET /api/admin/v1/ai-provider-logs/{log}',
        ],
        tables: ['ai_provider_logs', 'ai_providers', 'ai_requests'],
        permissions: ['ai-provider-logs.view'],
        decisions: [
            {
                ar: 'http_status بيتاخد من آخر رد HTTP وصل للـ connector (AbstractHttpConnector::client() بـ withResponseMiddleware ثم lastHttpStatus()). error_code لسه provider_error أو فاضي بس، والتفاصيل في كود HTTP ورسالة الخطأ.',
                en: 'http_status comes from the last HTTP answer the connector received (AbstractHttpConnector::client() withResponseMiddleware, then lastHttpStatus()). error_code is still only provider_error or null; the detail is in the HTTP code and error message.',
            },
            {
                ar: 'الـ API بيرجّع error_message وrequest_id بس الشاشة مش بتعرضهم، وشاشة طلبات الذكاء الاصطناعي مش بتعرض correlation_id، فالربط بين الشاشتين بالوقت والمزود بس.',
                en: 'The API returns error_message and request_id but the screen shows neither, and the AI requests screen does not show correlation_id, so the two screens can only be matched by time and provider.',
            },
            {
                ar: 'مفيش بحث ولا فلتر (بالمزود أو معرف الربط أو الخطأ) في الشاشة.',
                en: 'No search or filter (provider, correlation ID, error) on the screen.',
            },
        ],
    },
};
