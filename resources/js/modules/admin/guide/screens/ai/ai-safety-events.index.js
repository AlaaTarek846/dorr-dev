/**
 * Guide: AI safety events (admin.ai-safety-events.index).
 * Source of truth checked: views/ai-safety-events/index.vue, Modules/AI/routes/admin.php (ai-safety-events),
 * Http/Controllers/AiSafetyEventController.php, Services/AiSafetyEventService.php,
 * Repositories/AiSafetyEventRepository.php, Http/Resources/AiSafetyEventResource.php, Models/AiSafetyRule.php (actions),
 * Services/AiSafetyGuard.php::evaluate, Services/AiChatService.php::logSafetyEvent, Services/AiTaskRunner.php::logSafetyEvent,
 * Services/AiAuditTrail.php, Console/Commands/EnforceAiDataRetention.php.
 */
export default {
    route: 'admin.ai-safety-events.index',
    updated: '2026-11-11',

    ar: {
        summary: 'سجل بكل مرة قاعدة أمان من "قواعد الأمان" اشتغلت على رسالة: اتمنعت، أو اتنضفت، أو اتعلّمت للمراجعة. من هنا تعرف مين بعت إيه، وأنهي قاعدة مسكته.',
        audience: 'مسؤول المحتوى أو الأمان. محتاج صلاحية "ai-safety-events.view" (عرض بس).',
        purpose: [
            'تراجع الرسايل اللي قواعد الأمان مسكتها (في شات دُرر AI وفي أدوات الـ AI زي الترجمة).',
            'تعرف لو قاعدة بتمسك رسايل عادية بالغلط (كلمة مفتاحية عامة أوي) علشان تعدّلها.',
            'تعرف ليه طلب حالته blocked في شاشة الطلبات.',
        ],
        sections: [
            {
                title: 'الجدول',
                items: [
                    { name: 'صاحب الطلب', desc: 'اللي بعت الرسالة، وتحته رقمه. لو الحساب من غير اسم بيظهر رقم تليفونه.' },
                    { name: 'السياسة', desc: 'سياسة الأمان اللي القاعدة تبعها (شاشة سياسات الأمان).' },
                    { name: 'القاعدة', desc: 'القاعدة اللي اتطابقت. القواعد بتتفحص بالأولوية، وأول قاعدة تتطابق هي اللي بتتسجل.' },
                    { name: 'الإجراء المتخذ', desc: 'block = الرسالة اتمنعت واليوزر خد رد "اتمنعت". sanitize = الكلمات اتشالت والرسالة كملت. review = الرسالة كملت عادي بس اتسجلت للمراجعة. allow وrequire_confirmation مش بيتسجلوا هنا.' },
                    { name: 'السبب', desc: 'أول 500 حرف من رسالة اليوزر بعد إخفاء البيانات الشخصية والأسرار (الإيميل والتليفون وأرقام البطاقات والمفاتيح بتتحط مكانها [email] و[phone]...)، زي نسخة الرسالة في سجل الطلبات. لو سياسة البيانات الشخصية قافلة «تقليل البيانات»، بيتحفظ النص زي ما هو.' },
                    { name: 'التاريخ', desc: 'وقت الحدث، الأحدث فوق.' },
                    { name: 'الترقيم', desc: 'رقم الصفحة وعدد الصفوف. مفيش بحث ولا فلتر بالإجراء.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'يوزر بيقول رسالته اتمنعت من غير سبب',
                steps: [
                    'دوّر على اسمه هنا في وقت الشكوى، وبص على "القاعدة" و"السبب".',
                    'لو الرسالة عادية والقاعدة مسكتها بالغلط: افتح "قواعد الأمان" وعدّل الكلمات المفتاحية أو الأولوية أو وقّف القاعدة.',
                    'ابعت رسالة تجربة بنفس الكلام وتأكد إنها بقت بتعدي.',
                ],
            },
            {
                title: 'مراجعة دورية للرسايل المعلّمة',
                steps: [
                    'قلّب في الصفحات وركّز على الصفوف review وsanitize.',
                    'لو نفس اليوزر متكرر برسايل مؤذية، فكّر توقفه من "التحكم في التجربة المجانية".',
                ],
            },
        ],
        affects: [
            { text: 'الشاشة للقراءة بس. اللي بيغيّر سلوك الأمان هو "قواعد الأمان" و"سياسات الأمان".' },
            { route: 'admin.ai-safety-rules.index', text: 'قواعد الأمان: عمود القاعدة، ومنها بتصلّح قاعدة بتمسك بالغلط.' },
            { route: 'admin.ai-safety-policies.index', text: 'سياسات الأمان: عمود السياسة.' },
            { route: 'admin.ai-requests.index', text: 'طلبات الذكاء الاصطناعي: الطلب اللي حالته blocked.' },
            { route: 'admin.ai-audit-events.index', text: 'سجل التدقيق: كل حدث هنا بيتسجل كمان هناك كـ safety_rule_triggered.' },
            { route: 'admin.ai-trial-control.index', text: 'التحكم في التجربة المجانية: لو قررت توقف يوزر.' },
        ],
        warnings: [
            { level: 'warning', text: 'عمود "السبب" فيه نص رسالة اليوزر الأصلي من غير إخفاء للبيانات الشخصية. اتعامل معاه كبيانات حساسة.' },
            { level: 'info', text: 'السجلات الأقدم من مدة الاحتفاظ في سياسة البيانات (personal) بتتمسح يوميًا.' },
            { level: 'info', text: 'لو مفيش قاعدة متفعّلة في سياسة متفعّلة، الشاشة هتفضل فاضية.' },
        ],
    },

    en: {
        summary: 'Log of every time a rule from "Safety rules" fired on a message: blocked, sanitized or flagged for review. Shows who sent what and which rule caught it.',
        audience: 'Content or safety owner. Needs "ai-safety-events.view" (view-only).',
        purpose: [
            'Review messages caught by safety rules (DORR AI chat and AI tools such as translate).',
            'Find a rule catching normal messages by mistake (too generic a keyword) and fix it.',
            'Explain why a request is "blocked" on AI requests.',
        ],
        sections: [
            {
                title: 'Table',
                items: [
                    { name: 'Request owner', desc: 'Who sent the message, id under it; the phone number for a nameless account.' },
                    { name: 'Policy', desc: 'The safety policy the rule belongs to.' },
                    { name: 'Rule', desc: 'The matched rule. Rules are checked by priority; the first match is logged.' },
                    { name: 'Action taken', desc: 'block = stopped, the user got the "blocked" reply. sanitize = keywords removed, message went on. review = went on, logged for review. allow and require_confirmation are never logged.' },
                    { name: 'Reason', desc: 'First 500 characters of the user’s message with personal data and secrets masked (emails, phones, card numbers, keys become [email], [phone]…), like the request log’s copy. When the personal-data policy turns minimization off, the text is kept as typed.' },
                    { name: 'Date', desc: 'Newest first.' },
                    { name: 'Pagination', desc: 'Page and rows per page. No search or action filter.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'A user says a message was blocked for no reason',
                steps: ['Find them here at that time; read Rule and Reason.', 'If the rule caught a normal message: open Safety rules and adjust keywords, priority, or switch it off.', 'Send the same text as a test and confirm it passes.'],
            },
            {
                title: 'Regular review of flagged messages',
                steps: ['Page through, focusing on review and sanitize rows.', 'A user repeatedly sending harmful messages can be blocked in Free-trial control.'],
            },
        ],
        affects: [
            { text: 'Read-only; behaviour is changed in Safety rules and Safety policies.' },
            { route: 'admin.ai-safety-rules.index', text: 'Safety rules: the Rule column; fix a rule that misfires.' },
            { route: 'admin.ai-safety-policies.index', text: 'Safety policies: the Policy column.' },
            { route: 'admin.ai-requests.index', text: 'AI requests: the request marked blocked.' },
            { route: 'admin.ai-audit-events.index', text: 'Audit trail: every event here is also logged as safety_rule_triggered.' },
            { route: 'admin.ai-trial-control.index', text: 'Free-trial control: to block a user.' },
        ],
        warnings: [
            { level: 'warning', text: 'The Reason column holds the user’s original message without personal-data redaction. Treat it as sensitive.' },
            { level: 'info', text: 'Rows older than the personal data policy retention are purged daily.' },
            { level: 'info', text: 'With no active rule in an active policy, this screen stays empty.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-safety-events/index.vue',
            'Modules/AI/app/Http/Controllers/AiSafetyEventController.php → Services/AiSafetyEventService.php',
            'Modules/AI/app/Repositories/AiSafetyEventRepository.php (with owner, policy, rule)',
            'Modules/AI/app/Http/Resources/AiSafetyEventResource.php',
            'Writers: Services/AiChatService.php::logSafetyEvent (with request_id), Services/AiTaskRunner.php::logSafetyEvent (no request_id)',
            'Modules/AI/app/Services/AiSafetyGuard.php::evaluate (keyword_match / risk_score, by priority)',
        ],
        api: ['GET /api/admin/v1/ai-safety-events (page, per_page)', 'GET /api/admin/v1/ai-safety-events/{safetyEvent} (not used by the screen)'],
        tables: ['ai_safety_events (owner morph, request_id, safety_policy_id, safety_rule_id, action_taken, reason)'],
        permissions: ['ai-safety-events.view (view-only group)'],
        decisions: [
            { ar: 'require_confirmation مش بيتسجل ومش بيعمل أي حاجة في الشات (الرسالة بتعدي عادي).', en: 'require_confirmation is neither logged nor enforced — the message passes as if allowed (NEEDS-DECISION).' },
            { ar: 'الـ API بيرجع request_id بس الشاشة مش بتعرضه، ومن الأدوات (AiTaskRunner) بيبقى فاضي لأن الطلب مش بيتفتح.', en: 'request_id is returned but not shown; for tools (AiTaskRunner) it is always null since no request row is opened.' },
        ],
    },
};
