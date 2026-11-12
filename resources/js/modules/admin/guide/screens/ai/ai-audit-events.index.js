/**
 * Guide: AI audit trail (admin.ai-audit-events.index).
 * Source of truth checked: views/ai-audit-events/index.vue, Modules/AI/routes/admin.php (ai-audit-events),
 * Http/Controllers/AiAuditEventController.php, Services/AiAuditEventService.php, Repositories/AiAuditEventRepository.php,
 * Http/Resources/AiAuditEventResource.php, Models/AiAuditEvent.php (append-only guards), Services/AiAuditTrail.php
 * (event types, record()), call sites in Services/AiChatService.php (export, erase, idempotent replay, circuit
 * breaker, safety rule), Services/AiTaskRunner.php (circuit breaker, safety rule), Services/AiRealtimeService.php.
 */
export default {
    route: 'admin.ai-audit-events.index',
    updated: '2026-11-11',

    ar: {
        summary: 'دفتر ثابت بالأحداث الحساسة في الـ AI: يوزر صدّر أو مسح بياناته، قاعدة أمان اشتغلت، طلب اتكرر، مزود اتقفل بالـ Circuit Breaker، جلسة صوت مباشرة اتفتحت. السجلات هنا مينفعش تتعدّل ولا تتمسح.',
        audience: 'مسؤول الأمان أو الامتثال أو المسؤول الفني. محتاج صلاحية "ai-audit-events.view" (عرض بس).',
        purpose: [
            'تثبت إمتى يوزر طلب تصدير أو مسح بياناته (للامتثال وحماية البيانات).',
            'تعرف إمتى مزود اتقفل مؤقتًا لأنه فشل كتير (Circuit Breaker).',
            'تتابع كل الأحداث الحساسة في مكان واحد، بالوقت والـ IP ومعرف التتبع.',
        ],
        sections: [
            {
                title: 'أنواع الأحداث',
                items: [
                    { name: 'data_exported', desc: 'صاحب الحساب صدّر محادثات المساعد بتاعته.' },
                    { name: 'data_erased', desc: 'صاحب الحساب مسح بيانات المساعد بتاعته. خطورته high.' },
                    { name: 'safety_rule_triggered', desc: 'قاعدة أمان اشتغلت (block أو sanitize أو review). نفس الحدث موجود بتفاصيله في "أحداث الأمان".' },
                    { name: 'idempotent_replay', desc: 'التطبيق بعت نفس الطلب تاني (نفس مفتاح التكرار)، فالنظام رجّع الرد القديم بدل ما يعيد ويحسب تاني.' },
                    { name: 'circuit_breaker_opened', desc: 'مزود فشل كذا مرة ورا بعض، فاتقفل مؤقتًا والطلبات راحت لغيره. خطورته high، والكيان المتأثر هو ai_provider ورقمه.' },
                    { name: 'realtime_session_created', desc: 'اتفتحت جلسة صوت مباشر مع المساعد.' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'الحدث', desc: 'نوع الحدث من القايمة اللي فوق.' },
                    { name: 'درجة الخطورة', desc: 'high وcritical بالأحمر، medium بالأصفر، والباقي (info) رمادي.' },
                    { name: 'صاحب الحدث', desc: 'اليوزر أو مقدم الخدمة أو الكابتن، وتحته رقمه. "-" لحدث نظام (زي الـ Circuit Breaker).' },
                    { name: 'من نفّذ الفعل', desc: 'مخصص لأدمن عمل الفعل نيابة عن حد. حاليًا دايمًا "-" لأن محدش بيبعته.' },
                    { name: 'الكيان المتأثر', desc: 'نوع الحاجة ورقمها، مثلًا ai_provider #2 أو ai_safety_rule #5 أو ai_request #120.' },
                    { name: 'معرف التتبع', desc: 'trace_id: نفس correlation_id بتاع الطلب، علشان تربط الحدث بطلب معيّن.' },
                    { name: 'عنوان IP', desc: 'الـ IP اللي جه منه الطلب وقت الحدث.' },
                    { name: 'التاريخ', desc: 'الأحدث فوق.' },
                    { name: 'الترقيم', desc: 'رقم الصفحة وعدد الصفوف. مفيش بحث ولا فلتر.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'إثبات إن يوزر مسح بياناته',
                steps: [
                    'دوّر على حدث data_erased باسم اليوزر.',
                    'خد التاريخ وعنوان الـ IP كدليل. السجل ده مش بيتعدّل ولا يتمسح.',
                ],
            },
            {
                title: 'مزود وقف فجأة',
                steps: [
                    'دوّر على circuit_breaker_opened في وقت المشكلة، وشوف رقم المزود في "الكيان المتأثر".',
                    'افتح "صحة المزودين" و"التحويلات" و"سجل المزودين" لنفس الوقت علشان تعرف سبب الفشل.',
                    'صلّح السبب (مفتاح، رصيد، موديل) من إعدادات الذكاء الاصطناعي.',
                ],
            },
            {
                title: 'ربط حدث بطلب',
                steps: [
                    'خد "معرف التتبع" من الحدث.',
                    'دوّر في شاشة الطلبات على نفس الوقت واليوزر (الشاشة مش بتعرض الـ trace_id، بس بيرجع من الـ API).',
                ],
            },
        ],
        affects: [
            { text: 'الشاشة للقراءة بس.' },
            { route: 'admin.ai-safety-events.index', text: 'أحداث الأمان: تفاصيل كل safety_rule_triggered.' },
            { route: 'admin.ai-provider-health.index', text: 'صحة المزودين: حالة المزود بعد الـ Circuit Breaker.' },
            { route: 'admin.ai-failovers.index', text: 'التحويلات: الطلبات اللي اتحولت لمزود تاني.' },
            { route: 'admin.ai-provider-logs.index', text: 'سجل المزودين: الأخطاء اللي قفلت المزود.' },
            { route: 'admin.ai-requests.index', text: 'طلبات الذكاء الاصطناعي: الربط بمعرف التتبع.' },
        ],
        warnings: [
            { level: 'info', text: 'السجلات append-only: الموديل بيرفض أي تعديل أو حذف، حتى من الأدمن.' },
            { level: 'info', text: 'تسجيل الحدث مش بيوقف العملية الأصلية: لو الحفظ فشل، العملية بتكمل والخطأ بيتسجل في لوج السيرفر بس.' },
            { level: 'info', text: 'الصفوف بتتمسح يوميًا بعد مدة الاحتفاظ في سياسة البيانات الشخصية (أو مدة خاصة لو المسؤول الفني حطها: AI_RETENTION_AUDIT_EVENTS_DAYS). المسح ده بس هو المسموح، مش تعديل أو حذف يدوي.' },
        ],
    },

    en: {
        summary: 'Fixed ledger of sensitive AI events: a user exported or erased their data, a safety rule fired, a request was replayed, a provider was tripped by the circuit breaker, a live voice session opened. Rows can never be edited or deleted.',
        audience: 'Security, compliance or technical owner. Needs "ai-audit-events.view" (view-only).',
        purpose: [
            'Prove when a user exported or erased their data (compliance, data protection).',
            'See when a provider was paused for repeated failures (circuit breaker).',
            'One place for all sensitive events with time, IP and trace id.',
        ],
        sections: [
            {
                title: 'Event types',
                items: [
                    { name: 'data_exported', desc: 'The account owner exported their assistant conversations.' },
                    { name: 'data_erased', desc: 'The account owner erased their assistant data; severity high.' },
                    { name: 'safety_rule_triggered', desc: 'A safety rule fired (block, sanitize, review); details on Safety events.' },
                    { name: 'idempotent_replay', desc: 'The app re-sent the same request (same idempotency key); the stored reply was returned instead of running again.' },
                    { name: 'circuit_breaker_opened', desc: 'A provider failed repeatedly and was paused; traffic moved on. Severity high, subject ai_provider #id.' },
                    { name: 'realtime_session_created', desc: 'A live voice session with the assistant was opened.' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Event', desc: 'One of the types above.' },
                    { name: 'Severity', desc: 'high/critical red, medium amber, others (info) grey.' },
                    { name: 'Owner', desc: 'User, provider or captain with id; "-" for system events (circuit breaker).' },
                    { name: 'Actor', desc: 'Meant for an admin acting for someone; always "-" today since nothing passes it.' },
                    { name: 'Subject', desc: 'Type and id, e.g. ai_provider #2, ai_safety_rule #5, ai_request #120.' },
                    { name: 'Trace ID', desc: 'The request’s correlation id, to link the event to a request.' },
                    { name: 'IP address', desc: 'IP of the HTTP request at the time.' },
                    { name: 'Date', desc: 'Newest first.' },
                    { name: 'Pagination', desc: 'Page and rows per page. No search or filter.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Prove a user erased their data',
                steps: ['Find the data_erased event for the user.', 'Use date and IP as evidence; the row cannot be changed or deleted.'],
            },
            {
                title: 'A provider stopped suddenly',
                steps: ['Find circuit_breaker_opened at that time; the subject shows the provider id.', 'Open Provider health, Failovers and Provider logs for the same time.', 'Fix the cause (key, balance, model) in AI settings.'],
            },
            {
                title: 'Link an event to a request',
                steps: ['Take the trace id.', 'On AI requests look at the same time and owner (the screen does not show trace id; the API returns it).'],
            },
        ],
        affects: [
            { text: 'Read-only.' },
            { route: 'admin.ai-safety-events.index', text: 'Safety events: details of each safety_rule_triggered.' },
            { route: 'admin.ai-provider-health.index', text: 'Provider health after a circuit-breaker trip.' },
            { route: 'admin.ai-failovers.index', text: 'Failovers: requests moved to another provider.' },
            { route: 'admin.ai-provider-logs.index', text: 'Provider logs: the errors that tripped the provider.' },
            { route: 'admin.ai-requests.index', text: 'AI requests: linked by trace id.' },
        ],
        warnings: [
            { level: 'info', text: 'Append-only: the model refuses any update or delete, even by an admin.' },
            { level: 'info', text: 'Recording never blocks the real action: if saving fails, the action goes on and the error only reaches the server log.' },
            { level: 'info', text: 'Rows are purged daily after the personal-data policy retention (or their own window when the server sets AI_RETENTION_AUDIT_EVENTS_DAYS). That purge is the only allowed removal — no manual edit or delete.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-audit-events/index.vue',
            'Modules/AI/app/Http/Controllers/AiAuditEventController.php → Services/AiAuditEventService.php',
            'Modules/AI/app/Repositories/AiAuditEventRepository.php (with owner, actor)',
            'Modules/AI/app/Http/Resources/AiAuditEventResource.php (metadata returned, not shown)',
            'Modules/AI/app/Models/AiAuditEvent.php (update()/delete() throw LogicException; no updated_at)',
            'Writer: Modules/AI/app/Services/AiAuditTrail.php::record — called from AiChatService, AiTaskRunner, AiRealtimeService',
            'Purge: Modules/AI/app/Console/Commands/EnforceAiDataRetention.php (query-builder delete, config ai.retention.audit_events_days, empty = policy window)',
        ],
        api: ['GET /api/admin/v1/ai-audit-events (page, per_page)', 'GET /api/admin/v1/ai-audit-events/{event} (not used by the screen)'],
        tables: ['ai_audit_events (owner morph, actor morph, event_type, severity, subject_type/subject_id, trace_id, ip_address, metadata JSON, created_at)'],
        permissions: ['ai-audit-events.view (view-only group)'],
        decisions: [
            { ar: 'مفيش أي مكان بيبعت actor، فعمود "من نفّذ الفعل" دايمًا فاضي.', en: 'No call site passes actor, so the Actor column is always empty (NEEDS-DECISION: record admin actors or drop the column).' },
            { ar: 'metadata (مثلًا action_taken) بترجع من الـ API بس الشاشة مش بتعرضها، ومفيش فلتر بنوع الحدث أو الخطورة.', en: 'metadata (e.g. action_taken) is returned but not shown; no filter by event type or severity.' },
            { ar: 'الحماية من التعديل والحذف عبارة عن override لـ update() وdelete() في الموديل بس؛ save() بعد تغيير قيمة، أو update/delete بالـ query builder، مش بيتمنعوا.', en: 'Immutability is only an override of the model’s update() and delete(); save() after changing an attribute, or query-builder update/delete, is not blocked.' },
        ],
    },
};
