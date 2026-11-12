/**
 * Guide: AI files (admin.ai-files.index).
 * Source of truth checked: views/ai-files/index.vue, Modules/AI/routes/admin.php (admin/v1/ai-files),
 * Http/Controllers/AiFileController.php, Services/AiFileService.php, Repositories/AiFileRepository.php,
 * Models/AiFile.php (statuses, sources), Services/AiFileEngine.php (process, validate, delete),
 * Services/AiFileUploadService.php, Jobs/ProcessAiFileJob.php, Services/AiChatService.php (chat attachments),
 * Modules/AI/config/config.php (ai.files.*).
 */
export default {
    route: 'admin.ai-files.index',
    updated: '2026-11-10',

    ar: {
        summary: 'سجل بكل ملف اليوزر رفعه للمساعد الذكي (مرفق في الشات أو رفع مباشر): مين رفعه، واسمه، وحجمه، ووصل لفين في المعالجة. الشاشة للعرض بس.',
        audience: 'المسؤول الفني أو الدعم لما يوزر يقول "الملف مش بيتقري". محتاج صلاحية "ملفات الذكاء الاصطناعي: عرض".',
        purpose: [
            'تعرف الملف اتقبل ولا اترفض، وهل بقى جاهز إن المساعد يقراه ويجاوب منه.',
            'الملف بيمشي كده: اترفع ← بيتفحص ← بيتعالج (استخراج النص في الخلفية) ← جاهز، أو فشل.',
            'الفحص بيرفض الملف لو أكبر من الحد (25 ميجا افتراضيًا)، أو نوعه مش مسموح، أو طلع ملف تنفيذي متنكر.',
            'لو نفس اليوزر رفع نفس الملف بالظبط تاني، النظام بيستخدم النسخة الجاهزة القديمة ومش بيعمل صف جديد.',
        ],
        sections: [
            {
                title: 'الجدول (الأحدث فوق)',
                items: [
                    { name: 'المالك', desc: 'اسم اليوزر (أو مقدم الخدمة أو الكابتن) ورقمه.' },
                    { name: 'اسم الملف', desc: 'الاسم بعد تنضيفه من الرموز الخطرة.' },
                    { name: 'المصدر', desc: 'conversation = اترفع كمرفق في محادثة، upload = اترفع من غير محادثة.' },
                    { name: 'الحجم', desc: 'بالبايت أو KB أو MB.' },
                    { name: 'الحالة', desc: 'uploaded، validating، processing، ready (جاهز للقراية)، failed (اترفض أو فشلت معالجته).' },
                    { name: 'التاريخ', desc: 'وقت الرفع بتوقيت جهازك.' },
                    { name: 'الترقيم', desc: 'تحت الجدول: الصفحات وعدد الصفوف.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'يوزر بيقول المساعد مش شايف الملف',
                steps: [
                    'دوّر على صف الملف باسم اليوزر واسم الملف.',
                    'لو الحالة ready، الملف جاهز، والمشكلة في السؤال أو المحادثة.',
                    'لو processing لفترة طويلة، اتأكد إن الـ queue worker شغال.',
                    'لو failed، افتح شاشة معالجة الملفات وشوف عمود الخطأ علشان تعرف السبب.',
                ],
            },
        ],
        affects: [
            { text: 'شات المساعد: الملف الجاهز بس هو اللي المساعد بيقراه ويجاوب منه.' },
            { route: 'admin.ai-file-processing.index', text: 'معالجة الملفات: خطوات معالجة كل ملف هنا وسبب الفشل.' },
            { route: 'admin.ai-conversation-attachments.index', text: 'مرفقات المحادثات: ربط الملف بالمحادثة.' },
        ],
        warnings: [
            { level: 'info', text: 'الملف اللي اليوزر مسحه بيختفي من الجدول ده، والملف نفسه بيتمسح من التخزين.' },
            { level: 'info', text: 'كل يوزر ليه حد: 200 ملف و1 جيجا إجمالي (افتراضيًا، من إعدادات السيرفر).' },
            { level: 'info', text: 'مفيش بحث ولا فلتر ولا زراير هنا. سبب الرفض الكامل بيظهر في شاشة معالجة الملفات أو في الـ API.' },
        ],
    },

    en: {
        summary: 'Log of every file users sent to the AI assistant (chat attachment or direct upload): who, name, size and how far processing got. Read-only.',
        audience: 'Technical owner or support when a user says "the file is not read". Needs "AI files: view".',
        purpose: [
            'Tell whether a file was accepted or rejected, and whether it is ready for the assistant to read.',
            'Flow: uploaded → validating → processing (text extraction in the background) → ready, or failed.',
            'Validation rejects files over the size limit (25 MB default), of a type not allowed, or an executable in disguise.',
            'The same user uploading the exact same file again reuses the ready copy; no new row.',
        ],
        sections: [
            {
                title: 'Table (newest first)',
                items: [
                    { name: 'Owner', desc: 'User, provider or captain name and id.' },
                    { name: 'File name', desc: 'Name after unsafe characters are removed.' },
                    { name: 'Source', desc: 'conversation = attached in a chat, upload = uploaded without one.' },
                    { name: 'Size', desc: 'B, KB or MB.' },
                    { name: 'Status', desc: 'uploaded, validating, processing, ready (readable), failed (rejected or processing failed).' },
                    { name: 'Date', desc: 'Upload time in your device time zone.' },
                    { name: 'Pagination', desc: 'Pages and rows per page.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'A user says the assistant cannot see the file',
                steps: ['Find the row by user and file name.', 'ready: the file is fine; look at the question or conversation.', 'processing for a long time: check that the queue worker runs.', 'failed: open File processing and read the error column.'],
            },
        ],
        affects: [
            { text: 'Assistant chat: only ready files are read and answered from.' },
            { route: 'admin.ai-file-processing.index', text: 'File processing: each file’s steps and failure reason.' },
            { route: 'admin.ai-conversation-attachments.index', text: 'Conversation attachments: file-to-conversation links.' },
        ],
        warnings: [
            { level: 'info', text: 'A file the user deleted disappears from this list and is removed from storage.' },
            { level: 'info', text: 'Per-user limits: 200 files and 1 GB total by default (server config).' },
            { level: 'info', text: 'No search, filter or buttons here. The full rejection reason is on File processing or in the API.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-files/index.vue',
            'Modules/AI/app/Http/Controllers/AiFileController.php (index, show)',
            'Modules/AI/app/Repositories/AiFileRepository.php (with owner, newest first; soft-deleted excluded)',
            'Modules/AI/app/Services/AiFileEngine.php (process, validate, dedupe, delete)',
            'Modules/AI/app/Services/AiFileUploadService.php, Jobs/ProcessAiFileJob.php',
            'Modules/AI/app/Services/AiChatService.php (chat attachments → AiFileEngine::process)',
        ],
        api: [
            'GET /api/admin/v1/ai-files',
            'GET /api/admin/v1/ai-files/{file}',
            'User: POST|GET|DELETE /api/user/v1/ai-files (+ status)',
        ],
        tables: ['ai_files (owner morph, source_type, processing_status, processing_error, metadata, deleted_at)'],
        permissions: ['ai-files.view (screen and list; no write actions)'],
        decisions: [
            {
                ar: 'ألوان الحالة في الشاشة مبنية على pending/rejected القديمة، والحالات الحقيقية uploaded/validating/failed بتظهر رمادي. وسبب الفشل (processing_error) مش ظاهر في الجدول. محتاج تعديل في العرض لو حابين.',
                en: 'The status badge colours use the old pending/rejected values; the real uploaded/validating/failed show grey, and processing_error is not shown in the table. A display fix if wanted.',
            },
        ],
    },
};
