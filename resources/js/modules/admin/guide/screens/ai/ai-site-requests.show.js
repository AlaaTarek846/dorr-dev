/**
 * Guide: one human-assisted site request (admin.ai-site-requests.show).
 * Source of truth checked: views/ai-site-requests/show.vue, QuoteBuilder.vue, RequestChat.vue,
 * Modules/AI/routes/admin.php (admin/v1/ai-site-requests/{id}/*), Http/Controllers/AiSiteServiceRequestAdminController.php
 * (show, quote, changeStatus, milestone, releaseInstallment, extend, update, messages, sendMessage, file),
 * Services/Sites/AiSiteServiceRequestService.php (sendQuote, accept, pay, changeStatus, setMilestoneStatus,
 * releaseInstallment, extend, updateInternal, release, settleIfHandedOver, present), Models/AiSiteRequestStatus.php,
 * app/Http/Middleware/AdminScreenPermission.php.
 */
export default {
    route: 'admin.ai-site-requests.show',
    updated: '2026-11-11',

    ar: {
        summary: 'صفحة طلب واحد من طلبات "موقع بتدخل بشري": بتقرا بيانات العميل، وتبعت عرض السعر بالتسليمات والدفعات، وبعد ما يوافق ويدفع بتتابع التنفيذ والدفعات والتسليم، وبتكلم العميل في نفس الصفحة.',
        audience: 'الفريق اللي بينفذ المواقع. الفتح محتاج "مواقع بتدخل بشري: عرض". تغيير الحالة محتاج "تغيير الحالة". العرض والتسليمات والدفعات والموعد والرسايل والملاحظات محتاجة "تعديل".',
        purpose: [
            'تفهم العميل عايز إيه من بياناته ومرفقاته.',
            'تبعت عرض سعر فيه مدة التنفيذ والتسليمات وتقسيم الدفعات.',
            'بعد الموافقة: تعلّم التسليمات، وتفعّل الدفعات، وتغيّر الحالة، وتمد الموعد لو احتاج.',
            'تسلّم رابط الموقع للعميل، وده مش بيظهر له غير بعد ما يدفع كل الدفعات.',
        ],
        sections: [
            {
                title: 'رأس الصفحة',
                items: [
                    { name: 'العنوان والرقم المرجعي والحالة', desc: 'اسم المشروع وكود الطلب وشارة الحالة الحالية.' },
                    { name: 'الوقت المتبقي للتسليم', desc: 'عداد بالأيام والساعات والدقايق لحد موعد التسليم (بيتحسب من ساعة الجهاز). بيبقى أحمر وبعلامة سالب لو الموعد عدّى. بيختفي لما الطلب يخلص (مكتمل / مرفوض / ملغي).' },
                ],
            },
            {
                title: 'ملخص الدفعات',
                items: [
                    { name: 'السعر / المدفوع / المتبقي', desc: 'مجموع كل الدفعات، واللي اتدفع منها، والباقي. بيظهر بعد أول عرض.' },
                    { name: 'سبب رفض العرض', desc: 'لو العميل رفض العرض وكتب سبب.' },
                ],
            },
            {
                title: 'عرض السعر (بيظهر في: تم الاستلام، قيد المراجعة، تم إرسال العرض، رفض العرض)',
                items: [
                    { name: 'السعر', desc: 'إجباري، 1 أو أكتر، بعملة العميل اللي اتحددت وقت الطلب من محفظته.' },
                    { name: 'المدة (أيام)', desc: 'من 1 لـ 730. العداد بيبدأ من لحظة موافقة العميل.' },
                    { name: 'صلاحية العرض (أيام)', desc: 'من 1 لـ 90، بتبدأ بـ 7. بعدها العميل مش هيقدر يوافق.' },
                    { name: 'ملاحظات للعميل', desc: 'اختيارية، لحد 3000 حرف.' },
                    { name: 'جدول الاستلامات', desc: 'اختياري، لحد 20 تسليمة. لكل واحدة اسم وتفاصيل ورقم اليوم من بداية التنفيذ.' },
                    { name: 'الدفعات', desc: 'من 1 لـ 10 دفعات، لكل دفعة اسم ومبلغ وإمتى تستحق: عند الموافقة، أو عند تسليم تسليمة معينة، أو عند التسليم النهائي. لازم دفعة واحدة على الأقل "عند الموافقة".' },
                    { name: 'تقسيم 40 / 30 / 30', desc: 'يعمل الدفعات لوحده: 40% عند الموافقة، و30% عند أول تسليمة (لو فيه تسليمات)، والباقي عند التسليم النهائي.' },
                    { name: 'الموزّع', desc: 'مجموع الدفعات لازم يساوي السعر بالظبط، وإلا زرار الإرسال بيفضل مقفول.' },
                    { name: 'إرسال العرض / تحديث العرض وإعادة إرساله', desc: 'بيمسح التسليمات والدفعات القديمة ويحط الجديدة، والحالة بتبقى "تم إرسال العرض"، والعميل بيوصله إشعار.' },
                ],
            },
            {
                title: 'خطة التسليم (بعد الموافقة)',
                items: [
                    { name: 'كل تسليمة', desc: 'اسمها وتفاصيلها وتاريخها المتوقع، وقايمة: لم تبدأ / جاري العمل / تم التسليم.' },
                    { name: 'تم التسليم', desc: 'بيبعت للعميل إشعار، وبيفعّل الدفعة المربوطة بالتسليمة دي (بتبقى "مستحقة").' },
                ],
            },
            {
                title: 'الدفعات',
                items: [
                    { name: 'الحالة', desc: '"لم تستحق بعد"، أو "مستحقة" (العميل يقدر يدفعها من المحفظة)، أو "مدفوعة" بتاريخ الدفع.' },
                    { name: 'فعّل الدفعة الآن', desc: 'يخلّي دفعة لسه ما استحقتش "مستحقة" دلوقتي، من غير ما تستنى التسليمة. العميل بيوصله إشعار.' },
                ],
            },
            {
                title: 'بيانات العميل',
                items: [
                    { name: 'الحقول', desc: 'اسم النشاط، نوع الموقع، نبذة، الجمهور، الأهداف، الصفحات، المميزات، اللغات، ملاحظات التصميم، الألوان، مواقع مرجعية، الشعار، الدومين، المحتوى، الميزانية، المدة المطلوبة، وبيانات التواصل. الحقل الفاضي مش بيظهر.' },
                    { name: 'المرفقات', desc: 'لحد 6 ملفات العميل رفعها. دوس على الملف ينزل.' },
                ],
            },
            {
                title: 'العمود الجانبي',
                items: [
                    { name: 'الحالة + ملاحظة للعميل + تطبيق', desc: 'تغيير حر لأي حالة مفعّلة، والملاحظة بتتكتب في المحادثة. العميل بيوصله إشعار. "تم التسليم" بيفعّل الدفعة الأخيرة، و"مكتمل" محتاج كل الدفعات مدفوعة. "مرفوض" أو "ملغي" لطلب العميل دفع فيه بيرجّع كل اللي اندفع لمحفظته تلقائيًا (رصيد للصرف بس) مرة واحدة، والدفعات بتبقى "اترجعت"، وبيوصله إشعار ورسالة في المحادثة. لو اخترت الحالتين دول والعميل دافع، بيظهر تحذير بالمبلغ قبل التطبيق.' },
                    { name: 'تغيير موعد التسليم', desc: 'بعد الموافقة بس، ولطلب لسه ما خلصش. الموعد الجديد لازم يبقى في المستقبل (بيتحط 23:59 من اليوم اللي اخترته). العميل بيوصله إشعار.' },
                    { name: 'المحادثة', desc: 'رسايلك ورسايل العميل ورسايل النظام (تغيير حالة، دفعة مستحقة، استرجاع...). فتحها بيعلّم رسايل العميل إنها اتقرت. Ctrl+Enter للإرسال، لحد 2000 حرف، والعميل بيوصله إشعار. بتتحدث لحظيًا: أي رسالة جديدة على الطلب بتوصل كحدث real-time فالمحادثة (وبيانات الطلب لو اتغيرت) بتتقري مرة واحدة، ولو الاتصال وقع ورجع بتتقري مرة للتعويض. مفيش سؤال للسيرفر كل شوية.' },
                    { name: 'رابط الموقع المسلَّم وملاحظات الاستلام', desc: 'العميل مش بيشوفهم غير لما الطلب يبقى مكتمل أو متسلّم ومدفوع بالكامل.' },
                    { name: 'ملاحظات داخلية', desc: 'للفريق بس، العميل عمره ما بيشوفها.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'إرسال عرض سعر',
                steps: [
                    'اقرا بيانات العميل والمرفقات.',
                    'غيّر الحالة لـ "قيد المراجعة" لو حابب تعرّف العميل إنك شغال على طلبه.',
                    'في "عرض السعر" اكتب السعر والمدة وصلاحية العرض.',
                    'ضيف التسليمات (اختياري) ثم دوس "تقسيم 40 / 30 / 30" أو ضيف الدفعات بإيدك لحد ما "الموزّع" يساوي السعر.',
                    'دوس "إرسال العرض".',
                ],
            },
            {
                title: 'تنفيذ الطلب بعد موافقة العميل',
                steps: [
                    'لما العميل يوافق، دفعات "عند الموافقة" بتتخصم من محفظته مرة واحدة، والحالة بتبقى "جاري التنفيذ"، والعداد بيبدأ.',
                    'مع كل تسليمة غيّر حالتها لـ "تم التسليم" علشان الدفعة المربوطة بيها تستحق.',
                    'حط رابط الموقع وملاحظات الاستلام ودوس حفظ.',
                    'غيّر الحالة لـ "تم التسليم". الدفعة الأخيرة بتستحق، ولما العميل يدفعها الطلب بيبقى "مكتمل" لوحده ويشوف الرابط.',
                ],
            },
            {
                title: 'العميل طلب وقت أكتر أو حصل تأخير',
                steps: [
                    'في "تغيير موعد التسليم" اختار التاريخ الجديد ودوس تطبيق.',
                    'اكتب للعميل في المحادثة السبب.',
                ],
            },
        ],
        affects: [
            { text: 'تطبيق العميل: العرض والتسليمات والدفعات المستحقة والحالة والعداد والمحادثة. كل خطوة تقريبًا بتبعت له إشعار.' },
            { text: 'محفظة العميل: الدفعات بتتخصم منها لما يوافق أو يدفع دفعة مستحقة (بعملة محفظته في بلده).' },
            { route: 'admin.ai-site-requests.index', text: 'الصندوق: الحالة والعرض والموعد وعدد الرسايل اللي متقريتش.' },
        ],
        warnings: [
            { level: 'danger', text: 'تغيير الحالة لـ "مرفوض" أو "ملغي" بيقفل الطلب نهائيًا وبيرجّع للعميل كل الدفعات اللي دفعها (لمحفظته، رصيد للصرف بس). ده مش بيترجع فيه.' },
            { level: 'warning', text: 'إعادة إرسال العرض بتمسح كل التسليمات والدفعات القديمة. ده مسموح بس قبل موافقة العميل.' },
            { level: 'warning', text: '"جاري التنفيذ" و"تم التسليم" و"مكتمل" مش هيتطبقوا لو العميل لسه ما وافقش على العرض، و"مكتمل" مش هيتطبق لو فيه دفعة لسه ما اتدفعتش.' },
            { level: 'warning', text: 'بعد ما الطلب يخلص (مكتمل / مرفوض / ملغي) مينفعش تغيّر حالته تاني ولا تسليماته ولا موعده.' },
            { level: 'info', text: 'لو العرض انتهت صلاحيته، العميل مش هيقدر يوافق. ابعت العرض تاني بصلاحية جديدة.' },
            { level: 'info', text: 'لو معاك صلاحية العرض بس: الصفحة بتفتح للقراءة من غير أزرار الإرسال والحفظ والتطبيق.' },
        ],
    },

    en: {
        summary: 'One human-assisted site request: read the customer brief, send a quote with deliverables and payments, then — once accepted and paid — run delivery, payments and handover, and talk to the customer on the same page.',
        audience: 'The delivery team. Opening needs "Human-assisted sites: view". Changing the status needs "change status". Quote, deliverables, payments, deadline, messages and notes need "update".',
        purpose: [
            'Understand what the customer wants from the brief and attachments.',
            'Send a quote with duration, deliverables and a payment split.',
            'After acceptance: mark deliverables, release payments, change status, extend the deadline.',
            'Hand over the site link — the customer only sees it once everything is paid.',
        ],
        sections: [
            {
                title: 'Header',
                items: [
                    { name: 'Title, reference, status', desc: 'Project name, request code and current status badge.' },
                    { name: 'Time left to deliver', desc: 'Days/hours/minutes to the due date (device clock). Red with a minus sign when overdue. Hidden once the request is final (completed / rejected / cancelled).' },
                ],
            },
            {
                title: 'Payments summary',
                items: [
                    { name: 'Price / Paid / Remaining', desc: 'Sum of all payments, what is paid and what is left. Shown after the first quote.' },
                    { name: 'Reason for declining', desc: 'When the customer declined the quote with a reason.' },
                ],
            },
            {
                title: 'Quote (shown in: received, under review, quote sent, quote declined)',
                items: [
                    { name: 'Price', desc: 'Required, at least 1, in the customer currency fixed from their wallet when they asked.' },
                    { name: 'Duration (days)', desc: '1–730. The countdown starts when the customer accepts.' },
                    { name: 'Quote valid (days)', desc: '1–90, starts at 7. After that the customer cannot accept.' },
                    { name: 'Notes for the customer', desc: 'Optional, up to 3000 characters.' },
                    { name: 'Delivery schedule', desc: 'Optional, up to 20 deliverables: title, details, day number from the start.' },
                    { name: 'Payments', desc: '1–10 payments, each with a name, amount and trigger: on acceptance, when a given deliverable is handed over, or on final delivery. At least one must be "on acceptance".' },
                    { name: 'Split 40 / 30 / 30', desc: '40% on acceptance, 30% on the first deliverable (if any), the rest on final delivery.' },
                    { name: 'Allocated', desc: 'Payments must add up exactly to the price, or the send button stays disabled.' },
                    { name: 'Send quote / Update and resend', desc: 'Replaces the old deliverables and payments, sets the status to "quote sent" and notifies the customer.' },
                ],
            },
            {
                title: 'Delivery plan (after acceptance)',
                items: [
                    { name: 'Each deliverable', desc: 'Title, details, expected date and a pending / in progress / delivered picker.' },
                    { name: 'Delivered', desc: 'Notifies the customer and makes the payment tied to it "due".' },
                ],
            },
            {
                title: 'Payments',
                items: [
                    { name: 'Status', desc: '"Not yet due", "Due" (the customer can pay it from the wallet) or "Paid" with the date.' },
                    { name: 'Release now', desc: 'Makes a not-yet-due payment due now without waiting for its deliverable. The customer is notified.' },
                ],
            },
            {
                title: 'Customer brief',
                items: [
                    { name: 'Fields', desc: 'Business name, site type, description, audience, goals, pages, features, languages, style notes, colours, reference sites, logo, domain, content, budget, wanted within, contact details. Empty fields are hidden.' },
                    { name: 'Attachments', desc: 'Up to 6 files the customer uploaded; click to download.' },
                ],
            },
            {
                title: 'Side column',
                items: [
                    { name: 'Status + note + Apply', desc: 'Free move to any active status; the note goes into the conversation and the customer is notified. "Delivered" releases the final payment; "Completed" needs every payment settled. "Rejected" or "Cancelled" on a request the customer has paid into refunds everything paid to their wallet automatically (spend-only credit), once; the payments show "Refunded" and the customer gets a notification and a conversation line. Picking one of these on a paid request shows a warning with the amount before you apply.' },
                    { name: 'Change delivery date', desc: 'Only after acceptance and before the request is final. Must be in the future (set to 23:59 of the chosen day). The customer is notified.' },
                    { name: 'Conversation', desc: 'Your messages, the customer’s and system ones (status change, payment due, refund…). Opening it marks customer messages read. Ctrl+Enter sends; up to 2000 characters; the customer is notified. Live: every new message on the request arrives as a real-time event, so the conversation (and the request, if it changed) is read once; after a dropped connection comes back it is read once to catch up. No timer.' },
                    { name: 'Delivered site link & handover notes', desc: 'The customer only sees them when the request is completed, or delivered and fully paid.' },
                    { name: 'Internal notes', desc: 'Team only; never shown to the customer.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Send a quote',
                steps: ['Read the brief and attachments.', 'Optionally move the status to "Under review" so the customer knows you are on it.', 'In "Quote" enter the price, duration and validity.', 'Add deliverables (optional), then click "Split 40 / 30 / 30" or add payments until "Allocated" equals the price.', 'Click "Send quote".'],
            },
            {
                title: 'Run the work after acceptance',
                steps: ['On acceptance the "on acceptance" payments are taken from the wallet at once, the status becomes "In progress" and the countdown starts.', 'Set each deliverable to "Delivered" so its payment becomes due.', 'Fill the site link and handover notes and save.', 'Set the status to "Delivered": the final payment becomes due; when the customer pays it the request turns "Completed" by itself and the link is shown.'],
            },
            {
                title: 'More time needed',
                steps: ['In "Change delivery date" pick the new date and click Apply.', 'Tell the customer why in the conversation.'],
            },
        ],
        affects: [
            { text: 'Customer app: quote, deliverables, due payments, status, countdown and conversation; almost every step sends a notification.' },
            { text: 'Customer wallet: charged on acceptance and for each due payment they pay (in their country wallet currency).' },
            { route: 'admin.ai-site-requests.index', text: 'Inbox: status, quote, due date and unread count.' },
        ],
        warnings: [
            { level: 'danger', text: 'Moving to "Rejected" or "Cancelled" closes the request for good and refunds every payment the customer made (to their wallet, as spend-only credit). It cannot be undone.' },
            { level: 'warning', text: 'Resending a quote deletes all previous deliverables and payments; it is only allowed before the customer accepts.' },
            { level: 'warning', text: '"In progress", "Delivered" and "Completed" are refused before the customer accepts; "Completed" is refused while a payment is unpaid.' },
            { level: 'warning', text: 'Once final (completed / rejected / cancelled) the status, deliverables and deadline can no longer change.' },
            { level: 'info', text: 'An expired quote cannot be accepted; resend it with a new validity.' },
            { level: 'info', text: 'With view only the page is read-only: no send, save or apply buttons.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-site-requests/show.vue',
            'resources/js/modules/admin/themes/theme-1/views/ai-site-requests/QuoteBuilder.vue',
            'resources/js/modules/admin/themes/theme-1/views/ai-site-requests/RequestChat.vue',
            'Modules/AI/app/Http/Controllers/AiSiteServiceRequestAdminController.php',
            'Modules/AI/app/Services/Sites/AiSiteServiceRequestService.php',
            'Modules/AI/app/Models/AiSiteServiceRequest.php, AiSiteRequestMilestone.php, AiSiteRequestInstallment.php, AiSiteRequestMessage.php',
        ],
        api: [
            'GET  /api/admin/v1/ai-site-requests/{id}                (also stamps admin_seen_at)',
            'PUT  /api/admin/v1/ai-site-requests/{id}                (admin_notes, delivery_url, delivery_notes)',
            'POST /api/admin/v1/ai-site-requests/{id}/quote',
            'POST /api/admin/v1/ai-site-requests/{id}/status         (status, note)',
            'POST /api/admin/v1/ai-site-requests/{id}/extend         (due_at after now, reason)',
            'POST /api/admin/v1/ai-site-requests/{id}/milestones/{milestone}  (pending|in_progress|delivered)',
            'POST /api/admin/v1/ai-site-requests/{id}/installments/{installment}/release',
            'GET|POST /api/admin/v1/ai-site-requests/{id}/messages   (GET marks customer messages read)',
            'GET  /api/admin/v1/ai-site-requests/{id}/files/{index}',
            'GET  /api/admin/v1/ai-site-requests/statuses',
            'Real time: event ai_site_request.changed (Modules/AI/app/Events/AiSiteRequestChanged.php, {request_id, message_id, sender}) on App.Models.Admin.{id} of every admin with ai-site-requests.view and on the customer’s own private channel; one per request per commit',
        ],
        tables: ['ai_site_service_requests', 'ai_site_request_milestones', 'ai_site_request_installments (wallet_operation_id; status pending|due|paid|refunded)', 'ai_site_request_messages', 'ai_site_request_statuses'],
        permissions: [
            'ai-site-requests.view (page, messages, files)',
            'ai-site-requests.change-status (POST {id}/status — changeStatus)',
            'ai-site-requests.update (quote, extend, milestones, release, internal notes, send message)',
        ],
        decisions: [
            {
                ar: 'تغيير الحالة الحر بيسمح تختار "تم إرسال العرض" يدويًا من غير عرض. محتاج قرار: نمنعه؟',
                en: 'The free status change allows picking "Quote sent" by hand without a quote. NEEDS-DECISION: block it?',
            },
            {
                ar: 'صلاحية العرض في الفورم بتبدأ دايمًا بـ 7 ومش بتقرا "صلاحية العرض" من إعدادات الصندوق.',
                en: 'The quote validity field always starts at 7 and ignores the inbox setting "Quote valid for (days)".',
            },
        ],
        noTranslatableText: 'Request content is written by the customer and the team in their own language; status names use fixed name_ar / name_en columns.',
    },
};
