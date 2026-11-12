/**
 * Guide: the freelance quality desk (admin.freelance.quality, FS-605).
 * Source of truth checked: views/freelance/quality/index.vue, Modules/Freelance/routes/admin.php (freelance-quality/*),
 * Modules/Freelance/app/Http/Controllers/Admin/FreelanceQualityController.php, Services/Account/FreelancerPortfolio.php (present, unverify,
 * reverify), Services/Skills/FreelanceSkillChallenges.php (queue, counts, decide, present), Services/Reputation/FreelancerReputation.php
 * (queue, decide, excluded), database/seeders/Admin/AdminPermissionSeeder.php (freelance-quality), locales freelance.quality.
 */
export default {
    route: 'admin.freelance.quality',
    updated: '2026-11-12',

    ar: {
        summary: 'مكتب الجودة في الشغل الحر: الأعمال اللي عليها «موثّق من دُرّ» وسحب الشارة أو رجوعها، التحديات العملية اللي بترفع شارة المهارة لمتقدم أو خبير، وطلبات تصحيح بيانات السمعة (التسليم في الميعاد ونسبة الإكمال).',
        audience: 'فريق جودة الشغل الحر. صلاحية "الجودة والثقة: عرض" لفتح الشاشة وقراءة القوائم، و"تعديل" لسحب/رجوع الشارة والقرار في التحديات والتصحيحات.',
        purpose: [
            'تراجع الأعمال الموثّقة (عمل في معرض المحترف مربوط بمشروع خلص على دُرّ والعميل سمح بعرضه) وتسحب الشارة من عمل مينفعش يفضل عليه التوثيق.',
            'تقرّر في التحديات العملية: المحترف اللي عنده شارة سارية في مهارة بيبعت رابط للي بناه من "المطلوب"، وانت تقبل (وتمنح متقدم أو خبير) أو ترفض بسبب.',
            'تقرّر في طلبات تصحيح السمعة: لو قبلت، المشروع بيتشال من المؤشر اللي المحترف اشتكى منه.',
        ],
        sections: [
            {
                title: 'الملخص (فوق)',
                items: [
                    { name: 'أعمال موثّقة', desc: 'عدد الأعمال اللي عليها الشارة دلوقتي (عمل مربوط بمشروع مكتمل، العميل سامح بعرضه، والشارة ما اتسحبتش).' },
                    { name: 'تحديات مستنية', desc: 'التحديات اللي حالتها "مستني" (لسه محدش قرّر فيها).' },
                    { name: 'تصحيحات مستنية', desc: 'طلبات تصحيح السمعة المفتوحة.' },
                    { name: 'شارات مهارة سارية', desc: 'كل شارات المهارات اللي ما اتلغتش وما انتهتش، ومقسومة تحت حسب المستوى: مبتدئ · متوسط · متقدم · خبير.' },
                    { name: 'تحديث', desc: 'بيعيد قراءة الملخص والقايمة المفتوحة.' },
                ],
            },
            {
                title: 'تبويب الأعمال الموثّقة',
                items: [
                    { name: 'الفلتر', desc: 'موثّق / الشارة اتسحبت، وجنب كل واحد العدد.' },
                    { name: 'البحث', desc: 'بعنوان العمل أو اسم المحترف.' },
                    { name: 'الأعمدة', desc: 'العمل (أول صورة، التخصص، تاريخ الإنجاز)، المحترف ورقمه، مشروع دُرّ، الشارة (وشهر خلوص المشروع، أو سبب السحب) و"مخفي" لو العمل مخفي من المعرض.' },
                    { name: 'عرض', desc: 'بيفتح العمل كامل: الوصف، دراسة الحالة (المشكلة، الحل، دوره، الأدوات، النتايج — اللي مكتوب بس)، المهارات، الصور، الرابط الخارجي، الفيديو، ومين يقدر يشوفه (للكل / للعملاء بس / خاص).' },
                    { name: 'سحب الشارة', desc: 'سبب إجباري من 3 لـ 255 حرف، وبعدها تأكيد.' },
                    { name: 'رجّع الشارة', desc: 'للعمل اللي شارته اتسحبت، بعد تأكيد.' },
                ],
            },
            {
                title: 'تبويب التحديات العملية',
                items: [
                    { name: 'الفلتر', desc: 'مستني / مقبول / مرفوض / اتسحب (المحترف سحبه بنفسه) / الكل، بالعدد.' },
                    { name: 'البحث', desc: 'باسم المحترف أو رقم تليفونه.' },
                    { name: 'الترتيب', desc: 'المستني الأول، والأقدم فيهم فوق؛ بعدهم الأحدث قرارًا.' },
                    { name: 'الأعمدة', desc: 'المحترف، المهارة (ومستوى شارته دلوقتي)، المستوى المطلوب (متقدم أو خبير)، اتبعت إمتى، الحالة (والمستوى اللي اتمنح لو اتقبل).' },
                    { name: 'نافذة التحدي', desc: 'الشارة اللي بيبني عليها (المستوى، درجة الاختبار، بتنتهي إمتى، سارية ولا لأ)، "المطلوب" من إعدادات اختبار المهارة، الرابط اللي بعته وملاحظته، والقرار لو لسه مستني.' },
                    { name: 'القرار', desc: 'قبول أو رفض. في القبول بتختار المستوى: متقدم، أو متقدم/خبير لو المطلوب خبير (مش أعلى من المطلوب؛ الافتراضي = المطلوب). الملاحظة اختيارية في القبول وإجبارية في الرفض (لحد 1000 حرف).' },
                ],
            },
            {
                title: 'تبويب تصحيح بيانات السمعة',
                items: [
                    { name: 'الفلتر', desc: 'مستني / مقبول / مرفوض / الكل، بالعدد.' },
                    { name: 'البحث', desc: 'باسم المحترف أو رقم تليفونه.' },
                    { name: 'الأعمدة', desc: 'المحترف، المشروع، المؤشر (التسليم في الميعاد / نسبة الإكمال)، السبب (العميل اتأخر، العميل أنهى المشروع، نطاق الشغل اتغير، بيانات غلط، سبب تاني)، اتبعت إمتى، الحالة.' },
                    { name: 'القرار', desc: 'قبول أو رفض، والملاحظة إجبارية في الرفض (لحد 1000 حرف). الشاشة بتكتبلك قبل التأكيد إن القبول هيشيل المشروع من المؤشر.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'سحب شارة «موثّق من دُرّ» من عمل',
                steps: [
                    'تبويب الأعمال الموثّقة ← فلتر "موثّق"، وابحث بعنوان العمل أو اسم المحترف.',
                    'دوس "عرض" وراجع الصور والوصف ودراسة الحالة.',
                    'اكتب السبب (3 حروف على الأقل) ودوس "سحب الشارة" وأكّد.',
                ],
            },
            {
                title: 'رجوع شارة اتسحبت بالغلط',
                steps: [
                    'تبويب الأعمال الموثّقة ← فلتر "الشارة اتسحبت".',
                    'دوس "عرض" ← "رجّع الشارة" وأكّد.',
                ],
            },
            {
                title: 'القرار في تحدي عملي',
                steps: [
                    'تبويب التحديات العملية ← "مستني".',
                    'دوس "قرّر"، اقرا المطلوب وافتح الرابط وراجع اللي اتبنى.',
                    'قبول: اختار المستوى (متقدم أو خبير) واكتب ملاحظة لو حابب. رفض: اكتب السبب.',
                    'دوس "تأكيد القرار" وأكّد.',
                ],
            },
            {
                title: 'القرار في طلب تصحيح سمعة',
                steps: [
                    'تبويب تصحيح بيانات السمعة ← "مستني".',
                    'دوس "قرّر"، راجع المشروع والمؤشر والسبب وملاحظة المحترف.',
                    'قبول (والمشروع يتشال من المؤشر) أو رفض بسبب، وبعدين "تأكيد القرار".',
                ],
            },
        ],
        affects: [
            { text: 'سحب الشارة: العمل بيفضل في معرض المحترف من غير «موثّق من دُرّ»، والمحترف بيوصله إشعار فيه عنوان العمل والسبب (وبيقرا السبب في التطبيق). رجوع الشارة بيمسح السبب ومن غير إشعار.' },
            { text: 'عدد الأعمال الموثّقة بيدخل كمصدر في مؤشر "technical" في سمعة المحترف.' },
            { text: 'قبول تحدي: شارة المهارة بتعلى للمستوى الممنوح وصلاحيتها بتبدأ من جديد (لو المستوى أعلى من اللي عندها). المحترف بيوصله إشعار بالقبول أو الرفض.' },
            { text: 'رفض تحدي: المحترف ميقدرش يبعت تحدي جديد لنفس المهارة غير بعد أيام الانتظار (الافتراضي 14 يوم).' },
            { text: 'قبول تصحيح: المشروع بيتشال من مؤشر "التسليم في الميعاد" أو "نسبة الإكمال" في سمعة المحترف اللي العملاء بيشوفوها. المحترف بيوصله إشعار في الحالتين.' },
            { route: 'admin.freelance.skillTests', text: 'المطلوب في التحدي، وتشغيله، ودرجة المتوسط بيتظبطوا في إعدادات اختبار المهارة.' },
            { route: 'admin.freelance.audit', text: 'كل قرار هنا بيتسجّل في سجل الشغل الحر باسم الموظف.' },
        ],
        warnings: [
            { level: 'danger', text: 'سبب سحب الشارة بيوصل للمحترف زي ما هو — اكتبه بأسلوب محترم وواضح.' },
            { level: 'warning', text: 'القرار في التحدي أو التصحيح نهائي من الشاشة: مفيش تراجع، والقرار التاني على نفس الطلب بيرجع "اتراجع خلاص" (409) لو حد سبقك.' },
            { level: 'warning', text: 'لو شارة المحترف في المهارة انتهت أو اتلغت بعد ما بعت التحدي، القبول بيفشل (مفيش شارة تتعلى) — ارفض واطلب منه يعيد الاختبار.' },
            { level: 'info', text: 'الروابط والصور جاية من المحترف؛ الشاشة بتفتح روابط http/https بس وفي تبويب جديد. روابط الصور مؤقتة.' },
            { level: 'info', text: 'قبول تحدي بمستوى أقل من أو زي مستوى الشارة الحالي بيسجّل القبول بس من غير ما يغيّر الشارة.' },
        ],
    },

    en: {
        summary: 'The freelance quality desk: works marked «Verified by Dorr» and taking that badge back (or giving it back), practical challenges that raise a skill badge to advanced or expert, and requests to correct reputation figures (on-time delivery and completion rate).',
        audience: 'Freelance quality team. "Quality & trust: view" opens the screen and reads the lists; "update" takes back / gives back the badge and decides challenges and corrections.',
        purpose: [
            'Review verified works (a portfolio item tied to a project finished on Dorr whose client allowed it to be shown) and take the badge off a work that should not carry it.',
            'Decide practical challenges: a freelancer with a valid badge in a skill sends a link to what they built from the brief; you accept (granting advanced or expert) or reject with a reason.',
            'Decide reputation corrections: accepting takes the project out of the figure the freelancer complained about.',
        ],
        sections: [
            {
                title: 'Summary (top)',
                items: [
                    { name: 'Verified works', desc: 'Works carrying the badge now (tied to a completed project, the client allows showing it, the badge not taken back).' },
                    { name: 'Challenges waiting', desc: 'Challenges still "waiting" (nobody decided yet).' },
                    { name: 'Corrections waiting', desc: 'Open reputation correction requests.' },
                    { name: 'Valid skill badges', desc: 'All skill badges neither revoked nor expired, split below by level: beginner · intermediate · advanced · expert.' },
                    { name: 'Refresh', desc: 'Reloads the summary and the open list.' },
                ],
            },
            {
                title: 'Verified works tab',
                items: [
                    { name: 'Filter', desc: 'Verified / badge taken back, each with its count.' },
                    { name: 'Search', desc: 'By work title or freelancer name.' },
                    { name: 'Columns', desc: 'Work (first image, specialty, completed on), freelancer and phone, Dorr project, badge (month the project finished, or the reason it was taken back) and "Hidden" when the work is hidden from the portfolio.' },
                    { name: 'View', desc: 'Opens the whole work: description, case study (problem, solution, role, tools, results — only the parts written), skills, images, external link, video, and who can see it (public / clients only / private).' },
                    { name: 'Take the badge back', desc: 'A required reason, 3–255 characters, then a confirmation.' },
                    { name: 'Give the badge back', desc: 'For a work whose badge was taken back, after a confirmation.' },
                ],
            },
            {
                title: 'Practical challenges tab',
                items: [
                    { name: 'Filter', desc: 'Waiting / accepted / rejected / withdrawn (the freelancer pulled it) / all, with counts.' },
                    { name: 'Search', desc: 'By freelancer name or phone.' },
                    { name: 'Order', desc: 'Waiting first, the oldest on top; then the most recently decided.' },
                    { name: 'Columns', desc: 'Freelancer, skill (and their badge level now), asked level (advanced or expert), sent, status (and the granted level when accepted).' },
                    { name: 'Challenge window', desc: 'The badge it builds on (level, test score, end date, valid or not), the brief from the skill test settings, the link they sent and their note, and the decision while it waits.' },
                    { name: 'Decision', desc: 'Accept or reject. Accepting picks the level: advanced, or advanced/expert when expert was asked (never above the asked level; defaults to it). The note is optional to accept and required to reject (up to 1000 characters).' },
                ],
            },
            {
                title: 'Reputation corrections tab',
                items: [
                    { name: 'Filter', desc: 'Waiting / accepted / rejected / all, with counts.' },
                    { name: 'Search', desc: 'By freelancer name or phone.' },
                    { name: 'Columns', desc: 'Freelancer, project, figure (on-time delivery / completion rate), reason (client delayed, client ended the project, scope changed, wrong data, other), sent, status.' },
                    { name: 'Decision', desc: 'Accept or reject; the note is required to reject (up to 1000 characters). Before confirming, the screen states that accepting takes the project out of the figure.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Take the «Verified by Dorr» badge off a work',
                steps: [
                    'Verified works tab → "Verified", search by title or freelancer.',
                    'Click "View" and check the images, description and case study.',
                    'Write the reason (at least 3 characters), click "Take the badge back" and confirm.',
                ],
            },
            {
                title: 'Give back a badge taken by mistake',
                steps: [
                    'Verified works tab → "Badge taken back".',
                    'Click "View" → "Give the badge back" and confirm.',
                ],
            },
            {
                title: 'Decide a practical challenge',
                steps: [
                    'Practical challenges tab → "Waiting".',
                    'Click "Decide", read the brief, open the link and check what was built.',
                    'Accept: pick the level (advanced or expert), optionally add a note. Reject: write the reason.',
                    'Click "Confirm the decision" and confirm.',
                ],
            },
            {
                title: 'Decide a reputation correction',
                steps: [
                    'Reputation corrections tab → "Waiting".',
                    'Click "Decide", check the project, figure, reason and the freelancer\'s note.',
                    'Accept (the project leaves the figure) or reject with a reason, then "Confirm the decision".',
                ],
            },
        ],
        affects: [
            { text: 'Taking the badge back: the work stays in the freelancer\'s portfolio without «Verified by Dorr»; the freelancer is notified with the work title and the reason (and reads the reason in the app). Giving it back clears the reason, with no notification.' },
            { text: 'The number of verified works is a source of the "technical" figure in the freelancer\'s reputation.' },
            { text: 'Accepting a challenge: the skill badge goes up to the granted level and its validity starts again (when the level is higher than the one it has). The freelancer is notified of an accept or a reject.' },
            { text: 'Rejecting a challenge: the freelancer cannot send a new one for the same skill until the wait is over (14 days by default).' },
            { text: 'Accepting a correction: the project leaves the "on-time delivery" or "completion rate" figure of the freelancer\'s reputation that clients see. The freelancer is notified either way.' },
            { route: 'admin.freelance.skillTests', text: 'The challenge brief, switching the challenge on, and the intermediate score are set in the skill test settings.' },
            { route: 'admin.freelance.audit', text: 'Every decision here is written to the freelance audit log under the staff member\'s name.' },
        ],
        warnings: [
            { level: 'danger', text: 'The reason for taking a badge back reaches the freelancer as written — keep it clear and respectful.' },
            { level: 'warning', text: 'A challenge or correction decision is final from this screen: there is no undo, and deciding one somebody already decided returns "already decided" (409).' },
            { level: 'warning', text: 'If the freelancer\'s badge in the skill expired or was revoked after they sent the challenge, accepting fails (no badge to raise) — reject and ask them to retake the test.' },
            { level: 'info', text: 'Links and images come from the freelancer; the screen only opens http/https links, in a new tab. Image links are temporary.' },
            { level: 'info', text: 'Accepting a challenge at a level not above the current badge records the acceptance without changing the badge.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/freelance/quality/index.vue',
            'Modules/Freelance/app/Http/Controllers/Admin/FreelanceQualityController.php',
            'Modules/Freelance/app/Services/Account/FreelancerPortfolio.php',
            'Modules/Freelance/app/Services/Skills/FreelanceSkillChallenges.php',
            'Modules/Freelance/app/Services/Reputation/FreelancerReputation.php',
        ],
        api: [
            'GET  /api/admin/v1/freelance-quality/summary',
            'GET  /api/admin/v1/freelance-quality/verified-works (?status=verified|revoked, search, per_page) · POST …/{id}/unverify {reason 3..255} · POST …/{id}/reverify',
            'GET  /api/admin/v1/freelance-quality/challenges (?status=submitted|accepted|rejected|withdrawn, search, per_page) · POST …/{uuid}/decide {decision, level?, note?}',
            'GET  /api/admin/v1/freelance-quality/corrections (?status=open|accepted|rejected, search, per_page) · POST …/{uuid}/decide {decision, note?}',
        ],
        tables: ['freelancer_portfolio_items (verified_revoked_*, case_*)', 'freelance_skill_challenges', 'freelancer_skill_badges (level)', 'freelance_reputation_corrections', 'freelance_skill_tests (challenge_brief)'],
        permissions: ['freelance-quality.view (summary, works, challenges, corrections)', 'freelance-quality.update (unverify, reverify, decideChallenge, decideCorrection)'],
        decisions: [
            {
                ar: 'قوالب الـ Checklist والبلاغات على الرسائل فضلوا في شاشاتهم (مش جوه مكتب الجودة).',
                en: 'Checklist templates and reported messages keep their own screens (not inside the quality desk).',
            },
        ],
        noTranslatableText: 'A review desk, not a CRUD: it saves decisions on records the freelancers wrote (titles, notes, links) — staff write only notes and reasons that go to one freelancer.',
    },
};
