/**
 * Guide: AI knowledge sources (admin.ai-knowledge-sources.index).
 * Source of truth checked: views/ai-knowledge-sources/index.vue, Modules/AI/routes/admin.php
 * (admin/v1/ai-knowledge-sources), Http/Controllers/AiKnowledgeSourceController.php,
 * Http/Requests/AiKnowledgeSourceIngestRequest.php, Services/AiKnowledgeSourceService.php (ingest, approve, reject,
 * deprecate), Services/AiKnowledgeIngestionService.php (ingestText, runIndexing, indexChunks),
 * Jobs/IndexAiKnowledgeSourceJob.php, Services/AiKnowledgeRetriever.php (candidateSources, freshnessBoost),
 * Services/AiChatService.php (retrieve → evidence system message, citations), Modules/AI/config/config.php (ai.knowledge.*).
 */
export default {
    route: 'admin.ai-knowledge-sources.index',
    updated: '2026-11-11',

    ar: {
        summary: 'هنا بتضيف نصوص مرجعية (لوايح، أسئلة شائعة، سياسات…) المساعد الذكي يرجع لها ويستشهد بيها في ردوده، وبتعتمدها أو ترفضها أو توقفها.',
        audience: 'مسؤول المحتوى أو الجودة. فتح الشاشة محتاج "مصادر المعرفة: عرض"، والإضافة محتاجة "إضافة"، والاعتماد والرفض والإيقاف محتاجين "تعديل".',
        purpose: [
            'المصدر بيتنضف ويتقسم لقطع (حوالي 1000 حرف لكل قطعة)، وكل قطعة بتتحفظ قابلة للبحث. لو المزود الافتراضي OpenAI، كل قطعة بياخد لها بصمة معنى (embedding) كمان.',
            'مع كل رسالة في الشات، المساعد بيدوّر في القطع على أقرب 5 قطع للسؤال، ويديها للموديل كدليل، والرد بيبين المصادر.',
            'المصدر مش بيتستخدم في أي رد غير لما يكون: معتمد، ومتاح (الفهرسة خلصت)، ومش موقوف، وتصنيفه public أو internal.',
            'المصادر الأحدث (تاريخ الجلب) بتاخد أفضلية بسيطة لو اتنين متساويين في القرب من السؤال.',
        ],
        sections: [
            {
                title: 'رأس الصفحة',
                items: [
                    { name: 'إضافة مصدر', desc: 'بيفتح ويقفل فورم الإضافة فوق الجدول (محتاج صلاحية "إضافة").' },
                ],
            },
            {
                title: 'فورم الإضافة',
                items: [
                    { name: 'الاسم', desc: 'إجباري، لحد 255 حرف. هو اللي بيظهر كمصدر في الرد.' },
                    { name: 'التخصص', desc: 'اختياري، لحد 100 حرف (مثلًا legal). بيتحفظ بس الشات حاليًا مش بيفلتر بيه.' },
                    { name: 'الدولة', desc: 'اختياري، حرفين بالظبط (مثلًا SA أو EG).' },
                    { name: 'الناشر والجهة المعتمدة', desc: 'اختياريين، للتوثيق.' },
                    { name: 'التصنيف', desc: 'public أو internal (الافتراضي) بس هما اللي بيوصلوا للشات. confidential وpersonal وsecret وunverified مش هيتستخدموا في أي رد.' },
                    { name: 'المحتوى', desc: 'النص نفسه، إجباري، 20 حرف على الأقل.' },
                    { name: 'حفظ / إلغاء', desc: 'الحفظ بيعمل المصدر "قيد المراجعة" وبيبدأ الفهرسة في الخلفية.' },
                ],
            },
            {
                title: 'الجدول (الأحدث فوق)',
                items: [
                    { name: 'الاسم والتخصص والتصنيف', desc: 'زي ما اتكتبوا في الفورم.' },
                    { name: 'النسخة', desc: 'رقم النسخة (v1 للجديد). شارة "تم التغيير" لو اتعلّم إن المحتوى اتغيّر.' },
                    { name: 'حالة الاعتماد', desc: 'قيد المراجعة، معتمد، مرفوض، قديم (موقوف).' },
                    { name: 'الفهرسة', desc: 'في الانتظار، جارٍ الفهرسة، متاح، فشلت. لو فشلت حط الماوس عليها تشوف السبب.' },
                    { name: 'التاريخ', desc: 'وقت الإضافة.' },
                    { name: 'اعتماد / رفض / إيقاف الاعتماد', desc: 'كل زرار بيظهر لو المصدر مش في الحالة دي أصلًا (محتاجين صلاحية "تعديل").' },
                    { name: 'الترقيم', desc: 'تحت الجدول: الصفحات وعدد الصفوف.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'إضافة مرجع جديد للمساعد',
                steps: [
                    'دوس "إضافة مصدر".',
                    'اكتب الاسم، والصق النص في "المحتوى"، واختار التصنيف public أو internal.',
                    'دوس حفظ. المصدر هيظهر "قيد المراجعة" والفهرسة "في الانتظار".',
                    'استنى لحد ما الفهرسة تبقى "متاح" (محتاجة الـ queue worker شغال).',
                    'دوس "اعتماد". من الرسالة الجاية المساعد يقدر يستخدمه.',
                ],
            },
            {
                title: 'وقف مرجع قديم',
                steps: ['دوس "إيقاف الاعتماد" على المصدر.', 'المساعد مش هيستخدمه تاني لحد ما تعتمده تاني.'],
            },
        ],
        affects: [
            { text: 'ردود المساعد الذكي لكل اليوزرز ومقدمي الخدمة والكباتن: المصادر المعتمدة بتدخل كدليل في الرد وبتظهر كمصادر.' },
            { route: 'admin.ai-knowledge-chunks.index', text: 'قطع المعرفة: القطع اللي المصدر اتقسم لها.' },
            { text: 'كل طلب للمساعد بيتسجل معاه المصادر والقطع اللي اتستشهد بيها (جدول ai_request_citations).' },
        ],
        warnings: [
            { level: 'danger', text: 'المصدر بتصنيف confidential أو personal أو secret أو unverified مش هيتستخدم في أي رد خالص، لأن مالوش صاحب غير "النظام". استخدم public أو internal.' },
            { level: 'warning', text: 'المحتوى بيوصل لأي حد بيكلم المساعد. متحطش بيانات شخصية أو سرية في النص.' },
            { level: 'info', text: '"إيقاف الاعتماد" بيقفل المصدر، و"اعتماد" بعد كده بيرجّعه شغال تاني فورًا (من الرسالة الجاية).' },
            { level: 'warning', text: 'مفيش تعديل ولا حذف للمصدر من الشاشة. لو النص غلط: ارفضه أو أوقفه وأضف نسخة صح.' },
            { level: 'info', text: 'رسالة النجاح بتقول "اتفهرس" بس الفهرسة بتحصل في الخلفية بعدها. بص على عمود الفهرسة.' },
            { level: 'info', text: 'لو المزود الافتراضي مش OpenAI، البحث بيبقى بالكلمات بس من غير بصمة معنى.' },
        ],
    },

    en: {
        summary: 'Add reference texts (regulations, FAQs, policies…) the AI assistant retrieves and cites in its replies, then approve, reject or deprecate them.',
        audience: 'Content or quality owner. Needs "knowledge sources: view" to open, "create" to add, "update" to approve, reject or deprecate.',
        purpose: [
            'A source is cleaned and split into chunks (~1000 characters), each stored as searchable; when the default provider is OpenAI each chunk also gets an embedding.',
            'On every chat message the assistant finds the 5 closest chunks, gives them to the model as evidence, and the reply lists the sources.',
            'A source is used only when approved, indexed ("ready"), not deprecated, and classified public or internal.',
            'Newer sources (fetch date) get a small boost when two chunks are equally relevant.',
        ],
        sections: [
            {
                title: 'Header',
                items: [{ name: 'Add source', desc: 'Opens or closes the add form above the table (needs "create").' }],
            },
            {
                title: 'Add form',
                items: [
                    { name: 'Name', desc: 'Required, up to 255 characters; shown as the source in replies.' },
                    { name: 'Domain', desc: 'Optional, up to 100 characters (e.g. legal). Stored, but the chat does not filter by it today.' },
                    { name: 'Country', desc: 'Optional, exactly 2 letters (e.g. SA, EG).' },
                    { name: 'Publisher, authority', desc: 'Optional, for the record.' },
                    { name: 'Classification', desc: 'Only public and internal (default) reach the chat; confidential, personal, secret and unverified are never used.' },
                    { name: 'Content', desc: 'The text itself; required, at least 20 characters.' },
                    { name: 'Save / Cancel', desc: 'Save creates the source as "pending review" and queues indexing.' },
                ],
            },
            {
                title: 'Table (newest first)',
                items: [
                    { name: 'Name, domain, classification', desc: 'As entered.' },
                    { name: 'Version', desc: 'Version number (v1 when new); "Changed" badge when a change was flagged.' },
                    { name: 'Approval', desc: 'Pending review, approved, rejected, deprecated.' },
                    { name: 'Indexing', desc: 'Queued, indexing, indexed, failed — hover a failed badge for the reason.' },
                    { name: 'Date', desc: 'When it was added.' },
                    { name: 'Approve / Reject / Deprecate', desc: 'Each shows unless the source is already in that state (needs "update").' },
                    { name: 'Pagination', desc: 'Pages and rows per page.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Add a new reference for the assistant',
                steps: ['Click "Add source".', 'Enter the name, paste the text into Content, pick public or internal.', 'Save — it shows "pending review" and indexing "queued".', 'Wait for indexing to show "indexed" (needs the queue worker).', 'Click "Approve"; the assistant can use it from the next message.'],
            },
            {
                title: 'Retire an old reference',
                steps: ['Click "Deprecate".', 'The assistant stops using it until you approve it again.'],
            },
        ],
        affects: [
            { text: 'Assistant replies for every user, provider and captain: approved sources become evidence and are listed as sources.' },
            { route: 'admin.ai-knowledge-chunks.index', text: 'Knowledge chunks: the pieces the source was split into.' },
            { text: 'Each assistant request records the sources and chunks it cited (ai_request_citations).' },
        ],
        warnings: [
            { level: 'danger', text: 'A source classified confidential, personal, secret or unverified is never used in any reply — its only owner is "system". Use public or internal.' },
            { level: 'warning', text: 'The content can reach anyone talking to the assistant. Do not put personal or secret data in it.' },
            { level: 'info', text: '"Deprecate" switches the source off; "Approve" later switches it back on right away (from the next message).' },
            { level: 'warning', text: 'No edit or delete on this screen. If the text is wrong, reject or deprecate it and add a corrected one.' },
            { level: 'info', text: 'The success message says "indexed" but indexing runs in the background afterwards — watch the Indexing column.' },
            { level: 'info', text: 'When the default provider is not OpenAI, search is keyword-only, without embeddings.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-knowledge-sources/index.vue',
            'Modules/AI/app/Http/Controllers/AiKnowledgeSourceController.php (store → owner_type system, owner_id null)',
            'Modules/AI/app/Http/Requests/AiKnowledgeSourceIngestRequest.php',
            'Modules/AI/app/Services/AiKnowledgeSourceService.php, AiKnowledgeIngestionService.php, Jobs/IndexAiKnowledgeSourceJob.php',
            'Modules/AI/app/Services/AiKnowledgeRetriever.php (approved + is_active + ready + public/internal or own)',
            'Modules/AI/app/Services/AiKnowledgeSourceService.php (approve sets is_active = true again; deprecate sets it false)',
            'Modules/AI/app/Services/AiChatService.php (retrieve, evidenceSystemMessage, storeCitations)',
        ],
        api: [
            'GET|POST /api/admin/v1/ai-knowledge-sources   (POST: name, content, domain?, country_code?, publisher?, authority?, published_at?, effective_at?, data_classification?)',
            'GET      /api/admin/v1/ai-knowledge-sources/{source}',
            'POST     /api/admin/v1/ai-knowledge-sources/{source}/approve | reject | deprecate',
        ],
        tables: [
            'ai_knowledge_sources (approval_status, processing_status, processing_error, is_active, data_classification, current_version, change_detected)',
            'ai_knowledge_chunks (content_ref → storage/app ai-knowledge/{id}/chunk-N.json)',
            'ai_request_citations',
        ],
        permissions: [
            'ai-knowledge-sources.view (screen, list)',
            'ai-knowledge-sources.create (Add source, POST /)',
            'ai-knowledge-sources.update (approve, reject, deprecate)',
        ],
        decisions: [
            {
                ar: 'NEEDS-DECISION: مصادر الأدمن owner_type = system وowner_id = null، فالتصنيفات confidential/personal/secret/unverified مش بتوصل لحد. نشيلها من الفورم ولا نعرّف مين يشوفها؟',
                en: 'NEEDS-DECISION: admin sources are owned by system/null, so confidential/personal/secret/unverified reach nobody. Drop them from the form or define who may see them?',
            },
            {
                ar: 'الشات بيستدعي retrieve من غير domain، فحقل التخصص مش بيأثر. وchange_detected مفيش كود بيخليه true، وreingestText مالوش route، فمفيش طريقة من الشاشة لتحديث نسخة. ومفيش تعديل/حذف.',
                en: 'Chat calls retrieve() without a domain, so Domain has no effect; nothing ever sets change_detected = true and reingestText() has no route, so a new version cannot be made from the screen; no edit/delete.',
            },
            {
                ar: 'رسالة ai_knowledge_sources.ingested بتقول "اتفهرس" والفهرسة لسه في الـ queue. وpublished_at/effective_at مقبولين في الـ API بس مش في الفورم.',
                en: 'The ai_knowledge_sources.ingested toast says "indexed" while indexing is still queued; published_at/effective_at are accepted by the API but missing from the form.',
            },
        ],
    },
};
