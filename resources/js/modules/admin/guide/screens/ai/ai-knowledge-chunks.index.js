/**
 * Guide: AI knowledge chunks (admin.ai-knowledge-chunks.index).
 * Source of truth checked: views/ai-knowledge-chunks/index.vue, Modules/AI/routes/admin.php
 * (admin/v1/ai-knowledge-chunks), Http/Controllers/AiKnowledgeChunkController.php, Services/AiKnowledgeChunkService.php,
 * Repositories/AiKnowledgeChunkRepository.php, Http/Resources/AiKnowledgeChunkResource.php,
 * Services/AiKnowledgeIngestionService.php (indexChunks, reingestText), Services/AiKnowledgeRetriever.php.
 */
export default {
    route: 'admin.ai-knowledge-chunks.index',
    updated: '2026-11-10',

    ar: {
        summary: 'القطع الصغيرة اللي كل مصدر معرفة اتقسم لها علشان المساعد الذكي يدوّر فيها. الشاشة للعرض بس، ومفيدة تتأكد إن المصدر اتفهرس صح.',
        audience: 'المسؤول الفني أو مسؤول المحتوى. محتاج صلاحية "قطع المعرفة: عرض".',
        purpose: [
            'لما تضيف مصدر معرفة، النص بيتقسم لقطع حوالي 1000 حرف، مع تداخل 150 حرف بين كل قطعة واللي بعدها علشان المعنى ميتقطعش.',
            'المساعد بيدوّر في القطع دي مع كل رسالة، وبياخد أقرب 5 قطع كدليل للرد.',
            'لو قطعتين نصهم متطابق في نفس المصدر، واحدة بس اللي بتتحفظ.',
        ],
        sections: [
            {
                title: 'الجدول (الأحدث فوق)',
                items: [
                    { name: 'مصدر المعرفة', desc: 'اسم المصدر اللي القطعة تبعه.' },
                    { name: 'الترتيب', desc: 'مكان القطعة في النص، من 0. ممكن تلاقي أرقام ناقصة لو قطعة اتشالت لأنها مكررة.' },
                    { name: 'عدد التوكنز', desc: 'تقدير تقريبي: عدد الحروف ÷ 4.' },
                    { name: 'قابل للبحث', desc: 'نعم = المساعد يقدر يلاقيها.' },
                    { name: 'التاريخ', desc: 'وقت الفهرسة.' },
                    { name: 'الترقيم', desc: 'تحت الجدول: الصفحات وعدد الصفوف.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'تتأكد إن مصدر اتفهرس',
                steps: [
                    'بعد ما الفهرسة تبقى "متاح" في شاشة مصادر المعرفة، افتح الشاشة دي.',
                    'دوّر على اسم المصدر. لازم تلاقي قطعة واحدة على الأقل.',
                    'لو مفيش قطع والفهرسة "متاح"، النص كان فاضي بعد التنضيف.',
                ],
            },
        ],
        affects: [
            { route: 'admin.ai-knowledge-sources.index', text: 'مصادر المعرفة: كل قطعة تبع مصدر من هناك، وبتتستخدم بس لو المصدر معتمد ومتاح.' },
            { text: 'ردود المساعد الذكي: القطع دي هي الدليل اللي بيدخل في الرد.' },
        ],
        warnings: [
            { level: 'info', text: 'نص القطعة نفسه مش ظاهر هنا. محفوظ في ملف على السيرفر (content_ref) ومش في قاعدة البيانات.' },
            { level: 'info', text: 'القطعة "قابلة للبحث" مش معناها إنها بتتستخدم: المصدر لازم يكون معتمد ومتاح وتصنيفه public أو internal.' },
            { level: 'info', text: 'مفيش بحث ولا فلتر ولا زراير هنا.' },
        ],
    },

    en: {
        summary: 'The small pieces each knowledge source is split into so the AI assistant can search them. Read-only; useful to confirm a source was indexed.',
        audience: 'Technical or content owner. Needs "knowledge chunks: view".',
        purpose: [
            'A new knowledge source is split into ~1000-character chunks with a 150-character overlap so meaning is not cut.',
            'The assistant searches these chunks on every message and takes the 5 closest as evidence.',
            'Identical chunks within one source are stored once.',
        ],
        sections: [
            {
                title: 'Table (newest first)',
                items: [
                    { name: 'Knowledge source', desc: 'The source the chunk belongs to.' },
                    { name: 'Index', desc: 'Position in the text from 0; gaps mean a duplicate chunk was skipped.' },
                    { name: 'Tokens', desc: 'Rough estimate: characters ÷ 4.' },
                    { name: 'Searchable', desc: 'Yes = the assistant can find it.' },
                    { name: 'Date', desc: 'When it was indexed.' },
                    { name: 'Pagination', desc: 'Pages and rows per page.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Confirm a source was indexed',
                steps: ['Once indexing shows "indexed" on Knowledge sources, open this screen.', 'Find the source name; there must be at least one chunk.', 'No chunks but "indexed": the text was empty after cleaning.'],
            },
        ],
        affects: [
            { route: 'admin.ai-knowledge-sources.index', text: 'Knowledge sources: every chunk belongs to one, and is used only when that source is approved and ready.' },
            { text: 'Assistant replies: these chunks are the evidence given to the model.' },
        ],
        warnings: [
            { level: 'info', text: 'The chunk text is not shown here; it lives in a server file (content_ref), not in the database.' },
            { level: 'info', text: '"Searchable" does not mean "used": the source must be approved, ready and public or internal.' },
            { level: 'info', text: 'No search, filter or buttons here.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-knowledge-chunks/index.vue',
            'Modules/AI/app/Http/Controllers/AiKnowledgeChunkController.php (index, show)',
            'Modules/AI/app/Repositories/AiKnowledgeChunkRepository.php (with knowledgeSource, newest first)',
            'Modules/AI/app/Services/AiKnowledgeIngestionService.php (indexChunks: dedupe by md5, token_count = ceil(chars/4))',
            'Modules/AI/app/Services/AiKnowledgeRetriever.php (searchable = true, max_chunks_scanned)',
        ],
        api: ['GET /api/admin/v1/ai-knowledge-chunks', 'GET /api/admin/v1/ai-knowledge-chunks/{chunk}'],
        tables: ['ai_knowledge_chunks (knowledge_source_id, chunk_index, content_ref, token_count, searchable)', 'ai_knowledge_sources'],
        permissions: ['ai-knowledge-chunks.view (screen and list; no write actions)'],
        decisions: [],
    },
};
