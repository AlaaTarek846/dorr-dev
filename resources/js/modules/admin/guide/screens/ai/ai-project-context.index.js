/**
 * Guide: AI project context (admin.ai-project-context.index).
 * Source of truth checked: views/ai-project-context/index.vue, Modules/AI/routes/admin.php (admin/v1/ai-project-context),
 * Http/Controllers/AiProjectContextController.php, Services/AiProjectContextService.php,
 * Repositories/AiProjectContextRepository.php, Http/Resources/AiProjectContextResource.php,
 * Models/AiProjectContext.php (types), Models/AiProject.php (contextItems),
 * Services/AiUserProjectService.php (rememberCapsule writes, systemMessage reads),
 * Services/AiContextContinuationService.php (continueFrom — the context capsule),
 * migration 2026_09_22_190300_create_ai_project_context_table.php.
 */
export default {
    route: 'admin.ai-project-context.index',
    updated: '2026-11-11',

    ar: {
        summary: 'عرض لـ "ذاكرة المشاريع": ملخص المشروع والقرارات اللي اتاخدت فيه، بتتجمع من محادثات المشروع وبيقراها المساعد في كل رد جوه المشروع. الشاشة للعرض بس.',
        audience: 'المسؤول الفني. محتاج صلاحية "ذاكرة المشاريع: عرض".',
        purpose: [
            'اليوزر يقدر يعمل "مشاريع" في المساعد ويحط فيها محادثات وملفات. الجدول ده بيشيل ذاكرة طويلة لكل مشروع.',
            'بتتكتب لما اليوزر يستخدم "كمّل في محادثة جديدة" (كبسولة السياق) على محادثة أو أكتر تبع مشروع: هدف الشغل واللي اتعمل بيتحفظوا كـ summary (صف واحد للمشروع بيتحدّث)، وكل قرار بيتحفظ كـ decision (من غير تكرار، وآخر 30 قرار بس).',
            'المساعد بيقرا الملخص والقرارات دي في أي محادثة تبع المشروع، جنب وصف المشروع وتعليماته وملفاته.',
            'النوع constraint (قيد) موجود في الجدول بس محدش بيكتبه دلوقتي.',
        ],
        sections: [
            {
                title: 'الجدول (الأحدث فوق)',
                items: [
                    { name: 'المشروع', desc: 'اسم المشروع ورقمه.' },
                    { name: 'النوع', desc: 'summary (ملخص المشروع) أو decision (قرار). constraint مش بيتكتب لسه.' },
                    { name: 'المحتوى', desc: 'النص، ومقصوص لو طويل. الملخص لحد 2000 حرف، والقرار لحد 300.' },
                    { name: 'تاريخ الإنشاء', desc: 'وقت تسجيل العنصر (الملخص بيتحدّث في نفس الصف).' },
                    { name: 'الترقيم', desc: 'تحت الجدول: الصفحات وعدد الصفوف.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'تراجع ذاكرة مشروع',
                steps: ['افتح الشاشة.', 'دوّر على اسم المشروع واقرا الملخص والقرارات بتاعته.', 'لو مشروع ملوش صفوف، يبقى اليوزر لسه ما عملش كبسولة سياق من محادثاته.'],
            },
            {
                title: 'تفهم ليه المساعد "فاكر" قرار في مشروع',
                steps: ['دوّر على قرارات المشروع هنا.', 'المساعد بيقرا آخر 30 قرار مع الملخص في كل رد جوه المشروع.'],
            },
        ],
        affects: [
            { text: 'محتوى الشاشة دي بيدخل في رد المساعد لأي محادثة تبع المشروع.' },
            { route: 'admin.ai-project-instructions.index', text: 'تعليمات المشاريع: بتدخل في نفس رسالة النظام مع الذاكرة دي.' },
            { route: 'admin.ai-conversation-contexts.index', text: 'سياقات المحادثات: كبسولة السياق نفسها بتتحفظ هناك للمحادثة الجديدة.' },
        ],
        warnings: [
            { level: 'info', text: 'الذاكرة دي من كلام اليوزر ومحادثاته في مشروعه، ومش بتتشارك مع حد تاني.' },
            { level: 'info', text: 'مفيش بحث ولا فلتر ولا زراير هنا، ومفيش تعديل أو مسح من الأدمن. مسح المشروع بيمسح ذاكرته.' },
        ],
    },

    en: {
        summary: 'View of "project memory": the project summary and the decisions taken in it, gathered from the project\'s conversations and read by the assistant in every reply inside the project. Read-only.',
        audience: 'Technical owner. Needs "project context: view".',
        purpose: [
            'Users can create assistant "projects" holding conversations and files; this table holds long-term memory per project.',
            'It is written when the user runs "continue in a new chat" (the context capsule) on one or more conversations of a project: the goal and what was done are kept as the summary (one row per project, updated), and each decision as a decision row (no duplicates, newest 30 kept).',
            'The assistant reads this summary and these decisions in every conversation of the project, next to the project description, instructions and files.',
            'The constraint type exists in the table but nothing writes it today.',
        ],
        sections: [
            {
                title: 'Table (newest first)',
                items: [
                    { name: 'Project', desc: 'Project name and id.' },
                    { name: 'Type', desc: 'summary (project summary) or decision. constraint is not written yet.' },
                    { name: 'Content', desc: 'The text, truncated when long. Summary up to 2000 characters, decision up to 300.' },
                    { name: 'Created at', desc: 'When the item was recorded (the summary is updated in the same row).' },
                    { name: 'Pagination', desc: 'Pages and rows per page.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Review a project\'s memory',
                steps: ['Open the screen.', 'Find the project and read its summary and decisions.', 'A project with no rows means the user has not built a context capsule from its conversations yet.'],
            },
            {
                title: 'Understand why the assistant "remembers" a project decision',
                steps: ['Find the project\'s decisions here.', 'The assistant reads the newest 30 decisions and the summary in every reply inside the project.'],
            },
        ],
        affects: [
            { text: 'This content goes into the assistant\'s reply for every conversation of the project.' },
            { route: 'admin.ai-project-instructions.index', text: 'Project instructions: sent in the same system message as this memory.' },
            { route: 'admin.ai-conversation-contexts.index', text: 'Conversation contexts: the capsule itself is kept there for the new conversation.' },
        ],
        warnings: [
            { level: 'info', text: 'This memory comes from the user\'s own project conversations and is never shared with anyone else.' },
            { level: 'info', text: 'No search, filter or buttons here, and no admin edit/delete. Deleting the project deletes its memory.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-project-context/index.vue',
            'Modules/AI/app/Http/Controllers/AiProjectContextController.php (index, show)',
            'Modules/AI/app/Repositories/AiProjectContextRepository.php (with project, newest first)',
            'Modules/AI/app/Models/AiProjectContext.php, AiProject.php (contextItems)',
            'Writer: Modules/AI/app/Services/AiUserProjectService.php::rememberCapsule() ← AiContextContinuationService::continueFrom()',
            'Reader: AiUserProjectService::systemMessage() (summary + newest MAX_CONTEXT_DECISIONS = 30 decisions)',
        ],
        api: ['GET /api/admin/v1/ai-project-context', 'GET /api/admin/v1/ai-project-context/{context}'],
        tables: ['ai_project_context (project_id, context_type, content)', 'ai_projects', 'ai_project_conversations'],
        permissions: ['ai-project-context.view (screen and list; no write actions)'],
        decisions: [
            {
                ar: 'الذاكرة بتتكتب بس من كبسولة السياق (مش بعد كل رسالة) علشان متكلفش مكالمة موديل زيادة في كل رد. الجدول ملوش conversation_id، فالملخص واحد للمشروع كله.',
                en: 'Memory is written only from the context capsule (not after every message) to avoid an extra model call per reply. The table has no conversation_id, so there is one summary per project.',
            },
        ],
    },
};
