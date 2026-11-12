/**
 * Guide: AI code executions (admin.ai-code-executions.index).
 * Source of truth checked: views/ai-code-executions/index.vue (list + details modal), Modules/AI/routes/admin.php
 * (ai-code-executions), Http/Controllers/AiCodeExecutionController.php, Services/AiCodeExecutionRecordService.php,
 * Repositories/AiCodeExecutionRepository.php, Http/Resources/AiCodeExecutionResource.php, Models/AiCodeExecution.php,
 * Services/AiChatService.php (sandbox loop when the domain policy has sandbox_required),
 * Services/AiDomainPipelineService.php (extractCodeBlock, runSandboxAttempt), Services/AiSandboxRunner.php,
 * Modules/AI/config/config.php (ai.sandbox.*), migration create_ai_code_executions_table (nullOnDelete).
 */
export default {
    route: 'admin.ai-code-executions.index',
    updated: '2026-11-11',

    ar: {
        summary: 'لما المساعد يكتب كود في موضوع برمجة، النظام مش بيصدّق إن الكود شغال: بيشغّله فعلًا جوه بيئة معزولة (Docker من غير إنترنت) ويسجّل النتيجة هنا. لو فشل، المساعد بيصلّحه بالخطأ الحقيقي ويجرب تاني.',
        audience: 'المسؤول الفني. محتاج صلاحية "ai-code-executions.view" (عرض بس).',
        purpose: [
            'تتأكد إن الكود اللي المساعد بيديه لليوزر اتجرب فعلًا.',
            'تعرف لو بيئة التشغيل (Docker) واقفة أو مش متاحة على السيرفر.',
            'تشوف الكود ومخرجاته وأخطاءه لما يوزر يشتكي إن الكود مش شغال.',
        ],
        sections: [
            {
                title: 'إمتى بيتعمل صف',
                items: [
                    { name: 'الشرط', desc: 'سياسة المجال اللي الرسالة اتطابقت معاها لازم يكون فيها "sandbox_required" (شاشة سياسات المجالات)، والرد يكون فيه بلوك كود.' },
                    { name: 'أنهي كود', desc: 'أول بلوك كود في الرد بس. اللغات المدعومة: php وnode (js) وpython. بلوك من غير لغة بيتعامل كـ php. لغة تانية = مفيش تشغيل ومفيش صف.' },
                    { name: 'المحاولات', desc: 'لو التشغيل فشل، المساعد بيصلّح الكود بالخطأ ويتجرب تاني، لحد محاولتين (الإعداد الافتراضي). كل محاولة صف لوحدها.' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'الطلب', desc: 'رقم الطلب في شاشة الطلبات. "-" لو الطلب اتمسح بسياسة الاحتفاظ (الصف نفسه بيفضل).' },
                    { name: 'اللغة', desc: 'php أو node أو python.' },
                    { name: 'المحرك', desc: 'DockerSandboxDriver = اتشغّل في Docker. none = التشغيل مقفول من إعدادات السيرفر.' },
                    { name: 'المحاولة', desc: '1 للتشغيل الأول، 2 بعد التصحيح.' },
                    { name: 'المدة', desc: 'وقت التشغيل بالمللي ثانية. "-" لو ماتشغّلش.' },
                    { name: 'كود الخروج', desc: 'الرقم اللي البرنامج خرج بيه: 0 (أخضر) = خلص من غير أخطاء، أي رقم تاني (أحمر) = خطأ. "-" لو ماتشغّلش (مقفول أو انتهت المهلة).' },
                    { name: 'الحالة', desc: 'اكتمل = اشتغل وخلص بكود خروج 0. فشل = طلّع خطأ. انتهت المهلة = عدّى الوقت المسموح (10 ثواني افتراضيًا). الـ Sandbox غير متاح = التشغيل مقفول أو Docker مش شغال.' },
                    { name: 'التاريخ', desc: 'الأحدث فوق.' },
                    { name: '👁 التفاصيل', desc: 'نافذة فيها الحالة وكود الخروج والمدة، والكود اللي اتشغل، والمخرجات (stdout)، والأخطاء (stderr). للقراءة بس.' },
                    { name: 'الترقيم', desc: 'رقم الصفحة وعدد الصفوف.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'كل الصفوف "الـ Sandbox غير متاح"',
                steps: [
                    'دوس 👁 على أي صف واقرا stderr.',
                    '"Sandbox execution is disabled" يبقى AI_SANDBOX_ENABLED مقفول في إعدادات السيرفر.',
                    '"Sandbox driver unavailable" يبقى Docker مش شغال أو السيرفر مش قادر يوصله. كلّم المسؤول عن السيرفر.',
                    'طول ما هو مش متاح، الكود بيوصل لليوزر من غير ما يتجرب.',
                ],
            },
            {
                title: 'يوزر بيقول الكود اللي خده مش شغال',
                steps: [
                    'هات رقم الطلب من شاشة الطلبات.',
                    'دوّر عليه هنا ودوس 👁 على آخر محاولة: اقرا الكود والأخطاء.',
                    'لو مفيش صف خالص: سياسة المجال مش طالبة تشغيل، أو اللغة مش مدعومة.',
                ],
            },
        ],
        affects: [
            { text: 'الشاشة للقراءة بس. التشغيل والحدود (الوقت، الذاكرة، المحاولات) من إعدادات السيرفر AI_SANDBOX_*.' },
            { route: 'admin.ai-domain-policies.index', text: 'سياسات المجالات: "sandbox_required" هو اللي بيشغّل الخطوة دي.' },
            { route: 'admin.ai-requests.index', text: 'طلبات الذكاء الاصطناعي: الطلب صاحب الكود.' },
            { route: 'admin.ai-responses.index', text: 'ردود الذكاء الاصطناعي: الرد اللي فيه الكود.' },
        ],
        warnings: [
            { level: 'warning', text: 'لو الـ Sandbox مش متاح، المساعد مش هيوقف: الرد بيوصل لليوزر بالكود من غير تجربة.' },
            { level: 'info', text: 'الكود بيتشغّل في container جديد من غير إنترنت وبحدود CPU وذاكرة، وبيتمسح بعد التشغيل.' },
            { level: 'info', text: 'الصفوف دي بتتمسح يوميًا بعد مدة الاحتفاظ في سياسة البيانات الشخصية، أو بعد مدة خاصة بيها لو المسؤول الفني حطها في السيرفر (AI_RETENTION_CODE_EXECUTIONS_DAYS).' },
        ],
    },

    en: {
        summary: 'When the assistant writes code on a programming topic, the system does not trust it works: it runs it in an isolated sandbox (Docker, no network) and logs the result here. On failure the assistant fixes it with the real error and tries again.',
        audience: 'Technical owner. Needs "ai-code-executions.view" (view-only).',
        purpose: [
            'Confirm code given to users was actually run.',
            'See when the sandbox (Docker) is down or unavailable on the server.',
            'Read the code, output and errors when a user says the code does not work.',
        ],
        sections: [
            {
                title: 'When a row is written',
                items: [
                    { name: 'Condition', desc: 'The matched domain policy has "sandbox_required" (Domain policies screen) and the reply contains a code block.' },
                    { name: 'Which code', desc: 'Only the first code block. Supported: php, node (js), python; a block without a language is treated as php. Any other language = no run, no row.' },
                    { name: 'Attempts', desc: 'On failure the assistant fixes the code with the error and it runs again, up to 2 by default; one row per attempt.' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Request', desc: 'Request id; "-" after the request was purged (the row stays).' },
                    { name: 'Language', desc: 'php, node or python.' },
                    { name: 'Driver', desc: 'DockerSandboxDriver = ran in Docker; none = sandbox disabled in server settings.' },
                    { name: 'Attempt', desc: '1 first run, 2 after a correction.' },
                    { name: 'Duration', desc: 'Run time in ms; "-" when it did not run.' },
                    { name: 'Exit code', desc: 'The number the program exited with: 0 (green) = finished without errors, anything else (red) = error. "-" when it did not run (disabled or timed out).' },
                    { name: 'Status', desc: 'Completed = exit code 0. Failed = error. Timeout = over the limit (10 s default). Sandbox unavailable = disabled or Docker unreachable.' },
                    { name: 'Date', desc: 'Newest first.' },
                    { name: '👁 Details', desc: 'Modal with the status, exit code and duration, the code, stdout and stderr. Read-only.' },
                    { name: 'Pagination', desc: 'Page and rows per page.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Every row says "Sandbox unavailable"',
                steps: ['Click 👁 on a row and read stderr.', '"Sandbox execution is disabled" = AI_SANDBOX_ENABLED is off on the server.', '"Sandbox driver unavailable" = Docker is not running or unreachable; contact the server owner.', 'Meanwhile code reaches users untested.'],
            },
            {
                title: 'A user says the code does not work',
                steps: ['Get the request id from AI requests.', 'Find it here and open 👁 on the last attempt: read code and errors.', 'No row at all: the domain policy does not require a run, or the language is unsupported.'],
            },
        ],
        affects: [
            { text: 'Read-only. On/off and limits (time, memory, attempts) are server settings AI_SANDBOX_*.' },
            { route: 'admin.ai-domain-policies.index', text: 'Domain policies: "sandbox_required" turns this step on.' },
            { route: 'admin.ai-requests.index', text: 'AI requests: the request the code belongs to.' },
            { route: 'admin.ai-responses.index', text: 'AI responses: the reply containing the code.' },
        ],
        warnings: [
            { level: 'warning', text: 'When the sandbox is unavailable the assistant does not stop: the reply reaches the user with untested code.' },
            { level: 'info', text: 'Code runs in a fresh container with no network and CPU/memory limits, removed afterwards.' },
            { level: 'info', text: 'These rows are purged daily after the personal-data policy retention, or after their own window when the server sets AI_RETENTION_CODE_EXECUTIONS_DAYS.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-code-executions/index.vue (details modal: code, stdout, stderr)',
            'Modules/AI/app/Http/Controllers/AiCodeExecutionController.php → Services/AiCodeExecutionRecordService.php',
            'Modules/AI/app/Repositories/AiCodeExecutionRepository.php (with request, conversation)',
            'Modules/AI/app/Http/Resources/AiCodeExecutionResource.php',
            'Writers: Services/AiSandboxRunner.php::execute via AiDomainPipelineService::runSandboxAttempt, called from AiChatService',
            'Modules/AI/config/config.php → ai.sandbox (enabled, driver docker, timeout_seconds 10, memory 256m, cpus 0.5, max_correction_attempts 2, languages php/node/python)',
        ],
        api: ['GET /api/admin/v1/ai-code-executions (page, per_page)', 'GET /api/admin/v1/ai-code-executions/{execution} (not used by the screen)'],
        tables: ['ai_code_executions (request_id / conversation_id nullOnDelete, language, driver, code, status, exit_code, stdout, stderr, duration_ms, attempt_number)'],
        permissions: ['ai-code-executions.view (view-only group)'],
        decisions: [
            { ar: 'الكود ومخرجاته بيتحفظوا كاملين (ممكن يكون فيهم بيانات من رسالة اليوزر)، وبيتمسحوا بأمر ai:enforce-retention بمدة config ai.retention.code_executions_days (فاضية = مدة السياسة). والحالة pending مش بتتكتب.', en: 'Code and output are stored in full (they may hold data from the user message) and are purged by ai:enforce-retention after config ai.retention.code_executions_days (empty = the policy window). pending is never written.' },
        ],
    },
};
