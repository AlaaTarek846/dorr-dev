/**
 * Guide: AI document generations (admin.ai-document-generations.index).
 * Source of truth checked: views/ai-document-generations/index.vue, Modules/AI/routes/admin.php
 * (admin/v1/ai-document-generations), Http/Controllers/AiDocumentGenerationController.php,
 * Services/AiDocumentGenerationService.php, Repositories/AiDocumentGenerationRepository.php,
 * Http/Resources/AiDocumentGenerationResource.php, Models/AiDocumentGeneration.php (statuses),
 * Services/AiChatService.php (generateDownloadableFile, detectRequestedFileFormat, structureContentForDocument),
 * Services/DocumentGeneration/AiDocumentRenderer.php (pdf, docx, xlsx).
 */
export default {
    route: 'admin.ai-document-generations.index',
    updated: '2026-11-10',

    ar: {
        summary: 'سجل بكل ملف المساعد الذكي عمله لليوزر لما طلب منه ملف (PDF أو Word أو Excel): الصيغة، واسم الملف، ونجح ولا لأ. الشاشة للعرض بس.',
        audience: 'المسؤول الفني أو الدعم. محتاج صلاحية "توليد المستندات: عرض".',
        purpose: [
            'لما اليوزر يطلب في الشات "اعملهولي PDF" أو "جهزهالي في شيت"، المساعد بيرد الأول، وبعدين بيحوّل الرد لملف يتنزّل.',
            'الصيغة بتتحدد من كلام اليوزر: pdf أو docx أو xlsx.',
            'نفس الموديل اللي رد بيعيد ترتيب الرد كعناوين ونقط وجداول من غير ما يزود معلومات، وبعدين الملف بيترسم.',
            'لو حصل أي فشل، اليوزر برضه بياخد ملف: نص الرد كما هو في ملف .md، والصف هنا بيتسجل "failed".',
        ],
        sections: [
            {
                title: 'الجدول (الأحدث فوق)',
                items: [
                    { name: 'المالك', desc: 'اسم اليوزر (أو مقدم الخدمة أو الكابتن) ورقمه.' },
                    { name: 'الصيغة', desc: 'pdf أو docx أو xlsx، أو md لو الملف اتعمل كنص خام بعد فشل.' },
                    { name: 'الملف الناتج', desc: 'اسم الملف اللي اليوزر نزّله (dorr-ai-التاريخ-كود).' },
                    { name: 'الحالة', desc: 'generating (شغال)، completed (اتعمل صح)، failed (اتعمل .md بدل الصيغة المطلوبة).' },
                    { name: 'التاريخ', desc: 'وقت الطلب.' },
                    { name: 'الترقيم', desc: 'تحت الجدول: الصفحات وعدد الصفوف.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'يوزر بيقول طلب PDF ونزل له ملف غريب',
                steps: [
                    'دوّر على صف اليوزر في الوقت ده.',
                    'لو الحالة failed والصيغة md، تحويل الرد لـ PDF فشل واليوزر خد النص الخام.',
                    'شوف لوج السيرفر في نفس الوقت علشان تعرف سبب الفشل.',
                ],
            },
        ],
        affects: [
            { text: 'شات المساعد: الملف اللي بيتنزّل لليوزر مع الرد.' },
            { text: 'التخزين: الملفات بتتحفظ على الـ public disk تحت ai-chat/<نوع المالك>/<رقمه>/generated.' },
        ],
        warnings: [
            { level: 'info', text: 'الحالة failed مش معناها إن اليوزر ماخدش حاجة: خد ملف .md بنص الرد.' },
            { level: 'info', text: 'تحويل الرد بيستهلك طلب زيادة من نفس المزود والموديل.' },
            { level: 'info', text: 'مفيش بحث ولا فلتر ولا زراير هنا، ولا رابط تنزيل للملف.' },
        ],
    },

    en: {
        summary: 'Log of every file the AI assistant produced when a user asked for one (PDF, Word, Excel): format, file name and outcome. Read-only.',
        audience: 'Technical owner or support. Needs "document generations: view".',
        purpose: [
            'When a user asks in chat "make it a PDF" or "put it in a sheet", the assistant replies first, then turns the reply into a download.',
            'The format comes from the user’s words: pdf, docx or xlsx.',
            'The same model restructures its reply into headings, bullets and tables without adding facts, then the file is rendered.',
            'On any failure the user still gets a file: the raw reply as .md, and the row here is "failed".',
        ],
        sections: [
            {
                title: 'Table (newest first)',
                items: [
                    { name: 'Owner', desc: 'User, provider or captain name and id.' },
                    { name: 'Format', desc: 'pdf, docx, xlsx — or md when the raw-text fallback was used.' },
                    { name: 'Output file', desc: 'The file name the user downloaded (dorr-ai-<date>-<code>).' },
                    { name: 'Status', desc: 'generating, completed, failed (an .md was given instead).' },
                    { name: 'Date', desc: 'When it was requested.' },
                    { name: 'Pagination', desc: 'Pages and rows per page.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'A user asked for a PDF and got an odd file',
                steps: ['Find the user’s row at that time.', 'failed + md: rendering failed and the raw text was sent.', 'Check the server log at that time for the reason.'],
            },
        ],
        affects: [
            { text: 'Assistant chat: the file the user downloads with the reply.' },
            { text: 'Storage: files are saved on the public disk under ai-chat/<owner type>/<id>/generated.' },
        ],
        warnings: [
            { level: 'info', text: '"failed" does not mean the user got nothing: they got an .md with the reply text.' },
            { level: 'info', text: 'Restructuring the reply costs one extra call to the same provider and model.' },
            { level: 'info', text: 'No search, filter, buttons or download link here.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-document-generations/index.vue',
            'Modules/AI/app/Http/Controllers/AiDocumentGenerationController.php (index, show)',
            'Modules/AI/app/Repositories/AiDocumentGenerationRepository.php (with owner, conversation; newest first)',
            'Modules/AI/app/Services/AiChatService.php (generateDownloadableFile — only writer)',
            'Modules/AI/app/Services/DocumentGeneration/AiDocumentRenderer.php',
        ],
        api: ['GET /api/admin/v1/ai-document-generations', 'GET /api/admin/v1/ai-document-generations/{generation} (includes prompt, output_file_path)'],
        tables: ['ai_document_generations (owner morph, conversation_id, prompt ≤ 2000, output_format, output_file_name, output_file_path, status)'],
        permissions: ['ai-document-generations.view (screen and list; no write actions)'],
        decisions: [
            {
                ar: 'الحالات pending وpreview_ready متعرّفة في الموديل وليها لون في الشاشة، بس مفيش كود بيكتبها.',
                en: 'The pending and preview_ready statuses exist on the model and have a badge colour, but nothing writes them.',
            },
        ],
    },
};
