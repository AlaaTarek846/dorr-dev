/**
 * Guide: AI project instructions (admin.ai-project-instructions.index) - standing instructions of a person's AI project.
 * Source of truth checked: views/ai-project-instructions/*.vue, composables/useAiPrivacyScreens.js
 * (useAiProjectInstructions), stores/aiPrivacyScreens.js, Modules/AI/app/Models/AiProjectInstruction.php
 * (+ Concerns/HasIsActiveAlias.php), Repositories/AiProjectInstructionRepository.php,
 * Http/Controllers/AiProjectInstructionController.php, Http/Requests/AiProjectInstructionRequest.php,
 * Http/Resources/AiProjectInstructionResource.php, Services/AiUserProjectService.php (list, show, systemMessage,
 * setInstructions), Services/AiChatService.php (exportOwnerData), routes/admin.php.
 */
export default {
    route: 'admin.ai-project-instructions.index',
    updated: '2026-11-11',

    ar: {
        summary: 'هنا التعليمات الثابتة لمشاريع الذكاء الاصطناعي بتاعة الناس: كل تعليمة بتتبعت للذكاء الاصطناعي في كل محادثة جوه المشروع، وبتظهر لصاحب المشروع في مشروعه.',
        audience: 'مسؤول الذكاء الاصطناعي أو الدعم. محتاج صلاحيات "تعليمات المشاريع": عرض، إضافة، تعديل، حذف، تغيير الحالة، حذف متعدد.',
        purpose: [
            'صاحب المشروع بيكتب تعليمة واحدة من التطبيق؛ الشاشة دي بتوريك كل التعليمات وبتخليك توقف أو تعدّل أو تضيف.',
            'التعليمات بتتبعت جوه رسالة المشروع، الأعلى أولوية الأول، ولحد 2000 حرف من كل تعليمة.',
        ],
        sections: [
            {
                title: 'شريط الأدوات',
                items: [
                    { name: 'البحث', desc: 'بنص التعليمة أو اسم المشروع.' },
                    { name: 'الكل / مفعّلة / متوقفة / المحذوفات', desc: 'فلترة سريعة بالأعداد.' },
                    { name: 'حذف المحدد / إضافة تعليمة', desc: 'حسب صلاحياتك.' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'المشروع', desc: 'الاسم وتحته رقم المشروع ورقم التعليمة. دوس عليه يفتح التعديل.' },
                    { name: 'التعليمة / الأولوية', desc: 'النص (مقصوص؛ حط الماوس عليه تشوفه كله) والأولوية.' },
                    { name: 'الحالة / التاريخ / الأزرار', desc: 'زي شاشة الدول.' },
                ],
            },
            {
                title: 'فورم التعليمة',
                items: [
                    { name: 'رقم المشروع', desc: 'إجباري، لازم يكون مشروع موجود. مينفعش يتغيّر بعد الحفظ.' },
                    { name: 'الأولوية', desc: '0 أو أكتر، فاضي = 0.' },
                    { name: 'التعليمة', desc: 'إجباري، لحد 5000 حرف. بتتبعت زي ما هي - مش بتتترجم.' },
                    { name: 'الحالة', desc: 'مقفولة = مش بتتبعت ومش بتظهر في مشروع صاحبه.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'وقف تعليمة مخالفة',
                steps: ['دوّر عليها بالنص أو اسم المشروع.', 'اقفل زرار الحالة - بتبطل تتبعت فورًا.'],
            },
        ],
        affects: [
            { text: 'ردود الذكاء الاصطناعي في كل محادثات المشروع، وشاشة المشروع عند صاحبه في التطبيق، وتصدير بياناته.' },
        ],
        warnings: [
            { level: 'warning', text: 'لما صاحب المشروع يعدّل تعليمة مشروعه من التطبيق، كل تعليمات المشروع (حتى المتوقفة واللي في السلة) بتتمسح للأبد وبتتحط تعليمته الجديدة مكانها.' },
            { level: 'warning', text: 'نقل التعليمة للسلة زي إيقافها. وحذف المشروع بيحذف تعليماته للأبد.' },
            { level: 'info', text: 'التطبيق بيعرض لصاحب المشروع أول تعليمة مفعّلة بس.' },
        ],
    },

    en: {
        summary: 'Standing instructions of people\'s AI projects: each is sent to the AI in every conversation of the project, and shown to the owner in their project.',
        audience: 'AI or support administrator. Needs the "Project instructions" permissions: view, create, update, delete, change status, multiple delete.',
        purpose: [
            'The owner writes one instruction from the app; this screen shows every instruction and lets you pause, edit or add.',
            'Instructions go inside the project message, the highest priority first, up to 2000 chars of each.',
        ],
        sections: [
            {
                title: 'Toolbar',
                items: [
                    { name: 'Search', desc: 'By instruction text or project name.' },
                    { name: 'All / Active / Inactive / Trash', desc: 'Quick filters with counts.' },
                    { name: 'Delete selected / Add instruction', desc: 'Per your permissions.' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Project', desc: 'Name with the project id and the instruction id. Click to edit.' },
                    { name: 'Instruction / priority', desc: 'The text (cut; hover to see it all) and its priority.' },
                    { name: 'Status / dates / buttons', desc: 'Like countries.' },
                ],
            },
            {
                title: 'Instruction form',
                items: [
                    { name: 'Project ID', desc: 'Required, an existing project. Cannot be changed after saving.' },
                    { name: 'Priority', desc: '0 or more, empty = 0.' },
                    { name: 'Instruction', desc: 'Required, up to 5000 chars. Sent as it is - not translated.' },
                    { name: 'Status', desc: 'Off = not sent and not shown in the owner\'s project.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Stop an abusive instruction',
                steps: ['Find it by text or project name.', 'Switch its status off - it stops being sent at once.'],
            },
        ],
        affects: [
            { text: 'The AI replies in every conversation of the project, the owner\'s project screen in the app, and their data export.' },
        ],
        warnings: [
            { level: 'warning', text: 'When the owner edits the project\'s instruction from the app, every instruction of the project (switched-off and trashed ones too) is deleted for good and replaced by the new one.' },
            { level: 'warning', text: 'Moving an instruction to the trash is the same as switching it off. Deleting the project deletes its instructions for good.' },
            { level: 'info', text: 'The app shows the owner only the first active instruction.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-project-instructions/index.vue (countries-style list, no translations)',
            'resources/js/modules/admin/themes/theme-1/views/ai-project-instructions/ModalCreateAndUpdate.vue',
            'resources/js/composables/useAiPrivacyScreens.js (useAiProjectInstructions: search in instruction + project name), stores/aiPrivacyScreens.js',
            'Modules/AI/app/Http/Controllers/AiProjectInstructionController.php (CatalogController; guarded by admin.screen on the route group)',
            'Modules/AI/app/Repositories/AiProjectInstructionRepository.php (BaseRepository; priority desc)',
            'Modules/AI/app/Models/AiProjectInstruction.php (SoftDeletes, status, HasIsActiveAlias)',
            'Modules/AI/app/Services/AiUserProjectService.php (status = true in list/show/systemMessage; setInstructions force-deletes)',
        ],
        api: [
            'GET|POST       /api/admin/v1/ai-project-instructions            (?status_counts=1, search, trashed)',
            'GET|PUT|DELETE /api/admin/v1/ai-project-instructions/{instruction}',
            'PATCH          /api/admin/v1/ai-project-instructions/{instruction}/status',
            'POST           /api/admin/v1/ai-project-instructions/delete-multiple',
            'POST           /api/admin/v1/ai-project-instructions/{instruction}/restore',
            'DELETE         /api/admin/v1/ai-project-instructions/{instruction}/force',
        ],
        tables: ['ai_project_instructions (project_id, instruction, priority, status, deleted_at)'],
        permissions: [
            'ai-project-instructions.view (screen, list, show)',
            'ai-project-instructions.create · ai-project-instructions.update (edit, restore)',
            'ai-project-instructions.delete (trash, force delete) · ai-project-instructions.change-status · ai-project-instructions.multiple-delete',
        ],
        noTranslatableText: 'The instruction is a prompt sent to the AI word for word (technical, never translated); the rest is a project id and a priority.',
        decisions: [
            {
                ar: 'مفيش جدول ترجمة: نص التعليمة برومبت للذكاء الاصطناعي، والمالك قرر إنه ميتترجمش.',
                en: 'No translation table: the instruction is an AI prompt, which the owner decided is never translated.',
            },
            {
                ar: 'تعديل صاحب المشروع لتعليمته بيمسح القديمة للأبد (زي قبل السلة) عشان السلة متتملاش نسخ.',
                en: 'The owner\'s edit replaces the old instructions for good (as before the trash) so the trash never fills with copies.',
            },
        ],
    },
};
