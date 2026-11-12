/**
 * Guide: free-trial and abuse control (admin.ai-trial-control.index).
 * Source of truth checked: views/ai-trial-control/index.vue, Modules/AI/routes/admin.php (admin/v1/ai-trial-control),
 * Http/Controllers/AiTrialControlController.php, Http/Requests/AiTrialControlUpdateRequest.php,
 * Services/AiTrialControlService.php, Repositories/AiTrialControlRepository.php,
 * Services/AiChatUsageGuard.php (evaluate, preview: trial start/end, ABUSE_BLOCKED),
 * Services/AiTaskRunner.php (ownerBlocked), Models/AiTrialControl.php.
 */
export default {
    route: 'admin.ai-trial-control.index',
    updated: '2026-11-11',

    ar: {
        summary: 'سجل لكل يوزر أو مقدم خدمة أو كابتن فتح المساعد الذكي: حالة التجربة المجانية بتاعته، وحالة الفحص (سليم / تحت المراجعة / محظور). من هنا بتحظر حد بيسيء الاستخدام، أو تديله تجربة تانية.',
        audience: 'فريق الدعم ومكافحة الاحتيال. الفتح محتاج "التحكم في التجربة المجانية: عرض"، والحفظ محتاج "تعديل".',
        purpose: [
            'تحظر حساب بيسيء استخدام المساعد (حسابات وهمية علشان ياخد تجارب، محتوى مخالف...).',
            'تعلّم حساب "تحت المراجعة" وتكتب السبب علشان الفريق يتابعه.',
            'ترجّع لعميل حقه في تجربة مجانية جديدة لو حصلت مشكلة.',
        ],
        sections: [
            {
                title: 'الجدول (الأحدث فوق)',
                items: [
                    { name: 'المستخدم', desc: 'الاسم ورقمه. الصف بيتعمل لوحده أول مرة الشخص يستخدم المساعد.' },
                    { name: 'حالة التجربة', desc: '"مؤهل" = لسه ما خدش التجربة (أول استخدام هيبدأها لو مفيش اشتراك شغال). "نشطة" = التجربة اتبدأت. "منتهية" = دقايق التجربة خلصت، واشتراك التجربة بيتقفل (منتهي) ومش بيفتح تاني بعد دقائق الانتظار - التجربة مرة واحدة بس. "نشطة" و"منتهية" الاتنين معناهم إنه مش هياخد تجربة جديدة.' },
                    { name: 'حالة الفحص', desc: '"سليم" = عادي. "تحت المراجعة" = علامة للفريق بس، ومش بتمنع أي حاجة. "محظور" = المساعد وأدوات الذكاء الاصطناعي بتتقفل عليه خالص، حتى لو عنده اشتراك مدفوع.' },
                    { name: 'سبب الحظر/المراجعة', desc: 'ملاحظة داخلية لحد 1000 حرف. العميل مش بيشوفها.' },
                    { name: 'حفظ', desc: 'التغيير مش بيتحفظ غير لما تدوس حفظ. الزرار بيفضل مقفول لحد ما تغيّر حاجة في الصف.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'حظر حساب بيسيء الاستخدام',
                steps: [
                    'دوّر على الحساب في الجدول.',
                    'غيّر "حالة الفحص" لـ "محظور" واكتب السبب.',
                    'دوس حفظ. من أول رسالة جاية المساعد هيرفض يرد عليه، والأدوات (ترجمة، تلخيص، فويس...) هتتقفل.',
                ],
            },
            {
                title: 'إدّي عميل تجربة مجانية تانية',
                steps: [
                    'اتأكد من "اشتراكات الذكاء الاصطناعي" إن مفيش اشتراك نشط أو موقوف ليه (لو فيه، التجربة مش هتبدأ).',
                    'هنا غيّر "حالة التجربة" لـ "مؤهل" ودوس حفظ.',
                    'أول ما يفتح المساعد، بياخد أول خطة تجربة مفعّلة من جديد.',
                ],
            },
            {
                title: 'رفع الحظر',
                steps: [
                    'غيّر "حالة الفحص" لـ "سليم" (وامسح السبب لو حابب) ودوس حفظ.',
                ],
            },
        ],
        affects: [
            { text: 'شات المساعد عند اليوزر ومقدم الخدمة والكابتن: "محظور" بيقفله برسالة حظر، وزراير الذكاء الاصطناعي في الشات العادي بتتقفل.' },
            { route: 'admin.ai-plans.index', text: 'خطط الذكاء الاصطناعي: "مؤهل" بيدّي أول خطة تجربة مفعّلة هناك.' },
            { route: 'admin.ai-subscriptions.index', text: 'اشتراكات الذكاء الاصطناعي: بداية التجربة بتعمل اشتراك جديد هناك.' },
        ],
        warnings: [
            { level: 'danger', text: '"محظور" بيقفل المساعد حتى على المشتركين اللي دافعين، ومش بيرجّع فلوس ولا بيوقف تجديد الاشتراك.' },
            { level: 'warning', text: 'رجوع "مؤهل" بيدّي تجربة جديدة فعلًا. استخدمه بحذر علشان ما يبقاش باب لتجارب لا نهائية.' },
            { level: 'info', text: '"تحت المراجعة" مجرد علامة، ومش بيقفل أي حاجة.' },
            { level: 'info', text: 'العميل مش بيوصله إشعار لما تغيّر حالته هنا. هيعرف لما يستخدم المساعد.' },
            { level: 'info', text: 'مفيش بحث في الشاشة دي، قلّب الصفحات.' },
            { level: 'info', text: 'لو معاك صلاحية العرض بس: القوايم والخانة بيبقوا للقراءة وزرار الحفظ مش بيظهر.' },
        ],
    },

    en: {
        summary: 'A record for every user, provider or captain who opened the AI assistant: their free-trial state and review state (clear / flagged / blocked). Block an abuser here, or give someone a fresh trial.',
        audience: 'Support and anti-fraud. Opening needs "Trial control: view"; saving needs "update".',
        purpose: [
            'Block an account abusing the assistant (fake accounts farming trials, prohibited content…).',
            'Flag an account and write why so the team follows up.',
            'Give a customer a new free trial after a problem.',
        ],
        sections: [
            {
                title: 'Table (newest first)',
                items: [
                    { name: 'User', desc: 'Name and id. The row is created automatically the first time the person uses the assistant.' },
                    { name: 'Trial status', desc: '"Eligible" = trial not taken yet (first use starts it if there is no live subscription). "Active" = trial started. "Ended" = trial minutes used; the trial subscription is closed (expired) and does not reopen after the plan\'s cooldown - the trial is one-time. Active and Ended both mean no new trial.' },
                    { name: 'Review status', desc: '"Clear" = normal. "Flagged" = a team marker only; blocks nothing. "Blocked" = the assistant and AI tools are locked for them, even with a paid subscription.' },
                    { name: 'Block/review reason', desc: 'Internal note, up to 1000 characters; never shown to the customer.' },
                    { name: 'Save', desc: 'Nothing is saved until you click it; the button stays disabled until the row changes.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Block an abusive account',
                steps: ['Find the account.', 'Set "Review status" to "Blocked" and write the reason.', 'Save. From the next message the assistant refuses them, and the AI tools (translate, summarise, voice…) are locked.'],
            },
            {
                title: 'Give a customer a second free trial',
                steps: ['Check in "AI subscriptions" that they have no active or suspended subscription (otherwise no trial starts).', 'Here set "Trial status" to "Eligible" and save.', 'Next time they open the assistant they get the first active trial plan again.'],
            },
            {
                title: 'Lift a block',
                steps: ['Set "Review status" to "Clear" (and clear the reason if you like) and save.'],
            },
        ],
        affects: [
            { text: 'Assistant chat for users, providers and captains: "Blocked" locks it with a block message, and the AI buttons in regular chat lock too.' },
            { route: 'admin.ai-plans.index', text: 'AI plans: "Eligible" hands out the first active trial plan there.' },
            { route: 'admin.ai-subscriptions.index', text: 'AI subscriptions: starting a trial creates a subscription there.' },
        ],
        warnings: [
            { level: 'danger', text: '"Blocked" locks the assistant even for paying subscribers, refunds nothing and does not stop renewals.' },
            { level: 'warning', text: 'Setting "Eligible" really grants a new trial — use it carefully so it does not become endless free trials.' },
            { level: 'info', text: '"Flagged" is only a marker; it locks nothing.' },
            { level: 'info', text: 'The customer is not notified of changes here; they find out when using the assistant.' },
            { level: 'info', text: 'No search on this screen; page through.' },
            { level: 'info', text: 'With view only: the pickers and the reason are read-only and Save is hidden.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-trial-control/index.vue',
            'Modules/AI/app/Http/Controllers/AiTrialControlController.php',
            'Modules/AI/app/Http/Requests/AiTrialControlUpdateRequest.php',
            'Modules/AI/app/Services/AiTrialControlService.php, Repositories/AiTrialControlRepository.php',
            'Modules/AI/app/Services/AiChatUsageGuard.php (evaluate, preview)',
            'Modules/AI/app/Services/AiTaskRunner.php (ownerBlocked)',
        ],
        api: [
            'GET /api/admin/v1/ai-trial-control              (page, per_page; id desc)',
            'GET /api/admin/v1/ai-trial-control/{trialControl}',
            'PUT /api/admin/v1/ai-trial-control/{trialControl} (trial_status eligible|active|ended, abuse_status clear|flagged|blocked, abuse_reason ≤1000)',
        ],
        tables: ['ai_trial_control (owner_type, owner_id, trial_status, abuse_status, abuse_reason)'],
        permissions: ['ai-trial-control.view (screen, list)', 'ai-trial-control.update (save a row)'],
        decisions: [
            {
                ar: 'التجربة مرة واحدة (زي ما دليل خطط الذكاء الاصطناعي وكود AiChatUsageGuard بيقولوا): لما دقايقها تخلص الحالة بتبقى "منتهية" واشتراك التجربة بيتقفل (expired)، وأي اشتراك تجربة قديم فضل مفتوح بعد ما خلص بيتقفل أول ما الشخص يستخدم المساعد. دقائق الانتظار في خطة التجربة مالهاش تأثير. الأدمن بس يقدر يدّي تجربة تانية بـ "مؤهل".',
                en: 'The trial is one-time (as the AI plans guide and AiChatUsageGuard say): once its minutes are used it becomes "Ended" and the trial subscription is closed (expired); an older trial subscription still left open after it ended is closed the next time the person uses the assistant. A trial plan\'s cooldown has no effect. Only an admin can grant another trial with "Eligible".',
            },
            {
                ar: '"تحت المراجعة" (flagged) مش مقروء في أي مكان في الكود، مجرد علامة.',
                en: '"Flagged" is not read anywhere in the code; it is only a marker.',
            },
        ],
    },
};
