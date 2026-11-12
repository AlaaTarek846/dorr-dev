/**
 * Guide: AI requests log (admin.ai-requests.index).
 * Source of truth checked: views/ai-requests/index.vue, utils/aiOwner.js, Modules/AI/routes/admin.php (ai-requests),
 * Http/Controllers/AiRequestController.php, Services/AiRequestService.php, Repositories/AiRequestRepository.php
 * (source filter), Http/Resources/AiRequestResource.php, Models/AiRequest.php, Services/AiChatService.php (request
 * row: create / blocked / completed / failed), Services/AiTaskRunner.php (openRequest / closeRequest, `source`),
 * Console/Commands/EnforceAiDataRetention.php.
 */
export default {
    route: 'admin.ai-requests.index',
    updated: '2026-11-11',

    ar: {
        summary: 'سجل بكل طلب دخل نظام الذكاء الاصطناعي: رسالة في شات دُرر AI، أو أداة AI من موديول تاني (ترجمة، تلخيص، ردود مقترحة، تحويل فويس لنص، Discover...). كل صف = طلب واحد، مين بعته، راح لأنهي مزود، وخلص على إيه.',
        audience: 'المسؤول الفني أو الدعم اللي بيحقق في شكوى "المساعد مش بيرد". محتاج صلاحية "ai-requests.view" (الشاشة عرض بس).',
        purpose: [
            'تعرف كل اللي بيحصل في الـ AI: مين بيستخدم، وأنهي مزود بيرد، وكام طلب فشل أو اتمنع.',
            'نقطة البداية لأي تحقيق: من هنا تعرف رقم الطلب، وبعدين تروح للرد والاستهلاك والتحقق وسجل المزود بنفس الرقم.',
            'تفرق بين شات دُرر AI وبين أدوات الـ AI في باقي المنصة بفلتر "المصدر".',
        ],
        sections: [
            {
                title: 'شريط الأدوات',
                items: [
                    { name: 'فلتر المصدر', desc: '"كل المصادر"، أو "شات دُرر AI" (الطلبات اللي المصدر بتاعها فاضي)، أو "أدوات الـ AI" (أي طلب ليه مصدر زي chat.translate). تغيير الفلتر بيرجعك للصفحة الأولى.' },
                    { name: 'الترتيب', desc: 'الأحدث فوق دايمًا. مفيش بحث ولا فلتر بالحالة أو باليوزر.' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'صاحب الطلب', desc: 'اسم اليوزر أو مقدم الخدمة أو الكابتن، وتحته رقمه. لو الحساب من غير اسم بيظهر رقم تليفونه.' },
                    { name: 'المصدر', desc: 'فاضي = شات دُرر AI (بيظهر بالأزرق). غير كده اسم الأداة اللي طلبت، مثلًا chat.translate أو discover.ask.' },
                    { name: 'الهدف', desc: 'الـ Intent اللي محرك التوجيه اختاره للرسالة (من شاشة الأهداف). "-" لو مفيش هدف اتطابق.' },
                    { name: 'المزود', desc: 'المزود اللي رد فعلًا. لو الأول فشل والنظام حوّل لمزود تاني (Fallback)، اللي بيظهر هو اللي رد في الآخر.' },
                    { name: 'الحالة', desc: 'processing = لسه شغال (أو اتقطع في النص)، completed = اترد عليه، failed = المزود فشل، blocked = قاعدة أمان منعته. دي الحالات الوحيدة اللي النظام بيكتبها. الطلب الفاشل أو الممنوع بيتكتب له كمان كود خطأ (error_code) في الـ API: provider_error، circuit_open، safety_blocked، image_quota، request_failed.' },
                    { name: 'عدد المحاولات', desc: 'retry_count: كام مرة الطلب اتنقل لمزود بديل بعد ما المزود اللي قبله فشل (كل مرة = صف في "الفيل أوفر"). 0 = المزود الأول رد أو مفيش بديل.' },
                    { name: 'التاريخ', desc: 'وقت ما الطلب اتسجل، بتوقيت جهازك.' },
                    { name: 'الترقيم', desc: 'تحت الجدول: رقم الصفحة وعدد الصفوف في الصفحة.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'يوزر بيشتكي إن المساعد مش بيرد',
                steps: [
                    'خلي الفلتر على "شات دُرر AI" ودوّر على اسم اليوزر في أول الصفحات حسب وقت الشكوى.',
                    'بص على الحالة: failed يبقى المزود فشل، blocked يبقى قاعدة أمان منعت الرسالة، processing لمدة طويلة يبقى الطلب اتقطع.',
                    'لو failed: افتح "سجل المزودين" و"التحويلات" وشوف الخطأ في نفس الوقت، وراجع إعدادات المزود.',
                    'لو blocked: افتح "أحداث الأمان" وهتلاقي القاعدة اللي منعته في نفس الوقت.',
                    'لو مش لاقي أي صف لليوزر: الطلب اتوقف قبل ما يتسجل (مفيش باقة، أو دقايقه خلصت، أو مفيش مزود متفعّل). راجع اشتراكاته وجلسات الاستخدام.',
                ],
            },
            {
                title: 'متابعة أداة AI في موديول تاني (مثلًا الترجمة في الشات)',
                steps: [
                    'اختار "أدوات الـ AI" في فلتر المصدر.',
                    'عمود المصدر بيقولك أنهي أداة. بص على عدد failed وأنهي مزود بيرد.',
                    'لو الأداة اتمنعت بقاعدة أمان مش هتلاقي ليها صف هنا خالص، هتلاقيها في "أحداث الأمان" بس.',
                ],
            },
            {
                title: 'تتبع طلب واحد لآخره',
                steps: [
                    'خد رقم الطلب من هنا (أو من شاشة الردود، عمود "رقم الطلب").',
                    'في "ردود الذكاء الاصطناعي" دوّر على نفس الرقم علشان تشوف الرد أو رسالة الخطأ.',
                    'في "الاستهلاك" شوف التوكنز، وفي "التحقق" شوف هل الإجابة اتراجعت، وفي "تنفيذات الكود" لو كان سؤال برمجة.',
                ],
            },
        ],
        affects: [
            { text: 'الشاشة للقراءة بس، مش بتغيّر أي حاجة.' },
            { route: 'admin.ai-responses.index', text: 'ردود الذكاء الاصطناعي: رد كل طلب مربوط برقمه.' },
            { route: 'admin.ai-usage.index', text: 'الاستهلاك: صف توكنز لكل طلب خلص.' },
            { route: 'admin.ai-verifications.index', text: 'التحقق: مراجعات إجابات الشات لنفس الطلب.' },
            { route: 'admin.ai-code-executions.index', text: 'تنفيذات الكود: الكود اللي اتجرب للطلب ده.' },
            { route: 'admin.ai-provider-logs.index', text: 'سجل المزودين: كل نداء فعلي للمزود وخطؤه.' },
            { route: 'admin.ai-failovers.index', text: 'التحويلات: لما مزود فشل واتحول الطلب لغيره.' },
            { route: 'admin.ai-safety-events.index', text: 'أحداث الأمان: سبب أي طلب blocked.' },
            { route: 'admin.ai-intents.index', text: 'الأهداف: مصدر عمود "الهدف".' },
        ],
        warnings: [
            { level: 'warning', text: 'نص الرسالة (prompt) ورسالة الخطأ بيرجعوا من الـ API بس مش ظاهرين في الجدول. علشان تعرف سبب الفشل لازم تروح لشاشة الردود أو سجل المزودين.' },
            { level: 'info', text: 'نص الرسالة بيتحفظ بعد إخفاء البيانات الشخصية والأسرار، إلا لو سياسة البيانات (personal) قفلت الـ minimization.' },
            { level: 'info', text: 'الطلبات الأقدم من مدة الاحتفاظ في سياسة البيانات بتتمسح يوميًا (ai:enforce-retention)، ومعاها الردود والاستهلاك والتحقق بتوعها.' },
        ],
    },

    en: {
        summary: 'Log of every request that entered the AI system: a DORR AI chat message, or an AI tool in another module (translate, summary, smart replies, voice to text, Discover…). One row = one request: who sent it, which provider answered, how it ended.',
        audience: 'Technical owner or support investigating "the assistant does not answer". Needs "ai-requests.view" (view-only screen).',
        purpose: [
            'See all AI traffic: who uses it, which provider answers, how many failed or were blocked.',
            'Starting point of any investigation: take the request id here, then follow it to its response, usage, verification and provider log.',
            'Separate the DORR AI chat from AI tools elsewhere with the "Source" filter.',
        ],
        sections: [
            {
                title: 'Toolbar',
                items: [
                    { name: 'Source filter', desc: '"All sources", "DORR AI chat" (rows with no source) or "AI tools" (any row with a source such as chat.translate). Changing it goes back to page 1.' },
                    { name: 'Order', desc: 'Newest first. No search, no status or owner filter.' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Request owner', desc: 'User, provider or captain name with the id under it; the phone number for an account without a name.' },
                    { name: 'Source', desc: 'Empty = DORR AI chat (blue badge); otherwise the tool that asked, e.g. chat.translate, discover.ask.' },
                    { name: 'Intent', desc: 'The intent the routing engine matched (Intents screen); "-" when none.' },
                    { name: 'Provider', desc: 'The provider that finally answered (after any fallback).' },
                    { name: 'Status', desc: 'processing = still running (or cut off), completed, failed = provider failed, blocked = a safety rule stopped it. These are the only statuses the system writes. A failed or blocked request also gets an error_code in the API: provider_error, circuit_open, safety_blocked, image_quota, request_failed.' },
                    { name: 'Retry count', desc: 'retry_count: how many times the request moved to a fallback provider after the previous one failed (each one is a row in "Failovers"). 0 = the first provider answered or there was no fallback.' },
                    { name: 'Date', desc: 'When the request was logged, in your local time.' },
                    { name: 'Pagination', desc: 'Page and rows per page under the table.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'A user says the assistant does not answer',
                steps: [
                    'Set the filter to "DORR AI chat" and look for the user near the time of the complaint.',
                    'Check the status: failed = provider error, blocked = safety rule, long "processing" = cut off.',
                    'Failed: open Provider logs and Failovers at the same time and check the provider settings.',
                    'Blocked: open Safety events — the rule that blocked it is there at the same time.',
                    'No row at all: the request stopped before logging (no plan, minutes used up, no active provider). Check subscriptions and usage sessions.',
                ],
            },
            {
                title: 'Follow an AI tool in another module (e.g. chat translate)',
                steps: ['Choose "AI tools" in the source filter.', 'The Source column names the tool; watch failed rows and the provider.', 'A tool call blocked by a safety rule has no row here — only a Safety event.'],
            },
            {
                title: 'Trace one request end to end',
                steps: ['Take the request id here.', 'AI responses: same id → the reply or the error message.', 'Usage for tokens, Verifications for the fact-check, Code executions for a coding question.'],
            },
        ],
        affects: [
            { text: 'Read-only; changes nothing.' },
            { route: 'admin.ai-responses.index', text: 'AI responses: each request’s reply, by request id.' },
            { route: 'admin.ai-usage.index', text: 'Usage: one token row per finished request.' },
            { route: 'admin.ai-verifications.index', text: 'Verifications of chat answers for the same request.' },
            { route: 'admin.ai-code-executions.index', text: 'Code executions run for the request.' },
            { route: 'admin.ai-provider-logs.index', text: 'Provider logs: every real provider call and its error.' },
            { route: 'admin.ai-failovers.index', text: 'Failovers: a provider failed and the request moved on.' },
            { route: 'admin.ai-safety-events.index', text: 'Safety events: why a request is blocked.' },
            { route: 'admin.ai-intents.index', text: 'Intents: source of the Intent column.' },
        ],
        warnings: [
            { level: 'warning', text: 'The prompt and error message come back from the API but are not shown in the table; for the failure reason go to Responses or Provider logs.' },
            { level: 'info', text: 'The prompt is stored with personal data and secrets redacted, unless the personal data policy turns minimization off.' },
            { level: 'info', text: 'Requests older than the data policy retention are purged daily (ai:enforce-retention), with their responses, usage and verifications.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-requests/index.vue',
            'resources/js/utils/aiOwner.js (ownerDisplayName: name || phone || "-")',
            'Modules/AI/app/Http/Controllers/AiRequestController.php → Services/AiRequestService.php (BaseService::list)',
            'Modules/AI/app/Repositories/AiRequestRepository.php (with owner/gateway/intent/provider/response/usage/citations; source filter)',
            'Modules/AI/app/Http/Resources/AiRequestResource.php',
            'Writers: Modules/AI/app/Services/AiChatService.php (chat), Services/AiTaskRunner.php (tools, `source`)',
        ],
        api: [
            'GET /api/admin/v1/ai-requests            (page, per_page, source = ai_chat | tools | <exact source>)',
            'GET /api/admin/v1/ai-requests/{aiRequest} (not used by the screen)',
        ],
        tables: ['ai_requests (owner morph, gateway_id, intent_id, provider_id, source, model_key, prompt, correlation_id, idempotency_key, retry_count, error_code, error_message, status)', 'cascade → ai_responses, ai_usage, ai_verifications, ai_request_citations'],
        permissions: ['ai-requests.view (route admin.screen:ai-requests; view-only group)'],
        decisions: [
            { ar: 'retry_count بيزيد في dispatchWithFallback (AiChatService) وdispatch (AiTaskRunner) مع كل فيل أوفر، وerror_code بيتكتب من AiRequest::failureCode(). الحالات pending/retrying/cancelled اتشالت من ألوان الشاشة لأن محدش بيكتبها (الثوابت لسه في الموديل).', en: 'retry_count is incremented on every failover in dispatchWithFallback (AiChatService) and dispatch (AiTaskRunner); error_code comes from AiRequest::failureCode(). pending/retrying/cancelled were removed from the screen\'s badge map since nothing writes them (constants kept on the model).' },
            { ar: 'الـ API بيرجع prompt وerror_message وmodel_key وcorrelation_id (trace_id) والرد والاستهلاك والمصادر، بس الشاشة مفيهاش نافذة تفاصيل ولا بحث بالـ trace_id أو باليوزر، فالتحقيق صعب.', en: 'The API returns prompt, error_message, model_key, correlation_id (trace_id), response, usage and citations, but the screen has no details modal and no search by trace_id or owner (NEEDS-DECISION).' },
            { ar: 'نوع صاحب الطلب (user/provider/driver) بيرجع من الـ API بس مش ظاهر، فممكن رقمين متشابهين لنوعين مختلفين.', en: 'Owner type (user/provider/driver) is returned but not shown; the same id can belong to different owner types.' },
            { ar: 'طلب أداة اتمنع بقاعدة أمان (AiTaskRunner) مش بيتسجل كصف هنا خالص، عكس الشات اللي بيتسجل blocked.', en: 'A tool call blocked by safety (AiTaskRunner) gets no ai_requests row, unlike the chat which logs it as blocked.' },
        ],
    },
};
