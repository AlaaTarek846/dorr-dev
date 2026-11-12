/**
 * Guide: AI security events (admin.ai-security-events.index).
 * Source of truth checked: views/ai-security-events/index.vue, Modules/AI/routes/admin.php (ai-security-events),
 * Http/Controllers/AiSecurityEventController.php, Services/AiSecurityEventService.php,
 * Repositories/AiSecurityEventRepository.php, Http/Resources/AiSecurityEventResource.php, Models/AiSecurityEvent.php,
 * migration 2026_09_22_130300_create_ai_security_events_table.php, Console/Commands/EnforceAiDataRetention.php;
 * writer Services/Monitoring/AiSecurityEventRecorder.php, called from Providers/AIServiceProvider.php
 * (ai-chat-send / ai-chat-general limiters), Services/AiChatService.php (sendMessage, blocked account) and
 * Services/AiTaskRunner.php (ownerBlocked).
 */
export default {
    route: 'admin.ai-security-events.index',
    updated: '2026-11-11',

    ar: {
        summary: 'سجل قرارات الأمان اللي محرك الذكاء الاصطناعي بياخدها: طلبات اترفضت علشان عدّت حد السرعة، وحسابات ممنوعة من الـ AI حاولت تستخدمه.',
        audience: 'مسؤول الأمان. محتاج صلاحية "ai-security-events.view" (عرض بس).',
        purpose: [
            'تعرف مين بيضغط على النظام بطلبات كتير، ومين اتمنع وبيحاول تاني.',
            'دلوقتي بيتسجل نوعين: rate_limit_exceeded = طلبات شات الـ AI عدّت الحد (15 رسالة في الدقيقة للإرسال، 60 للباقي) واترفضت بـ 429؛ blocked_owner_attempt = حساب ممنوع في "تحكم التجربة" حاول يبعت رسالة أو يستخدم أداة AI.',
            'بيتكتب صف واحد بالكتير في الدقيقة لنفس الشخص ونفس النوع، علشان هجمة طلبات متملاش الجدول.',
            'قرارات المحتوى (قواعد الأمان) مش هنا، هي في "أحداث الأمان" و"فحوصات الأمان". الأحداث الحساسة التانية (تصدير ومسح بيانات، فتح Circuit Breaker) في "سجل التدقيق".',
        ],
        sections: [
            {
                title: 'الجدول',
                items: [
                    { name: 'صاحب الحدث', desc: 'اليوزر أو مقدم الخدمة أو السواق (الاسم، ولو مفيش اسم رقم التليفون). فاضي لو الطلب من غير حساب.' },
                    { name: 'نوع الحدث', desc: 'rate_limit_exceeded أو blocked_owner_attempt.' },
                    { name: 'الخطورة', desc: 'low (أخضر) لتجاوز الحد، medium (أصفر) لحساب ممنوع بيحاول. high وcritical مش بيتكتبوا دلوقتي.' },
                    { name: 'معرف الربط', desc: 'correlation_id: فاضي في النوعين دول، لأن الطلب بيترفض قبل ما يبقى له trace_id.' },
                    { name: 'الوصف', desc: 'جملة قصيرة: أنهي حد اترفض وعلى أنهي مسار، أو إن الحساب الممنوع حاول يستخدم الشات أو أداة. للأدمن بس.' },
                    { name: 'التاريخ', desc: 'الأحدث فوق.' },
                    { name: 'الترقيم', desc: 'رقم الصفحة وعدد الصفوف.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'التعامل مع شخص بيضغط على النظام',
                steps: [
                    'دوّر على صفوف rate_limit_exceeded متكررة لنفس الشخص.',
                    'لو شكله إساءة استخدام، افتح "تحكم التجربة" وامنعه.',
                    'بعد المنع، محاولاته هتظهر هنا كـ blocked_owner_attempt.',
                ],
            },
        ],
        affects: [
            { text: 'الشاشة للقراءة بس.' },
            { route: 'admin.ai-trial-control.index', text: 'تحكم التجربة: منع حساب من الـ AI هو اللي بيطلّع blocked_owner_attempt.' },
            { route: 'admin.ai-audit-events.index', text: 'سجل التدقيق: باقي الأحداث الحساسة.' },
            { route: 'admin.ai-data-policies.index', text: 'سياسات البيانات: مدة الاحتفاظ بتمسح الصفوف القديمة.' },
        ],
        warnings: [
            { level: 'info', text: 'صف واحد في الدقيقة لنفس الشخص ونفس النوع: عدد الصفوف مش هو عدد الطلبات المرفوضة.' },
            { level: 'info', text: 'السجلات الأقدم من مدة الاحتفاظ في سياسة البيانات (personal) بتتمسح يوميًا.' },
        ],
    },

    en: {
        summary: 'Log of the security decisions the AI engine takes: requests refused for going over the rate limit, and accounts blocked from AI that still try to use it.',
        audience: 'Security owner. Needs "ai-security-events.view" (view-only).',
        purpose: [
            'See who floods the system with requests and who keeps trying after being blocked.',
            'Two types are recorded today: rate_limit_exceeded = AI chat requests over the limit (15 sends a minute, 60 for the rest) refused with 429; blocked_owner_attempt = an account blocked in "Trial control" tried to send a message or use an AI tool.',
            'At most one row a minute per person and type, so a request flood does not fill the table.',
            'Content decisions (safety rules) are on "Safety events" and "Safety scans". Other sensitive events (data export/erase, circuit breaker opened) are on "Audit trail".',
        ],
        sections: [
            {
                title: 'Table',
                items: [
                    { name: 'Event owner', desc: 'The user, provider or driver (name, or phone when there is no name). Empty for an unauthenticated request.' },
                    { name: 'Event type', desc: 'rate_limit_exceeded or blocked_owner_attempt.' },
                    { name: 'Severity', desc: 'low (green) for a rate limit, medium (amber) for a blocked account trying. high and critical are not written today.' },
                    { name: 'Correlation ID', desc: 'Empty for these two types: the request is refused before it gets a trace id.' },
                    { name: 'Description', desc: 'One line: which limiter refused which path, or that the blocked account tried the chat or a tool. Admin only.' },
                    { name: 'Date', desc: 'Newest first.' },
                    { name: 'Pagination', desc: 'Page and rows per page.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Deal with someone flooding the system',
                steps: ['Look for repeated rate_limit_exceeded rows for the same person.', 'If it looks like abuse, open "Trial control" and block them.', 'After the block, their attempts show here as blocked_owner_attempt.'],
            },
        ],
        affects: [
            { text: 'Read-only.' },
            { route: 'admin.ai-trial-control.index', text: 'Trial control: blocking an account from AI is what produces blocked_owner_attempt.' },
            { route: 'admin.ai-audit-events.index', text: 'Audit trail: the other sensitive events.' },
            { route: 'admin.ai-data-policies.index', text: 'Data policies: the retention window purges old rows.' },
        ],
        warnings: [
            { level: 'info', text: 'One row a minute per person and type: the row count is not the number of refused requests.' },
            { level: 'info', text: 'Rows older than the personal data policy retention are purged daily.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-security-events/index.vue',
            'Modules/AI/app/Http/Controllers/AiSecurityEventController.php → Services/AiSecurityEventService.php',
            'Modules/AI/app/Repositories/AiSecurityEventRepository.php (with owner)',
            'Modules/AI/app/Http/Resources/AiSecurityEventResource.php (owner.phone on the admin API only), Models/AiSecurityEvent.php',
            'Writer: Modules/AI/app/Services/Monitoring/AiSecurityEventRecorder.php (Cache::add dedupe, 60 s per type + owner)',
            'Callers: Providers/AIServiceProvider.php::recordingRefusals() (limiter response callback, re-throws ThrottleRequestsException), AiChatService::sendMessage() (usage reason blocked), AiTaskRunner::ownerBlocked()',
            'Modules/AI/app/Console/Commands/EnforceAiDataRetention.php (purges this table)',
        ],
        api: ['GET /api/admin/v1/ai-security-events (page, per_page)', 'GET /api/admin/v1/ai-security-events/{securityEvent} (not used by the screen)'],
        tables: ['ai_security_events (owner morph, event_type, severity, correlation_id, description)'],
        permissions: ['ai-security-events.view (view-only group)'],
        decisions: [
            { ar: 'ai_security_policies لسه محدش بيقراها في المحرك، فمفيش أحداث "مخالفة سياسة". محاولة الوصول لمحادثة حد تاني بترجع 404 عادي ومش بتتسجل هنا.', en: 'ai_security_policies is still not read by the engine, so there are no "policy violation" events. Cross-owner access answers a plain 404 and is not logged here.' },
        ],
    },
};
