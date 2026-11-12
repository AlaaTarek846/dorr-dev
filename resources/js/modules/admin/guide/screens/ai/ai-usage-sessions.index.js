/**
 * Guide: AI usage sessions log (admin.ai-usage-sessions.index).
 * Source of truth checked: views/ai-usage-sessions/index.vue, Modules/AI/routes/admin.php (admin/v1/ai-usage-sessions),
 * Http/Controllers/AiUsageSessionController.php, Services/AiUsageSessionService.php,
 * Repositories/AiUsageSessionRepository.php (with owner + subscription.plan, newest first),
 * Http/Resources/AiUsageSessionResource.php, Services/AiChatUsageGuard.php (evaluate, recordConsumption).
 */
export default {
    route: 'admin.ai-usage-sessions.index',
    updated: '2026-11-10',

    ar: {
        summary: 'سجل قراءة بس لكل "نافذة استخدام" للمساعد الذكي: مين استخدم، على أنهي خطة، بدأ إمتى وخلص إمتى، وقعد قد إيه. النظام هو اللي بيكتب السجل ده، محدش بيضيفه بإيده.',
        audience: 'فريق الدعم والمنتج. محتاج صلاحية "جلسات استخدام الذكاء الاصطناعي: عرض" بس، ومفيش أي تعديل في الشاشة.',
        purpose: [
            'ترد على عميل بيسأل "دقايقي خلصت ليه؟".',
            'تفهم الاستخدام الفعلي لكل خطة: الناس بتستهلك الدقايق كلها ولا لأ.',
            'تتأكد إن وقت الانتظار بين النوافذ شغال زي ما الخطة بتقول.',
        ],
        sections: [
            {
                title: 'الجدول (الأحدث فوق)',
                items: [
                    { name: 'صاحب الجلسة', desc: 'اسم اليوزر أو مقدم الخدمة أو الكابتن ورقمه.' },
                    { name: 'الخطة', desc: 'خطة الاشتراك اللي الجلسة اتحسبت عليه. "-" لو الاشتراك أو الخطة اتمسحوا.' },
                    { name: 'بداية الجلسة', desc: 'أول رسالة بعد ما نافذة جديدة اتفتحت. الوقت بيتحسب من هنا، مش من وقت الكتابة الفعلي.' },
                    { name: 'نهاية الجلسة', desc: 'إمتى النافذة اتقفلت. "-" يعني الجلسة لسه مفتوحة.' },
                    { name: 'المدة', desc: 'دقايق وثواني من البداية لحد آخر رسالة (للجلسة المفتوحة) أو لحد القفل. "0m 0s" يعني لسه مفيش رسالة اتحسبت.' },
                ],
            },
            {
                title: 'الترقيم',
                items: [
                    { name: 'الصفحات وعدد الصفوف', desc: 'تحت الجدول. مفيش بحث ولا فلاتر في الشاشة دي.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'عميل بيقول دقايقه خلصت بسرعة',
                steps: [
                    'دوّر على اسمه في الجدول (الأحدث فوق، قلّب الصفحات).',
                    'قارن بداية ونهاية آخر جلسة بدقائق الاستخدام في خطته.',
                    'النافذة بتعد من أول رسالة، حتى لو العميل ساب الشات مفتوح من غير ما يكتب. لو ده اللي حصل، اشرحله.',
                    'لو عايز تعوّضه، مد اشتراكه من "اشتراكات الذكاء الاصطناعي".',
                ],
            },
            {
                title: 'العميل بيقول المساعد بيقوله استنى',
                steps: [
                    'شوف نهاية آخر جلسة ليه.',
                    'ضيف عليها "دقائق الانتظار" بتاعة خطته: قبل الوقت ده النافذة الجديدة مش هتفتح.',
                ],
            },
        ],
        affects: [
            { text: 'الشاشة دي بتعرض بس ومش بتغيّر حاجة.' },
            { route: 'admin.ai-plans.index', text: 'دقائق الاستخدام والانتظار في الخطة هي اللي بتحدد إمتى الجلسة تتقفل وإمتى تفتح جديدة.' },
            { route: 'admin.ai-subscriptions.index', text: 'كل جلسة مربوطة باشتراك من هناك.' },
        ],
        warnings: [
            { level: 'info', text: 'الجلسة اللي عدّت دقايقها مش بتتقفل في نفس اللحظة: بتتقفل أول ما العميل يحاول يستخدم المساعد تاني. علشان كده ممكن تلاقي جلسة مفتوحة بقالها وقت طويل.' },
            { level: 'info', text: 'في الخطط اللي دقائقها 0 (من غير حد) الجلسة مش بتتقفل أبدًا.' },
        ],
    },

    en: {
        summary: 'Read-only log of every assistant "usage window": who used it, on which plan, when it started and ended, and how long. The system writes it; nobody adds rows by hand.',
        audience: 'Support and product. Needs only "AI usage sessions: view"; nothing can be changed here.',
        purpose: [
            'Answer "why did my minutes run out?".',
            'See real usage per plan: do people use all their minutes?',
            'Check that the cooldown between windows behaves as the plan says.',
        ],
        sections: [
            {
                title: 'Table (newest first)',
                items: [
                    { name: 'Session owner', desc: 'User, provider or captain name and id.' },
                    { name: 'Plan', desc: 'Plan of the subscription the session counted against; "-" if it was removed.' },
                    { name: 'Started at', desc: 'First message after a new window opened. Time counts from here, not from actual typing.' },
                    { name: 'Ended at', desc: 'When the window closed; "-" = still open.' },
                    { name: 'Duration', desc: 'Minutes and seconds from start to the last message (open session) or to close. "0m 0s" = no message counted yet.' },
                ],
            },
            {
                title: 'Pagination',
                items: [
                    { name: 'Pages and rows per page', desc: 'Under the table. There is no search or filter on this screen.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'A customer says their minutes ran out too fast',
                steps: ['Find them in the table (newest first; page through).', 'Compare the last session’s start and end with their plan’s usage minutes.', 'The window counts from the first message even if the chat sat idle; explain that if so.', 'To compensate, extend their subscription in "AI subscriptions".'],
            },
            {
                title: 'The assistant tells the customer to wait',
                steps: ['Look at their last session’s end.', 'Add their plan’s cooldown minutes: no new window opens before that.'],
            },
        ],
        affects: [
            { text: 'This screen only shows data and changes nothing.' },
            { route: 'admin.ai-plans.index', text: 'The plan’s usage and cooldown minutes decide when a session closes and a new one opens.' },
            { route: 'admin.ai-subscriptions.index', text: 'Every session belongs to a subscription there.' },
        ],
        warnings: [
            { level: 'info', text: 'A session past its minutes is not closed at that instant: it closes the next time the customer tries the assistant, so a long-open session is normal.' },
            { level: 'info', text: 'On plans with 0 usage minutes (unlimited) the session never closes.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-usage-sessions/index.vue',
            'Modules/AI/app/Http/Controllers/AiUsageSessionController.php',
            'Modules/AI/app/Services/AiUsageSessionService.php, Repositories/AiUsageSessionRepository.php',
            'Modules/AI/app/Http/Resources/AiUsageSessionResource.php',
            'Modules/AI/app/Services/AiChatUsageGuard.php (evaluate opens/closes, recordConsumption updates duration)',
        ],
        api: [
            'GET /api/admin/v1/ai-usage-sessions          (page, per_page; ordered by started_at desc)',
            'GET /api/admin/v1/ai-usage-sessions/{usageSession}',
        ],
        tables: ['ai_usage_sessions (owner_type, owner_id, subscription_id, started_at, ended_at, duration_seconds)'],
        permissions: ['ai-usage-sessions.view (screen, list, show)'],
        decisions: [
            {
                ar: 'مفيش بحث ولا فلتر بالمستخدم أو الخطة أو التاريخ، فالدعم لازم يقلّب الصفحات. محتاج قرار: نضيف فلتر بالمالك؟',
                en: 'No search or filter by owner, plan or date, so support has to page through. NEEDS-DECISION: add an owner filter?',
            },
        ],
    },
};
