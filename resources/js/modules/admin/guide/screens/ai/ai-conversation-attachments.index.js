/**
 * Guide: AI conversation attachments (admin.ai-conversation-attachments.index).
 * Source of truth checked: views/ai-conversation-attachments/index.vue, Modules/AI/routes/admin.php
 * (admin/v1/ai-conversation-attachments), Http/Controllers/AiConversationAttachmentController.php,
 * Services/AiConversationAttachmentService.php, Repositories/AiConversationAttachmentRepository.php,
 * Http/Resources/AiConversationAttachmentResource.php, Models/AiConversationAttachment.php,
 * Services/AiChatService.php (storeAttachment, storeGeneratedImageAttachment, storeGeneratedAudioAttachment, document output),
 * Models/AiConversation.php (deleting hook removes files), Services/AiVideoGenerationService.php, Http/Requests (chat message rules: 10 MB, allowed mimes),
 * database/migrations/2026_09_22_150200_create_ai_conversation_attachments_table.php.
 */
export default {
    route: 'admin.ai-conversation-attachments.index',
    updated: '2026-11-11',

    ar: {
        summary: 'كل ملف اترفع أو اتولّد جوه محادثة مع المساعد: الملفات اللي اليوزر بيبعتها، والصور والفيديوهات والردود الصوتية اللي المساعد بيعملها. الشاشة للقراءة بس.',
        audience: 'المسؤول الفني أو الدعم. محتاج صلاحية "ai-conversation-attachments.view" (عرض بس).',
        purpose: [
            'رفع من اليوزر: صورة أو PDF أو Word أو Excel أو CSV أو نص أو صوت أو فيديو، لحد 10 ميجا للملف. بيتربط برسالة اليوزر.',
            'من المساعد: الصورة اللي ولّدها أو عدّلها، والفيديو بعد ما يخلص توليده، والرد الصوتي. دول بيتربطوا برسالة المساعد.',
            'الملفات اللي المساعد بيعملها كمستند (PDF أو Excel...) مش هنا، دي في "توليد المستندات".',
            'تفيدك لو يوزر بيقول "بعت ملف والمساعد مشافهوش" أو "الصورة/الفيديو موصلش".',
        ],
        sections: [
            {
                title: 'الجدول (الأحدث فوق)',
                items: [
                    { name: 'المحادثة', desc: 'عنوان المحادثة ورقمها (#). لو المحادثة اتمسحت، المرفقات بتاعتها بتتمسح من الجدول معاها.' },
                    { name: 'رقم الرسالة', desc: 'الرسالة اللي الملف مربوط بيها، رسالة اليوزر لو هو اللي رفعه، أو رسالة المساعد لو هو اللي ولّده. "-" لو الرسالة اتمسحت.' },
                    { name: 'اسم الملف', desc: 'الاسم الأصلي لو اليوزر رفعه. الملفات المولّدة اسمها بيبدأ بـ dorr-ai-edited أو dorr-ai-video أو dorr-ai-voice-reply.' },
                    { name: 'نوع الملف', desc: 'نوع الملف (MIME)، زي image/png أو application/pdf أو video/mp4.' },
                    { name: 'الحجم', desc: 'بالبايت أو KB أو MB.' },
                    { name: 'التاريخ', desc: 'وقت ما الملف اتحفظ.' },
                ],
            },
            {
                title: 'أسفل الجدول',
                items: [
                    { name: 'الصفحات وعدد الصفوف', desc: 'تنقّل بين الصفحات وتختار كام صف في الصفحة. مفيش بحث ولا فلاتر، ومفيش فتح أو تحميل للملف من الجدول.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'يوزر بيقول بعت ملف والمساعد مشافهوش',
                steps: [
                    'اسأله عن عنوان المحادثة أو رقمها ووقت الإرسال.',
                    'دوّر على المحادثة هنا: لو الملف مش موجود، يبقى الرفع نفسه مكملش (غالبًا الحجم أكبر من 10 ميجا أو النوع مش مسموح).',
                    'لو الملف موجود: افتح "ملفات الذكاء الاصطناعي" و"معالجة الملفات" علشان تشوف هل النص اتقرا منه.',
                ],
            },
            {
                title: 'فيديو أو صورة من المساعد موصلتش',
                steps: [
                    'دوّر على المحادثة: لو فيه صف video/mp4 أو image، يبقى الملف اتحفظ والمشكلة في العرض عند اليوزر.',
                    'لو مفيش صف: افتح "طلبات الذكاء الاصطناعي" و"سجلات المزودين" وشوف المكالمة فشلت ولا لأ.',
                ],
            },
        ],
        affects: [
            { text: 'شات المساعد عند اليوزر ومقدم الخدمة والكابتن: نفس الملفات دي بتظهر كمرفقات في فقاعات الرسايل، وفي معرض المخرجات والبحث في المحادثات.' },
            { route: 'admin.ai-files.index', text: 'ملفات الذكاء الاصطناعي: الملف اللي اليوزر بيرفعه بيتسجل كمان هناك علشان يتقرا ويتفهرس.' },
            { route: 'admin.ai-file-processing.index', text: 'معالجة الملفات: خطوات قراية الملف المرفوع.' },
            { route: 'admin.ai-document-generations.index', text: 'توليد المستندات: الملفات اللي المساعد بيعملها كمستند، ومش بتظهر هنا.' },
        ],
        warnings: [
            { level: 'warning', text: 'الملفات دي من محادثات خاصة وممكن يكون فيها بيانات شخصية. الـ API بيرجّع رابط عام للملف (url) على الـ public disk.' },
            { level: 'info', text: 'مسح المحادثة بيمسح الصفوف دي والملفات نفسها من التخزين (AiConversation::deleting).' },
        ],
    },

    en: {
        summary: 'Every file uploaded or generated inside an assistant conversation: files the user sends, and images, videos and voice replies the assistant makes. Read-only.',
        audience: 'Technical admin or support. Needs "ai-conversation-attachments.view" (view only).',
        purpose: [
            'User uploads: image, PDF, Word, Excel, CSV, text, audio or video, up to 10 MB each; linked to the user\'s message.',
            'From the assistant: a generated or edited image, a finished video and a voice reply; linked to the assistant\'s message.',
            'Documents the assistant writes (PDF, Excel…) are not here; they are in "Document generations".',
            'Useful when a user says "I sent a file and the assistant did not see it" or "the image/video never arrived".',
        ],
        sections: [
            {
                title: 'Table (newest first)',
                items: [
                    { name: 'Conversation', desc: 'Conversation title and number (#). Deleting the conversation deletes its attachment rows.' },
                    { name: 'Message ID', desc: 'The message the file belongs to: the user\'s if uploaded, the assistant\'s if generated. "-" if the message was deleted.' },
                    { name: 'File name', desc: 'Original name for uploads. Generated files start with dorr-ai-edited, dorr-ai-video or dorr-ai-voice-reply.' },
                    { name: 'File type', desc: 'MIME type, e.g. image/png, application/pdf, video/mp4.' },
                    { name: 'Size', desc: 'In B, KB or MB.' },
                    { name: 'Date', desc: 'When the file was stored.' },
                ],
            },
            {
                title: 'Below the table',
                items: [
                    { name: 'Pages and rows per page', desc: 'Move between pages and pick the page size. No search, no filters, and no open/download from the table.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'A user says they sent a file and the assistant did not see it',
                steps: [
                    'Ask for the conversation title or number and the time.',
                    'Find the conversation here: no row means the upload never completed (usually over 10 MB or a type not allowed).',
                    'If the row exists: open "AI files" and "File processing" to see whether its text was read.',
                ],
            },
            {
                title: 'A video or image from the assistant never arrived',
                steps: [
                    'Find the conversation: a video/mp4 or image row means the file was saved and the problem is on the display side.',
                    'No row: open "AI requests" and "Provider logs" to see whether the call failed.',
                ],
            },
        ],
        affects: [
            { text: 'The assistant chat for users, providers and drivers: these same files appear as message attachments, in the output gallery and in history search.' },
            { route: 'admin.ai-files.index', text: 'AI files: an uploaded file is also registered there to be read and indexed.' },
            { route: 'admin.ai-file-processing.index', text: 'File processing: the steps of reading an uploaded file.' },
            { route: 'admin.ai-document-generations.index', text: 'Document generations: documents the assistant writes, which do not appear here.' },
        ],
        warnings: [
            { level: 'warning', text: 'These files come from private chats and can hold personal data. The API returns a public URL (url) on the public disk.' },
            { level: 'info', text: 'Deleting a conversation deletes these rows and the stored files themselves (AiConversation::deleting).' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-conversation-attachments/index.vue',
            'Modules/AI/app/Http/Controllers/AiConversationAttachmentController.php',
            'Modules/AI/app/Services/AiConversationAttachmentService.php',
            'Modules/AI/app/Repositories/AiConversationAttachmentRepository.php',
            'Modules/AI/app/Http/Resources/AiConversationAttachmentResource.php',
            'Modules/AI/app/Models/AiConversationAttachment.php',
            'Modules/AI/app/Services/AiChatService.php',
            'Modules/AI/app/Services/AiVideoGenerationService.php',
        ],
        api: [
            'GET /api/admin/v1/ai-conversation-attachments (paginated, newest first; with conversation, message)',
            'GET /api/admin/v1/ai-conversation-attachments/{attachment}',
        ],
        tables: ['ai_conversation_attachments', 'ai_conversations', 'ai_messages', 'ai_files'],
        permissions: ['ai-conversation-attachments.view'],
        decisions: [
            {
                ar: 'الـ API بيرجّع url وis_image بس الشاشة مش بتعرض معاينة ولا رابط تحميل، ومش بتعرض صاحب المحادثة. NEEDS-DECISION: نضيف معاينة أو رابط؟ (ملفات خاصة، محتاجة قرار خصوصية).',
                en: 'The API returns url and is_image but the screen shows no preview, link or conversation owner. NEEDS-DECISION: add a preview/link? (private files — a privacy decision).',
            },
        ],
    },
};
