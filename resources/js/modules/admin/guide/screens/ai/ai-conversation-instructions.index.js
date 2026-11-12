/**
 * Guide: AI conversation instructions (admin.ai-conversation-instructions.index) - system messages added to one
 * conversation.
 * Source of truth checked: views/ai-conversation-instructions/*.vue, composables/useAiPrivacyScreens.js
 * (useAiConversationInstructions), stores/aiPrivacyScreens.js, Modules/AI/app/Models/AiConversationInstruction.php
 * (+ Concerns/HasIsActiveAlias.php), Repositories/AiConversationInstructionRepository.php,
 * Http/Controllers/AiConversationInstructionController.php, Http/Requests/AiConversationInstructionRequest.php,
 * Http/Resources/AiConversationInstructionResource.php, Services/AiChatService.php (systemMessages), routes/admin.php.
 */
export default {
    route: 'admin.ai-conversation-instructions.index',
    updated: '2026-11-11',

    ar: {
        summary: 'هنا تعليمات خاصة بمحادثة معينة: كل تعليمة بتتبعت للذكاء الاصطناعي كرسالة نظام مع كل رسالة جديدة في المحادثة دي، الأعلى أولوية الأول.',
        audience: 'مسؤول الذكاء الاصطناعي أو الدعم. محتاج صلاحيات "تعليمات المحادثات": عرض، إضافة، تعديل، حذف، تغيير الحالة، حذف متعدد.',
        purpose: [
            'توجيه الذكاء الاصطناعي في محادثة واحدة (مثال: "جاوب بالعربي وخلي الشرح مختصر").',
            'التعليمة بتتبعت بعد تعليمات المشروع وقبل سياق المحادثة.',
        ],
        sections: [
            {
                title: 'شريط الأدوات',
                items: [
                    { name: 'البحث', desc: 'بنص التعليمة أو المصدر أو عنوان المحادثة.' },
                    { name: 'الكل / مفعّلة / متوقفة / المحذوفات', desc: 'فلترة سريعة بالأعداد.' },
                    { name: 'حذف المحدد / إضافة تعليمة', desc: 'حسب صلاحياتك.' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'المحادثة', desc: 'العنوان وتحته رقم المحادثة ورقم التعليمة. دوس عليه يفتح التعديل.' },
                    { name: 'المصدر', desc: 'اليوزر / النظام / قالب - للمعلومة بس.' },
                    { name: 'التعليمة / الأولوية', desc: 'النص (مقصوص؛ حط الماوس عليه تشوفه كله) والأولوية.' },
                    { name: 'الحالة / التاريخ / الأزرار', desc: 'زي شاشة الدول.' },
                ],
            },
            {
                title: 'فورم التعليمة',
                items: [
                    { name: 'رقم المحادثة', desc: 'إجباري، لازم يكون محادثة موجودة. مينفعش يتغيّر بعد الحفظ.' },
                    { name: 'المصدر', desc: 'اليوزر (الافتراضي) / النظام / قالب.' },
                    { name: 'التعليمة', desc: 'إجباري، لحد 5000 حرف. بتتبعت زي ما هي - مش بتتترجم.' },
                    { name: 'الأولوية', desc: '0 أو أكتر، فاضي = 0. الأعلى بيتبعت الأول.' },
                    { name: 'الحالة', desc: 'مقفولة = مش بتتبعت.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'خلّي الذكاء الاصطناعي يرد بالإنجليزي في محادثة',
                steps: ['دوس "إضافة تعليمة".', 'اكتب رقم المحادثة.', 'اكتب التعليمة ودوس حفظ - بتتطبق من الرسالة الجاية.'],
            },
            {
                title: 'وقف تعليمة مؤقتًا',
                steps: ['اقفل زرار الحالة.'],
            },
        ],
        affects: [
            { text: 'ردود الذكاء الاصطناعي في المحادثة دي بس (في التطبيقات وشات دُر).' },
        ],
        warnings: [
            { level: 'warning', text: 'نقل التعليمة للسلة زي إيقافها: مش بتتبعت. والحذف النهائي متاح من السلة في أي وقت.' },
            { level: 'warning', text: 'حذف المحادثة نفسها بيحذف كل تعليماتها للأبد (حتى اللي في السلة).' },
            { level: 'info', text: 'التعليمة بتروح للمزود زي ما هي، فمتكتبش فيها بيانات سرية.' },
        ],
    },

    en: {
        summary: 'Instructions for one conversation: each is sent to the AI as a system message with every new message of that conversation, the highest priority first.',
        audience: 'AI or support administrator. Needs the "Conversation instructions" permissions: view, create, update, delete, change status, multiple delete.',
        purpose: [
            'Steer the AI in a single conversation (e.g. "Answer in Arabic and keep explanations short").',
            'It is sent after the project instructions and before the conversation context.',
        ],
        sections: [
            {
                title: 'Toolbar',
                items: [
                    { name: 'Search', desc: 'By instruction text, source or conversation title.' },
                    { name: 'All / Active / Inactive / Trash', desc: 'Quick filters with counts.' },
                    { name: 'Delete selected / Add instruction', desc: 'Per your permissions.' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Conversation', desc: 'Title with the conversation id and the instruction id. Click to edit.' },
                    { name: 'Source', desc: 'User / System / Template - for reference only.' },
                    { name: 'Instruction / priority', desc: 'The text (cut; hover to see it all) and its priority.' },
                    { name: 'Status / dates / buttons', desc: 'Like countries.' },
                ],
            },
            {
                title: 'Instruction form',
                items: [
                    { name: 'Conversation ID', desc: 'Required, an existing conversation. Cannot be changed after saving.' },
                    { name: 'Source', desc: 'User (default) / System / Template.' },
                    { name: 'Instruction', desc: 'Required, up to 5000 chars. Sent as it is - not translated.' },
                    { name: 'Priority', desc: '0 or more, empty = 0. Higher is sent first.' },
                    { name: 'Status', desc: 'Off = not sent.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Make the AI answer in English in one conversation',
                steps: ['Click "Add instruction".', 'Type the conversation id.', 'Type the instruction and save - it applies from the next message.'],
            },
            {
                title: 'Pause an instruction',
                steps: ['Switch its status off.'],
            },
        ],
        affects: [
            { text: 'The AI replies in that conversation only (apps and DORR chat).' },
        ],
        warnings: [
            { level: 'warning', text: 'Moving an instruction to the trash is the same as switching it off: it is not sent. Delete for good is always allowed from the trash.' },
            { level: 'warning', text: 'Deleting the conversation itself deletes all its instructions for good (the trash included).' },
            { level: 'info', text: 'The instruction goes to the provider as it is - do not put secrets in it.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-conversation-instructions/index.vue (countries-style list, no translations)',
            'resources/js/modules/admin/themes/theme-1/views/ai-conversation-instructions/ModalCreateAndUpdate.vue',
            'resources/js/composables/useAiPrivacyScreens.js (useAiConversationInstructions: search in instruction, source_type + conversation title), stores/aiPrivacyScreens.js',
            'Modules/AI/app/Http/Controllers/AiConversationInstructionController.php (CatalogController; guarded by admin.screen on the route group)',
            'Modules/AI/app/Repositories/AiConversationInstructionRepository.php (BaseRepository; priority desc)',
            'Modules/AI/app/Models/AiConversationInstruction.php (SoftDeletes, status, HasIsActiveAlias)',
            'Modules/AI/app/Services/AiChatService.php (systemMessages: status = true, priority desc, trash excluded)',
        ],
        api: [
            'GET|POST       /api/admin/v1/ai-conversation-instructions            (?status_counts=1, search, trashed)',
            'GET|PUT|DELETE /api/admin/v1/ai-conversation-instructions/{instruction}',
            'PATCH          /api/admin/v1/ai-conversation-instructions/{instruction}/status',
            'POST           /api/admin/v1/ai-conversation-instructions/delete-multiple',
            'POST           /api/admin/v1/ai-conversation-instructions/{instruction}/restore',
            'DELETE         /api/admin/v1/ai-conversation-instructions/{instruction}/force',
        ],
        tables: ['ai_conversation_instructions (conversation_id, source_type, instruction, priority, status, deleted_at)'],
        permissions: [
            'ai-conversation-instructions.view (screen, list, show)',
            'ai-conversation-instructions.create · ai-conversation-instructions.update (edit, restore)',
            'ai-conversation-instructions.delete (trash, force delete) · ai-conversation-instructions.change-status · ai-conversation-instructions.multiple-delete',
        ],
        noTranslatableText: 'The instruction is a prompt sent to the AI word for word (technical, never translated); the rest is a conversation id, a source code and a priority.',
        decisions: [
            {
                ar: 'مفيش جدول ترجمة: نص التعليمة برومبت للذكاء الاصطناعي، والمالك قرر إنه ميتترجمش.',
                en: 'No translation table: the instruction is an AI prompt, which the owner decided is never translated.',
            },
        ],
    },
};
