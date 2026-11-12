/**
 * Guide: reported freelance chat messages (admin.freelance.messageReports).
 * Source of truth checked: views/freelance/message-reports/index.vue, Modules/Freelance/routes/admin.php (freelance-message-reports),
 * Modules/Freelance/app/Http/Controllers/Admin/FreelanceMessageReportController.php (index, file, decide),
 * Modules/Freelance/app/Services/Chat/MessageReports.php (REASONS, report, decide), locales freelance.messageReports.
 */
export default {
    route: 'admin.freelance.messageReports',
    updated: '2026-11-11',

    ar: {
        summary: 'رسايل الشات اللي عميل أو محترف بلّغ عنها. بتشوف النص زي ما كان وقت البلاغ، والطرفين والسبب، وبتقرر: تجاهل أو تأكيد (وممكن تخفي الرسالة).',
        audience: 'فريق الثقة والأمان. الفتح محتاج "الرسائل المبلغ عنها: عرض"، والقرار محتاج "تعديل".',
        purpose: [
            'تراجع البلاغات المفتوحة الأول (الترتيب: المفتوح فوق، والأحدث الأول).',
            'تخفي رسالة مخالفة من الشات.',
            'تحتفظ بسجل للقرار وملاحظته.',
        ],
        sections: [
            {
                title: 'الفلاتر',
                items: [
                    { name: 'مفتوح / مؤكَّد / متجاهَل', desc: 'كل زرار بيعرض البلاغات في الحالة دي، وجنبه العدد الكلي ليها.' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'الرسالة', desc: 'نص الرسالة وقت البلاغ (حتى لو الراسل عدّلها أو مسحها بعد كده). لو فيها ملف، بيتفتح النسخة المحفوظة مع البلاغ. "مخفية من الشات" لو الرسالة مش ظاهرة دلوقتي.' },
                    { name: 'السبب', desc: 'ألفاظ مسيئة، تحرش، تهديد أو ابتزاز، مشاركة بيانات تواصل، رسائل مزعجة، أو سبب تاني، وملاحظة المبلّغ لو كتبها.' },
                    { name: 'الأطراف', desc: 'المبلّغ والمبلَّغ عنه، وكل واحد عميل ولا محترف.' },
                    { name: 'التاريخ', desc: 'وقت البلاغ.' },
                    { name: 'القرار', desc: 'زرار "راجع" للبلاغ المفتوح (لو معاك صلاحية التعديل)، أو نتيجة القرار وملاحظته لو اتقرر.' },
                ],
            },
            {
                title: 'نافذة القرار',
                items: [
                    { name: 'تجاهل (مفيش مشكلة)', desc: 'البلاغ بيتقفل كمتجاهَل.' },
                    { name: 'تأكيد (مخالفة)', desc: 'البلاغ بيتقفل كمؤكَّد.' },
                    { name: 'اخفي الرسالة من الشات', desc: 'بيظهر مع التأكيد بس. بيخفي الرسالة من المحادثة عند الطرفين لو لسه ظاهرة.' },
                    { name: 'ملاحظة', desc: 'اختياري، لحد 500 حرف، بتظهر جنب القرار.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'مراجعة بلاغ',
                steps: [
                    'خليك على فلتر "مفتوح" ودوس "راجع" في الصف.',
                    'اقرا النص وافتح الملف لو موجود.',
                    'اختار تجاهل أو تأكيد، ولو مخالفة واضحة علّم "اخفي الرسالة".',
                    'اكتب ملاحظة قصيرة ودوس احفظ.',
                ],
            },
        ],
        affects: [
            { text: 'شات الشغل الحر في تطبيق العميل والمحترف: الرسالة المخفية بتختفي من المحادثة.' },
            { route: 'admin.freelance.sanctions', text: 'القرار هنا معلومة بس ومش بيقيّد أي حساب. التحذير أو التقييد قرار منفصل من الجزاءات.' },
        ],
        warnings: [
            { level: 'warning', text: 'القرار نهائي: البلاغ المقفول مينفعش يتقرر تاني.' },
            { level: 'info', text: 'نفس الشخص مينفعش يبلّغ عن نفس الرسالة مرتين، ورسايل النظام ورسايلك أنت مينفعش تتبلّغ.' },
            { level: 'info', text: 'كل قرار بيتسجل في سجل المراجعة.' },
        ],
    },

    en: {
        summary: 'Freelance chat messages a client or freelancer reported. You see the text as it was when reported, both sides and the reason, and decide: dismiss or uphold (and optionally hide the message).',
        audience: 'The trust & safety team. Opening needs "Reported messages: view"; deciding needs update.',
        purpose: [
            'Review open reports first (open on top, newest first).',
            'Hide an offending message from the chat.',
            'Keep a record of the decision and its note.',
        ],
        sections: [
            {
                title: 'Filters',
                items: [
                    { name: 'Open / Upheld / Dismissed', desc: 'Each button lists the reports in that status, with its total count.' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Message', desc: 'The text as it was when reported (even if the sender later edited or removed it). An attached file opens from the copy kept with the report. "Hidden" when the message is no longer shown.' },
                    { name: 'Reason', desc: 'Abuse, harassment, threat/blackmail, sharing contact info, spam, or other — plus the reporter\'s note.' },
                    { name: 'People', desc: 'Who reported and who was reported, each client or freelancer.' },
                    { name: 'When', desc: 'Time of the report.' },
                    { name: 'Decision', desc: '"Review" on an open report (with update permission), or the outcome and its note once decided.' },
                ],
            },
            {
                title: 'Decision window',
                items: [
                    { name: 'Dismiss (no problem)', desc: 'Closes the report as dismissed.' },
                    { name: 'Uphold (violation)', desc: 'Closes the report as upheld.' },
                    { name: 'Hide the message', desc: 'Only with uphold. Hides the message from the conversation for both sides if it is still shown.' },
                    { name: 'Note', desc: 'Optional, up to 500 characters, shown next to the decision.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Review a report',
                steps: [
                    'Stay on "Open" and click "Review" on the row.',
                    'Read the text and open the file if any.',
                    'Choose dismiss or uphold; for a clear violation tick "Hide the message".',
                    'Write a short note and save.',
                ],
            },
        ],
        affects: [
            { text: 'Freelance chat in the client and freelancer apps: a hidden message disappears from the conversation.' },
            { route: 'admin.freelance.sanctions', text: 'The decision is information only and restricts no account. Warnings and limits are a separate decision in Sanctions.' },
        ],
        warnings: [
            { level: 'warning', text: 'Decisions are final: a closed report cannot be decided again.' },
            { level: 'info', text: 'A person cannot report the same message twice, nor system messages or their own.' },
            { level: 'info', text: 'Every decision is written to the audit log.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/freelance/message-reports/index.vue',
            'Modules/Freelance/app/Http/Controllers/Admin/FreelanceMessageReportController.php',
            'Modules/Freelance/app/Services/Chat/MessageReports.php (REASONS, report, decide)', 'Modules/Freelance/app/Models/FreelanceMessageReport.php',
        ],
        api: [
            'GET  /api/admin/v1/freelance-message-reports   (?status, reason, per_page) + pagination.counts',
            'GET  /api/admin/v1/freelance-message-reports/{uuid}/file   (the evidence copy)',
            'POST /api/admin/v1/freelance-message-reports/{uuid}/decide {decision: dismiss|uphold, hide_message?, note?}',
        ],
        tables: ['freelance_message_reports (+ media collection "evidence")', 'freelance_messages'],
        permissions: ['freelance-message-reports.view (index, file)', 'freelance-message-reports.update (decide)'],
        decisions: [],
    },
};
