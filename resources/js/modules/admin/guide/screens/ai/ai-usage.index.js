/**
 * Guide: AI usage log (admin.ai-usage.index).
 * Source of truth checked: views/ai-usage/index.vue, Modules/AI/routes/admin.php (ai-usage),
 * Http/Controllers/AiUsageController.php, Services/AiUsageService.php, Repositories/AiUsageRepository.php,
 * Http/Resources/AiUsageResource.php, Models/AiUsage.php (types), migration create_ai_usage_table (cost defaults),
 * Services/AiChatService.php (AiUsage create, ~4 chars/token), Services/AiTaskRunner.php::closeRequest.
 */
export default {
    route: 'admin.ai-usage.index',
    updated: '2026-11-11',

    ar: {
        summary: 'سجل بعدد التوكنز لكل طلب AI خلص: كام توكن دخل وكام خرج، ومين صاحب الطلب. ده تقدير تقريبي، مش أرقام فواتير المزود.',
        audience: 'مدير المنصة أو المسؤول المالي اللي عايز يعرف مين بيستهلك أكتر. محتاج صلاحية "ai-usage.view" (عرض بس).',
        purpose: [
            'تعرف مين بيستخدم الـ AI أكتر وطلباته طويلة قد إيه.',
            'تلاقي يوزر بيستهلك بشكل غريب (طلبات كتير ورا بعض) قبل ما تقرر توقفه من "التحكم في التجربة المجانية".',
        ],
        sections: [
            {
                title: 'الجدول',
                items: [
                    { name: 'صاحب الاستهلاك', desc: 'صاحب الطلب (من الطلب نفسه)، وتحته رقمه. لو الحساب من غير اسم بيظهر رقم تليفونه.' },
                    { name: 'توكنز المدخلات', desc: 'تقدير: طول رسالة اليوزر بالحروف ÷ 4. مش بيحسب تاريخ المحادثة ولا التعليمات اللي بتتبعت معاها.' },
                    { name: 'توكنز المخرجات', desc: 'تقدير: طول الرد ÷ 4. بيبقى 0 لو الطلب فشل.' },
                    { name: 'الإجمالي', desc: 'المدخلات + المخرجات.' },
                    { name: 'التكلفة الإجمالية', desc: 'حاليًا دايمًا 0، لأن مفيش كود بيحسب التكلفة.' },
                    { name: 'نوع القيمة', desc: 'estimated (تقديري) في كل الصفوف دلوقتي. actual وadjustment موجودين في الكود بس محدش بيكتبهم.' },
                    { name: 'التاريخ', desc: 'وقت تسجيل الاستهلاك، الأحدث فوق.' },
                    { name: 'الترقيم', desc: 'رقم الصفحة وعدد الصفوف. مفيش بحث ولا فلتر ولا إجمالي.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'معرفة استهلاك يوزر معيّن',
                steps: [
                    'قلّب في الصفحات ودوّر على اسم اليوزر ورقمه (مفيش بحث).',
                    'عدد الصفوف = عدد طلباته اللي خلصت، والإجمالي بيديك فكرة عن طولها.',
                    'لو محتاج الدقايق اللي اتصرفت من باقته، افتح "جلسات الاستخدام" بدل الشاشة دي.',
                ],
            },
            {
                title: 'مقارنة باستهلاك المزود الحقيقي',
                steps: [
                    'متعتمدش على الأرقام هنا للفلوس: هي تقدير وبتقل عن الحقيقة.',
                    'افتح لوحة المزود نفسه (OpenAI أو Anthropic...) علشان الفاتورة الفعلية.',
                ],
            },
        ],
        affects: [
            { text: 'الشاشة للقراءة بس، ومش بتأثر على حدود الباقات ولا على المحفظة.' },
            { route: 'admin.ai-requests.index', text: 'طلبات الذكاء الاصطناعي: كل صف استهلاك تابع لطلب.' },
            { route: 'admin.ai-usage-sessions.index', text: 'جلسات الاستخدام: الدقايق اللي بتتحسب على باقة اليوزر فعلًا.' },
            { route: 'admin.ai-trial-control.index', text: 'التحكم في التجربة المجانية: لو قررت توقف يوزر بيسيء الاستخدام.' },
        ],
        warnings: [
            { level: 'warning', text: 'الأرقام تقديرية (حوالي 4 حروف = توكن واحد) والتكلفة دايمًا 0. متستخدمهاش في فوترة أو حساب أرباح.' },
            { level: 'info', text: 'الطلبات المحظورة، وطلبات الصور والفيديو، وردود الفرز الطبي السريع مش بيتعملها صف هنا.' },
            { level: 'info', text: 'حدود الباقة (الدقايق) بتتحسب في جلسات الاستخدام، مش من التوكنز دي.' },
        ],
    },

    en: {
        summary: 'Token count of every finished AI request: tokens in, tokens out, and the request owner. A rough estimate, not the provider’s billing figures.',
        audience: 'Platform owner or finance checking who uses the most. Needs "ai-usage.view" (view-only).',
        purpose: [
            'See who uses the AI most and how long their requests are.',
            'Spot unusual use (many requests in a row) before deciding to block someone in Free-trial control.',
        ],
        sections: [
            {
                title: 'Table',
                items: [
                    { name: 'Usage owner', desc: 'The request owner with the id under it; the phone number for a nameless account.' },
                    { name: 'Input tokens', desc: 'Estimate: user message length ÷ 4. History and system instructions are not counted.' },
                    { name: 'Output tokens', desc: 'Estimate: reply length ÷ 4; 0 when the request failed.' },
                    { name: 'Total', desc: 'Input + output.' },
                    { name: 'Total cost', desc: 'Always 0 today — nothing computes cost.' },
                    { name: 'Usage type', desc: 'estimated on every row today; actual and adjustment exist in code but are never written.' },
                    { name: 'Date', desc: 'When it was logged; newest first.' },
                    { name: 'Pagination', desc: 'Page and rows per page. No search, filter or totals.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'One user’s usage',
                steps: ['Page through and find the user’s name and id (no search).', 'Rows = finished requests; Total hints at their length.', 'For plan minutes used, open Usage sessions instead.'],
            },
            {
                title: 'Compare with the real provider bill',
                steps: ['Do not use these numbers for money: they are estimates and run low.', 'Open the provider’s own dashboard for the real bill.'],
            },
        ],
        affects: [
            { text: 'Read-only; does not touch plan limits or the wallet.' },
            { route: 'admin.ai-requests.index', text: 'AI requests: each usage row belongs to a request.' },
            { route: 'admin.ai-usage-sessions.index', text: 'Usage sessions: the minutes actually charged to the plan.' },
            { route: 'admin.ai-trial-control.index', text: 'Free-trial control: to block an abusive user.' },
        ],
        warnings: [
            { level: 'warning', text: 'Numbers are estimates (~4 characters per token) and cost is always 0. Do not use them for billing or margins.' },
            { level: 'info', text: 'Blocked requests, image/video requests and instant health-triage replies get no row.' },
            { level: 'info', text: 'Plan limits (minutes) are counted in Usage sessions, not from these tokens.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-usage/index.vue',
            'Modules/AI/app/Http/Controllers/AiUsageController.php → Services/AiUsageService.php',
            'Modules/AI/app/Repositories/AiUsageRepository.php (with request.owner)',
            'Modules/AI/app/Http/Resources/AiUsageResource.php (input/output/total cost as float)',
            'Writers: Services/AiChatService.php (TYPE_ESTIMATED), Services/AiTaskRunner.php::closeRequest',
        ],
        api: ['GET /api/admin/v1/ai-usage (page, per_page)', 'GET /api/admin/v1/ai-usage/{usage} (not used by the screen)'],
        tables: ['ai_usage (request_id → ai_requests cascade, input/output/total_tokens, input/output/total_cost default 0, usage_type)'],
        permissions: ['ai-usage.view (view-only group)'],
        decisions: [
            { ar: 'التكلفة عمرها ما بتتحسب (default 0) والنوع دايمًا estimated، بس عنوان الشاشة بيقول "التكلفة الفعلية" و"مصدر أي فوترة". يا نحسب التكلفة من أسعار الموديلات، يا نغيّر النص.', en: 'Cost is never computed (default 0) and type is always estimated, yet the subtitle says "actual cost … source for any billing". Compute cost from model prices or fix the wording (NEEDS-DECISION).' },
            { ar: 'توكنز المدخلات بتتحسب من رسالة اليوزر بس، مش من كل اللي اتبعت للمزود (التاريخ والتعليمات)، فالرقم أقل بكتير من الحقيقة.', en: 'Input tokens count only the user message, not the full payload sent (history, system prompt), so they run far below reality.' },
            { ar: 'مفيش فلتر بالمصدر (شات ولا أداة) ولا إجماليات ولا بحث باليوزر.', en: 'No source filter (chat vs tool), totals or owner search.' },
        ],
    },
};
