/**
 * Guide: AI responses log (admin.ai-responses.index).
 * Source of truth checked: views/ai-responses/index.vue, Modules/AI/routes/admin.php (ai-responses),
 * Http/Controllers/AiResponseController.php, Services/AiResponseService.php, Repositories/AiResponseRepository.php,
 * Http/Resources/AiResponseResource.php, Models/AiResponse.php (finish reasons), Services/AiChatService.php
 * (AiResponse create after the reply), Services/AiTaskRunner.php (closeRequest), minimizedForLog.
 */
export default {
    route: 'admin.ai-responses.index',
    updated: '2026-11-10',

    ar: {
        summary: 'سجل بالرد اللي رجع من المزود على كل طلب AI، أو رسالة الخطأ لو فشل. كل صف مربوط بطلب واحد من شاشة "طلبات الذكاء الاصطناعي".',
        audience: 'المسؤول الفني أو الدعم اللي عايز يعرف المساعد رد بإيه بالظبط، أو ليه فشل. محتاج صلاحية "ai-responses.view" (عرض بس).',
        purpose: [
            'تشوف نص الرد اللي اتحفظ لطلب معيّن (بعد إخفاء البيانات الشخصية).',
            'تعرف سبب الفشل: لما الطلب يفشل، الصف بيبقى فيه رسالة الخطأ الأصلية اللي رجعت من المزود.',
        ],
        sections: [
            {
                title: 'الجدول',
                items: [
                    { name: 'رقم الطلب', desc: 'رقم الطلب في شاشة "طلبات الذكاء الاصطناعي". مفيش رابط، دوّر عليه هناك بنفسك.' },
                    { name: 'الرد', desc: 'أول 140 حرف من نص الرد. لو الطلب فشل، بيظهر بدلها رسالة الخطأ اللي رجعت من المزود.' },
                    { name: 'سبب التوقف', desc: 'completed = اترد عليه، error = فشل. length_limit وtool_call موجودين في الكود بس محدش بيكتبهم دلوقتي.' },
                    { name: 'التاريخ', desc: 'وقت حفظ الرد، بتوقيت جهازك. الأحدث فوق.' },
                    { name: 'الترقيم', desc: 'رقم الصفحة وعدد الصفوف. مفيش بحث ولا فلتر.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'معرفة سبب فشل رد',
                steps: [
                    'من شاشة الطلبات خد رقم الطلب اللي حالته failed.',
                    'هنا دوّر على نفس الرقم، هتلاقي سبب التوقف error ورسالة الخطأ في عمود الرد.',
                    'لو الرسالة مقطوعة أو محتاج تفاصيل أكتر، افتح "سجل المزودين" في نفس الوقت.',
                ],
            },
            {
                title: 'مراجعة رد اشتكى منه يوزر',
                steps: [
                    'هات رقم الطلب من شاشة الطلبات (اسم اليوزر ووقت الرسالة).',
                    'اقرا الرد هنا. لو عايز تعرف هل الإجابة اتراجعت قبل ما تتبعت، افتح "التحقق" لنفس الرقم.',
                ],
            },
        ],
        affects: [
            { text: 'الشاشة للقراءة بس.' },
            { route: 'admin.ai-requests.index', text: 'طلبات الذكاء الاصطناعي: الطلب صاحب الرد.' },
            { route: 'admin.ai-verifications.index', text: 'التحقق: مراجعة إجابة الشات قبل ما توصل لليوزر.' },
            { route: 'admin.ai-provider-logs.index', text: 'سجل المزودين: تفاصيل النداء الفعلي والخطأ.' },
        ],
        warnings: [
            { level: 'info', text: 'ده نص الرد زي ما رجع من المزود. اليوزر ممكن يكون شاف حاجة مختلفة شوية: تنبيهات الأمان، أو اعتذار النظام لو التحقق فشل، أو رسالة "المزود مش متاح" بدل الخطأ الخام.' },
            { level: 'info', text: 'النص بيتحفظ بعد إخفاء البيانات الشخصية والأسرار، إلا لو سياسة البيانات (personal) قفلت الـ minimization. رسالة الخطأ بتتحفظ زي ما هي.' },
            { level: 'info', text: 'الطلبات المحظورة بقاعدة أمان، وردود الفرز الطبي السريع، وطلبات توليد الصور والفيديو من الشات، مش بيتعملها صف هنا.' },
            { level: 'info', text: 'لما الطلب يتمسح بسياسة الاحتفاظ، رده بيتمسح معاه.' },
        ],
    },

    en: {
        summary: 'Log of the reply the provider returned for each AI request, or the error when it failed. Each row belongs to one request on the "AI requests" screen.',
        audience: 'Technical owner or support checking exactly what the assistant answered, or why it failed. Needs "ai-responses.view" (view-only).',
        purpose: [
            'Read the stored reply text of a request (personal data redacted).',
            'See why a request failed: a failed row holds the raw provider error.',
        ],
        sections: [
            {
                title: 'Table',
                items: [
                    { name: 'Request ID', desc: 'The request on "AI requests"; not a link, look it up there.' },
                    { name: 'Response', desc: 'First 140 characters of the reply; for a failed request the provider error message instead.' },
                    { name: 'Finish reason', desc: 'completed or error. length_limit and tool_call exist in code but are never written today.' },
                    { name: 'Date', desc: 'When it was stored, local time; newest first.' },
                    { name: 'Pagination', desc: 'Page and rows per page. No search or filter.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Find why a reply failed',
                steps: ['Take the failed request id from AI requests.', 'Find the same id here: finish reason error, the provider error in the Response column.', 'For more detail open Provider logs at the same time.'],
            },
            {
                title: 'Review a reply a user complained about',
                steps: ['Get the request id from AI requests (user + time).', 'Read the reply here; open Verifications for the same id to see whether it was fact-checked.'],
            },
        ],
        affects: [
            { text: 'Read-only.' },
            { route: 'admin.ai-requests.index', text: 'AI requests: the request this reply belongs to.' },
            { route: 'admin.ai-verifications.index', text: 'Verifications: the fact-check of a chat answer.' },
            { route: 'admin.ai-provider-logs.index', text: 'Provider logs: the real call and its error.' },
        ],
        warnings: [
            { level: 'info', text: 'This is the provider’s reply; the user may have seen something slightly different (safety notices, the abstain reply after a failed verification, "provider unavailable" instead of the raw error).' },
            { level: 'info', text: 'Reply text is stored redacted unless the personal data policy turns minimization off; the error message is stored as is.' },
            { level: 'info', text: 'Safety-blocked requests, instant health-triage replies and chat image/video generation requests get no row here.' },
            { level: 'info', text: 'When a request is purged by retention, its response goes with it.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-responses/index.vue (shows response.content || response.message)',
            'Modules/AI/app/Http/Controllers/AiResponseController.php → Services/AiResponseService.php',
            'Modules/AI/app/Repositories/AiResponseRepository.php (with request, newest first)',
            'Modules/AI/app/Http/Resources/AiResponseResource.php',
            'Writers: Services/AiChatService.php (after generateVerifiedReply), Services/AiTaskRunner.php::closeRequest',
        ],
        api: ['GET /api/admin/v1/ai-responses (page, per_page)', 'GET /api/admin/v1/ai-responses/{response} (not used by the screen)'],
        tables: ['ai_responses (request_id → ai_requests cascade, response JSON {content, message}, finish_reason)'],
        permissions: ['ai-responses.view (view-only group)'],
        decisions: [
            { ar: 'مفيش طريقة تشوف الرد كامل (بيتقطع عند 140 حرف) ولا نافذة تفاصيل ولا بحث برقم الطلب.', en: 'No way to read the full reply (cut at 140 chars), no details modal, no search by request id (NEEDS-DECISION).' },
            { ar: 'الرد بيتحفظ بعد التحقق وقبل ما تتضاف تنبيهات الأمان (ولو النظام اعتذر بيتحفظ نص الاعتذار). محتاج قرار لو المطلوب يتحفظ النص النهائي اللي اليوزر شافه.', en: 'The reply is stored after verification but before safety notices are added (on abstain it holds the abstain text); decide whether the exact final text should be logged.' },
            { ar: 'طلبات الصور والفيديو في الشات (handleVideoRequest وأخواتها) بتقفل الطلب من غير صف رد ولا استهلاك.', en: 'Chat image/video requests close the ai_requests row without a response or usage row.' },
        ],
    },
};
