/**
 * Guide: AI conversation contexts (admin.ai-conversation-contexts.index).
 * Source of truth checked: views/ai-conversation-contexts/index.vue, Modules/AI/routes/admin.php
 * (admin/v1/ai-conversation-contexts), Http/Controllers/AiConversationContextController.php,
 * Services/AiConversationContextService.php, Repositories/AiConversationContextRepository.php,
 * Http/Resources/AiConversationContextResource.php, Models/AiConversationContext.php,
 * Services/AiContextContinuationService.php (the only writer), Services/AiChatService.php (included contexts → system messages),
 * database/migrations/2026_09_22_150100_create_ai_conversation_contexts_table.php.
 */
export default {
    route: 'admin.ai-conversation-contexts.index',
    updated: '2026-11-10',

    ar: {
        summary: 'قطع سياق إضافية مربوطة بمحادثة مع المساعد، والمساعد بيقراها قبل كل رد في المحادثة دي. دلوقتي النوع الوحيد اللي بيتكتب فعلًا هو "كبسولة السياق" من ميزة "كمّل في محادثة جديدة". الشاشة للقراءة بس.',
        audience: 'المسؤول الفني أو الدعم. محتاج صلاحية "ai-conversation-contexts.view" (عرض بس).',
        purpose: [
            'ميزة "كمّل في محادثة جديدة": اليوزر بيختار لحد 5 من محادثاته، والمساعد بيلخصهم في كبسولة (الهدف، القرارات، المعلومات المهمة، الملفات، المهام الباقية، اللي اتعمل). الكبسولة بتتحفظ هنا كصف نوعه context_capsule ومربوط بالمحادثة الجديدة.',
            'أي صف "متضمن في الطلب" بيتبعت للموديل كتعليمة نظام مع كل رسالة في المحادثة دي.',
            'اليوزر يقدر يشوف الكبسولة ويمسحها من التطبيق في أي وقت. لو مسحها، الصف بيختفي من هنا.',
            'تفيدك لو يوزر بيشتكي إن المساعد "فاكر" حاجات قديمة أو بيرد على حاجة مش في المحادثة.',
        ],
        sections: [
            {
                title: 'الجدول (الأحدث فوق)',
                items: [
                    { name: 'المحادثة', desc: 'عنوان المحادثة ورقمها (#). لو المحادثة اتمسحت، الصفوف بتاعتها بتتمسح معاها.' },
                    { name: 'نوع السياق', desc: 'دلوقتي عمليًا context_capsule بس. الأنواع message_history وsummary وsystem_instruction وattachment وmemory وexternal متعرّفة في الكود بس مفيش حاجة بتكتبها.' },
                    { name: 'متضمن في الطلب', desc: 'نعم = بيتبعت للموديل مع كل رد. لا = محفوظ بس مش بيتبعت. الكبسولة دايمًا نعم.' },
                    { name: 'عدد التوكنز', desc: 'المفروض حجم القطعة في الطلب. الكبسولة مش بتحسبه، فهيظهر فاضي.' },
                    { name: 'التاريخ', desc: 'وقت إنشاء القطعة (يعني وقت ما اليوزر عمل "كمّل في محادثة جديدة").' },
                ],
            },
            {
                title: 'أسفل الجدول',
                items: [
                    { name: 'الصفحات وعدد الصفوف', desc: 'تنقّل بين الصفحات وتختار كام صف في الصفحة. مفيش بحث ولا فلاتر.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'يوزر بيقول المساعد بيرد بحاجات من محادثة قديمة',
                steps: [
                    'اسأل اليوزر عن عنوان المحادثة أو رقمها.',
                    'دوّر عليها هنا في عمود "المحادثة".',
                    'لو ليها صف context_capsule "متضمن"، يبقى المحادثة دي اتعملت من "كمّل في محادثة جديدة" والمساعد بيقرا ملخص المحادثات القديمة.',
                    'لو اليوزر مش عايز كده، يقدر يمسح الكبسولة من التطبيق نفسه (الشاشة دي مفيهاش مسح).',
                ],
            },
        ],
        affects: [
            { text: 'شات المساعد عند اليوزر ومقدم الخدمة والكابتن: كل صف متضمن بيتقرا قبل كل رد في المحادثة بتاعته.' },
            { route: 'admin.ai-conversation-instructions.index', text: 'تعليمات المحادثات: نوع تاني من التعليمات اللي بتتبعت للموديل في نفس المحادثة.' },
            { route: 'admin.ai-requests.index', text: 'طلبات الذكاء الاصطناعي: بناء الكبسولة نفسه طلب للمزود (المصدر ai.capsule).' },
        ],
        warnings: [
            { level: 'info', text: 'الجدول مش بيعرض محتوى الكبسولة نفسه، مع إن الـ API بيرجّعه. ممكن يكون فيه بيانات شخصية من محادثات اليوزر.' },
            { level: 'info', text: 'الصفوف بتتمسح لوحدها لما المحادثة تتمسح، أو لما اليوزر يمسح الكبسولة. ومش داخلة في أمر الاحتفاظ بالبيانات.' },
        ],
    },

    en: {
        summary: 'Extra pieces of context attached to an assistant conversation; the assistant reads them before every reply in that conversation. Today the only kind actually written is the "context capsule" from "Continue in a new chat". Read-only.',
        audience: 'Technical admin or support. Needs "ai-conversation-contexts.view" (view only).',
        purpose: [
            '"Continue in a new chat": the user picks up to 5 of their conversations and the assistant summarises them into a capsule (goal, decisions, facts, files, open tasks, done). It is stored here as a context_capsule row on the new conversation.',
            'Every row marked "Included" is sent to the model as a system message with each message in that conversation.',
            'The user can view and delete the capsule from the app at any time; deleting it removes the row here.',
            'Useful when a user says the assistant "remembers" old things or answers about something not in the chat.',
        ],
        sections: [
            {
                title: 'Table (newest first)',
                items: [
                    { name: 'Conversation', desc: 'Conversation title and number (#). Deleting the conversation deletes its rows.' },
                    { name: 'Context type', desc: 'In practice only context_capsule. message_history, summary, system_instruction, attachment, memory and external are defined in code but nothing writes them.' },
                    { name: 'Included', desc: 'Yes = sent to the model with every reply. No = stored but not sent. A capsule is always Yes.' },
                    { name: 'Token count', desc: 'Meant to be the piece\'s size in the prompt. Capsules do not set it, so it shows empty.' },
                    { name: 'Date', desc: 'When it was created (i.e. when the user used "Continue in a new chat").' },
                ],
            },
            {
                title: 'Below the table',
                items: [
                    { name: 'Pages and rows per page', desc: 'Move between pages and pick the page size. No search or filters.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'A user says the assistant brings up an old conversation',
                steps: [
                    'Ask the user for the conversation title or number.',
                    'Find it here in the "Conversation" column.',
                    'An included context_capsule row means the chat was created with "Continue in a new chat" and the assistant reads a summary of the old chats.',
                    'If the user does not want that, they can delete the capsule in the app (this screen has no delete).',
                ],
            },
        ],
        affects: [
            { text: 'The assistant chat for users, providers and drivers: every included row is read before each reply in its conversation.' },
            { route: 'admin.ai-conversation-instructions.index', text: 'Conversation instructions: another kind of instruction sent to the model in the same conversation.' },
            { route: 'admin.ai-requests.index', text: 'AI requests: building the capsule is itself a provider request (source ai.capsule).' },
        ],
        warnings: [
            { level: 'info', text: 'The table does not show the capsule content, though the API returns it; it can hold personal data from the user\'s chats.' },
            { level: 'info', text: 'Rows go away when the conversation is deleted or the user deletes the capsule; they are not covered by the retention command.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-conversation-contexts/index.vue',
            'Modules/AI/app/Http/Controllers/AiConversationContextController.php',
            'Modules/AI/app/Services/AiConversationContextService.php',
            'Modules/AI/app/Repositories/AiConversationContextRepository.php',
            'Modules/AI/app/Http/Resources/AiConversationContextResource.php',
            'Modules/AI/app/Models/AiConversationContext.php',
            'Modules/AI/app/Services/AiContextContinuationService.php',
            'Modules/AI/app/Services/AiChatService.php',
        ],
        api: [
            'GET /api/admin/v1/ai-conversation-contexts (paginated, newest first; with conversation)',
            'GET /api/admin/v1/ai-conversation-contexts/{context}',
        ],
        tables: ['ai_conversation_contexts', 'ai_conversations'],
        permissions: ['ai-conversation-contexts.view'],
        decisions: [
            {
                ar: 'الكود بيكتب نوع واحد بس (context_capsule) ومش بيملا token_count. الأنواع التانية متعرّفة ومش مستخدمة، والنص الفرعي للشاشة بيوصفها كأنها "قطع السياق المختارة لكل محادثة". NEEDS-DECISION: نسيب الأنواع للمستقبل ولا نصحح النص؟',
                en: 'Only context_capsule is written and token_count is never set; the other types are defined but unused, while the screen subtitle describes "context pieces selected for every conversation". NEEDS-DECISION: keep the types for later, or fix the wording?',
            },
            {
                ar: 'الشاشة مش بتعرض صاحب المحادثة ولا محتوى السياق، فالدعم مش هيقدر يدوّر بيوزر معيّن. مفيش بحث ولا فلتر.',
                en: 'The screen shows neither the conversation owner nor the content, so support cannot look up a given user; there is no search or filter.',
            },
        ],
    },
};
