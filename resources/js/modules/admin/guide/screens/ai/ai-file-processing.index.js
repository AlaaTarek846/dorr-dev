/**
 * Guide: AI file processing (admin.ai-file-processing.index).
 * Source of truth checked: views/ai-file-processing/index.vue, Modules/AI/routes/admin.php
 * (admin/v1/ai-file-processing), Http/Controllers/AiFileProcessingController.php, Services/AiFileProcessingService.php,
 * Repositories/AiFileProcessingRepository.php, Http/Resources/AiFileProcessingResource.php,
 * Models/AiFileProcessing.php (types, statuses), Jobs/ProcessAiFileJob.php (handle, markFailed, tries/backoff).
 */
export default {
    route: 'admin.ai-file-processing.index',
    updated: '2026-11-10',

    ar: {
        summary: 'سجل بكل محاولة معالجة لملف اترفع للمساعد الذكي: استخراج النص منه نجح ولا فشل، وليه فشل. الشاشة للعرض بس.',
        audience: 'المسؤول الفني. محتاج صلاحية "معالجة الملفات: عرض".',
        purpose: [
            'بعد ما الملف يعدّي الفحص، شغلانة في الخلفية بتقرا النص منه (PDF وWord وExcel وصور وغيرهم) علشان المساعد يقدر يجاوب منه.',
            'كل محاولة بتعمل صف هنا. لو نجحت، الملف بيبقى "جاهز" وبيتقسم لقطع للبحث.',
            'لو الفشل مؤقت، النظام بيعيد المحاولة لحد 3 مرات (بعد 10 ثم 30 ثم 60 ثانية)، وكل محاولة ليها صف لوحدها.',
        ],
        sections: [
            {
                title: 'الجدول (الأحدث فوق)',
                items: [
                    { name: 'الملف', desc: 'اسم الملف. بيظهر "-" لو الملف اتمسح.' },
                    { name: 'نوع المعالجة', desc: 'حاليًا text_extraction (استخراج النص) بس.' },
                    { name: 'الحالة', desc: 'processing (شغالة)، completed (نجحت)، failed (فشلت).' },
                    { name: 'الخطأ', desc: 'كود السبب، زي unsupported_file_type (نوع مالوش قارئ) أو processing_exception (القارئ وقع).' },
                    { name: 'التاريخ', desc: 'وقت بداية المحاولة.' },
                    { name: 'الترقيم', desc: 'تحت الجدول: الصفحات وعدد الصفوف.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'تعرف ليه ملف فشل',
                steps: [
                    'دوّر على اسم الملف في الجدول.',
                    'لو فيه أكتر من صف، دي محاولات إعادة. بص على آخر صف (الأحدث).',
                    'اقرا عمود الخطأ. لو unsupported_file_type، النوع ده لسه مالوش قارئ.',
                    'لو الصف فاضل processing ومش بيتغيّر، اتأكد إن الـ queue worker شغال.',
                ],
            },
        ],
        affects: [
            { route: 'admin.ai-files.index', text: 'ملفات الذكاء الاصطناعي: حالة الملف بتتغيّر لـ ready أو failed على حسب المحاولة.' },
            { text: 'شات المساعد: الملف اللي معالجته فشلت مش هيتقري في الرد.' },
        ],
        warnings: [
            { level: 'info', text: 'الملف اللي بيترفض في الفحص (حجم أو نوع ممنوع) مش بيوصل هنا خالص. سببه في حالة الملف نفسه.' },
            { level: 'info', text: 'صف "failed" قبله صف تاني لنفس الملف ممكن يكون محاولة مؤقتة اتعادت بعدها. العبرة بآخر صف.' },
            { level: 'info', text: 'مفيش بحث ولا فلتر ولا زراير هنا.' },
        ],
    },

    en: {
        summary: 'Log of every processing attempt on a file sent to the AI assistant: whether text extraction worked and why it failed. Read-only.',
        audience: 'Technical owner. Needs "file processing: view".',
        purpose: [
            'After validation a background job extracts the text (PDF, Word, Excel, images…) so the assistant can answer from it.',
            'Each attempt writes a row here. On success the file becomes "ready" and is chunked for search.',
            'A temporary failure is retried up to 3 times (after 10, 30, 60 seconds); each attempt has its own row.',
        ],
        sections: [
            {
                title: 'Table (newest first)',
                items: [
                    { name: 'File', desc: 'File name; "-" when the file was deleted.' },
                    { name: 'Processing type', desc: 'Only text_extraction today.' },
                    { name: 'Status', desc: 'processing, completed, failed.' },
                    { name: 'Error', desc: 'Reason code, e.g. unsupported_file_type (no reader) or processing_exception (reader crashed).' },
                    { name: 'Date', desc: 'When the attempt started.' },
                    { name: 'Pagination', desc: 'Pages and rows per page.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Find why a file failed',
                steps: ['Find the file name.', 'Several rows = retries; read the newest one.', 'Read the error column; unsupported_file_type means no reader exists yet.', 'Stuck on processing: check the queue worker.'],
            },
        ],
        affects: [
            { route: 'admin.ai-files.index', text: 'AI files: the file turns ready or failed from these attempts.' },
            { text: 'Assistant chat: a file that failed processing is not read in replies.' },
        ],
        warnings: [
            { level: 'info', text: 'Files rejected at validation (size, blocked type) never reach this list; the reason is on the file itself.' },
            { level: 'info', text: 'A "failed" row followed by another row for the same file may be a retried temporary error — the newest row counts.' },
            { level: 'info', text: 'No search, filter or buttons here.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-file-processing/index.vue',
            'Modules/AI/app/Http/Controllers/AiFileProcessingController.php (index, show)',
            'Modules/AI/app/Repositories/AiFileProcessingRepository.php (with file, newest first)',
            'Modules/AI/app/Http/Resources/AiFileProcessingResource.php',
            'Modules/AI/app/Jobs/ProcessAiFileJob.php (only writer; tries 3, backoff 10/30/60)',
        ],
        api: ['GET /api/admin/v1/ai-file-processing', 'GET /api/admin/v1/ai-file-processing/{processing}'],
        tables: ['ai_file_processing (file_id, processing_type, status, extracted_content_ref, blocks_ref, error_message)', 'ai_files'],
        permissions: ['ai-file-processing.view (screen and list; no write actions)'],
        decisions: [
            {
                ar: 'الـ relation بتاع file مش withTrashed، فالملف المحذوف بيظهر "-" ومش بتعرف كان إيه. والأنواع metadata_extraction/ocr/parsing/index_preparation متعرّفة في الموديل بس مفيش كود بيكتبها.',
                en: 'The file relation is not withTrashed, so a deleted file shows "-". The metadata_extraction/ocr/parsing/index_preparation types exist on the model but nothing writes them.',
            },
        ],
    },
};
