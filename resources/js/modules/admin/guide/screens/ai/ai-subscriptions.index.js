/**
 * Guide: AI subscriptions (admin.ai-subscriptions.index).
 * Source of truth checked: views/ai-subscriptions/index.vue, Modules/AI/routes/admin.php (admin/v1/ai-subscriptions),
 * Http/Controllers/AiSubscriptionAdminController.php, AiSubscriptionController.php,
 * Services/AiSubscriptionPurchaseService.php (adminExtend, adminSuspend, adminReactivate, adminCancel),
 * Services/AiSubscriptionReportService.php (overview), Services/AiChatUsageGuard.php (what status means for the chat),
 * Http/Resources/AiSubscriptionResource.php, Http/Requests/AiSubscriptionExtendRequest.php, Services/AiSubscriptionNotifier.php.
 */
export default {
    route: 'admin.ai-subscriptions.index',
    updated: '2026-11-11',

    ar: {
        summary: 'كل اشتراكات المساعد الذكي (اليوزر ومقدم الخدمة والكابتن) مع أرقام البيزنس فوق: الإيراد الشهري، وإيراد الشهر، والمشتركين، ومعدل التسرب. ومن هنا بتمد اشتراك، أو توقفه، أو ترجّعه، أو تلغيه كقرار دعم من غير فلوس.',
        audience: 'فريق الدعم والمالية ومدير المنصة. الفتح محتاج "اشتراكات الذكاء الاصطناعي: عرض"، والتمديد والإيقاف وإعادة التفعيل والإلغاء محتاجين "تعديل".',
        purpose: [
            'تشوف صحة البيزنس بسرعة من الكروت اللي فوق.',
            'ترد على عميل: اشتراكه شغال لحد إمتى، في مهلة سماح ولا لأ، بيتجدد ولا لأ.',
            'تعوّض عميل بأيام زيادة، أو توقف اشتراك فيه مشكلة، أو تلغيه.',
        ],
        sections: [
            {
                title: 'كروت الأرقام',
                items: [
                    { name: 'إيراد شهري متكرر (MRR)', desc: 'مجموع سعر كل اشتراك نشط مدفوع بتجديد تلقائي، محسوب على 30 يوم - سطر لكل عملة (عملة آخر دفعة ناجحة للاشتراك، أو عملة الخطة لو مفيش). العملات المختلفة عمرها ما بتتجمع مع بعض.' },
                    { name: 'إيرادات هذا الشهر', desc: 'مجموع مدفوعات الاشتراكات اللي نجحت من أول الشهر - سطر لكل عملة.' },
                    { name: 'اشتراكات مدفوعة نشطة', desc: 'اشتراكات نشطة ليها تاريخ نهاية وسعرها أكبر من 0.' },
                    { name: 'معدل التسرب (30 يوم)', desc: 'نسبة تقريبية: اللي اتلغى أو انتهى في آخر 30 يوم ÷ (النشط دلوقتي + اللي مشي).' },
                    { name: 'مشتركين تجريبي', desc: 'اشتراكات نشطة على خطة تجربة.' },
                    { name: 'في مهلة السماح', desc: 'اشتراكات نشطة التجديد بتاعها فشل ولسه في مهلة الـ 3 أيام.' },
                    { name: 'موقوفة', desc: 'اشتراكات حالتها موقوف (من الإدارة أو بعد ما المهلة خلصت).' },
                    { name: 'توزيع المشتركين على الخطط', desc: 'عدد الاشتراكات النشطة في كل خطة.' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'المشترك', desc: 'الاسم، ونوعه (مستخدم / مقدم خدمة / كابتن) ورقمه.' },
                    { name: 'الخطة', desc: 'اسم الخطة بلغة اللوحة.' },
                    { name: 'بداية ونهاية الاشتراك', desc: 'النهاية "-" يعني من غير تاريخ نهاية (زي التجربة المجانية).' },
                    { name: 'التجديد التلقائي', desc: 'مفعّل أو متوقف، وشارة "في مهلة السماح" لو التجديد فشل.' },
                    { name: 'الحالة', desc: 'نشط / غير نشط / منتهي / ملغي / موقوف.' },
                    { name: '👁️ التفاصيل', desc: 'نافذة فيها كل البيانات: السعر وقت الاشتراك، والأيام المتبقية، ونهاية مهلة السماح، ونفس الأزرار. بتتفتح لوحدها لما تدوس على إشعار اشتراك.' },
                    { name: 'عدد الأيام + تمديد', desc: 'من 1 لـ 3650 يوم. لو الاشتراك لسه شغال الأيام بتتزود على نهايته، ولو خلص بتتحسب من النهارده. مجاني، من غير محفظة.' },
                    { name: 'إيقاف', desc: 'للاشتراك النشط بس، بعد تأكيد. المساعد بيتقفل على العميل برسالة إن اشتراكه موقوف.' },
                    { name: 'إعادة تفعيل', desc: 'للموقوف بس. بيرجع نشط وبيمسح مهلة السماح.' },
                    { name: 'إلغاء', desc: 'للنشط أو الموقوف. الحالة بتبقى ملغي والتجديد التلقائي بيتقفل، والعميل بيفقد الوصول فورًا.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'تعويض عميل بأسبوع زيادة',
                steps: [
                    'دوّر على الاشتراك ودوس 👁️ للتأكد من التواريخ.',
                    'اكتب 7 في "عدد الأيام" ودوس "تمديد".',
                    'العميل بيوصله إشعار جوه التطبيق بالتعديل.',
                ],
            },
            {
                title: 'عميل بيقول المساعد مقفول عليه',
                steps: [
                    'دوّر على اشتراكه: لو "موقوف" شوف السبب، ولو مناسب دوس "إعادة تفعيل".',
                    'لو "في مهلة السماح" يبقى التجديد فشل (غالبًا رصيد المحفظة). اطلب منه يشحن.',
                    'لو مفيش اشتراك نشط وخلّص التجربة، راجع "التحكم في التجربة المجانية".',
                ],
            },
        ],
        affects: [
            { text: 'شات المساعد والأدوات عند اليوزر ومقدم الخدمة والكابتن: الإيقاف والإلغاء بيقفلوا الاستخدام، والتمديد بيطوّل المدة.' },
            { text: 'إشعارات العميل: كل تعديل من الإدارة بيوصل له جوه التطبيق (من غير push).' },
            { route: 'admin.ai-plans.index', text: 'خطط الذكاء الاصطناعي: كل اشتراك مربوط بخطة، والحدود بتتقري من الخطة وقت الاستخدام.' },
            { route: 'admin.ai-usage-sessions.index', text: 'جلسات الاستخدام: كل جلسة مربوطة باشتراك.' },
            { route: 'admin.ai-trial-control.index', text: 'التحكم في التجربة: التجربة المجانية بتعمل اشتراك هنا.' },
        ],
        warnings: [
            { level: 'warning', text: 'تعديلات الإدارة مجانية: الإلغاء مش بيرجّع فلوس، والتمديد وإعادة التفعيل مش بيخصموا حاجة.' },
            { level: 'warning', text: 'الإلغاء نهائي من الشاشة: مفيش زرار يرجّع الاشتراك الملغي، والعميل لازم يشترك تاني.' },
            { level: 'warning', text: 'التمديد على اشتراك من غير تاريخ نهاية (زي التجربة) بيدّيله تاريخ نهاية جديد بعد الأيام دي، يعني بيحوّله لاشتراك محدود.' },
            { level: 'info', text: 'التمديد مش بيغيّر الحالة: اشتراك ملغي أو منتهي بيفضل زي ما هو حتى لو مدّيته.' },
            { level: 'info', text: 'لو معاك صلاحية العرض بس: هتشوف الأرقام والجدول والتفاصيل من غير أزرار التعديل.' },
        ],
    },

    en: {
        summary: 'Every AI assistant subscription (users, providers, captains) with business numbers on top: MRR, this month’s revenue, subscribers, churn. Extend, suspend, reactivate or cancel a subscription as a free support decision.',
        audience: 'Support, finance and the platform owner. Opening needs "AI subscriptions: view"; extend, suspend, reactivate and cancel need "update".',
        purpose: [
            'See business health at a glance from the cards.',
            'Answer a customer: until when it runs, whether it is in grace, whether it renews.',
            'Compensate with extra days, suspend a problem subscription, or cancel it.',
        ],
        sections: [
            {
                title: 'Number cards',
                items: [
                    { name: 'Monthly recurring revenue (MRR)', desc: 'Sum of every active paid auto-renew subscription price, scaled to 30 days - one line per currency (the currency of the subscription\'s last successful payment, or the plan\'s when it has none). Different currencies are never added together.' },
                    { name: 'Revenue this month', desc: 'Successful subscription payments since the 1st - one line per currency.' },
                    { name: 'Active paid subscriptions', desc: 'Active, with an end date and a price above 0.' },
                    { name: 'Churn rate (30 days)', desc: 'Approximate: cancelled or expired in the last 30 days ÷ (active now + those that left).' },
                    { name: 'Trial subscribers', desc: 'Active subscriptions on a trial plan.' },
                    { name: 'In grace period', desc: 'Active subscriptions whose renewal failed and are inside the 3-day grace.' },
                    { name: 'Suspended', desc: 'Status suspended (by an admin or after grace ran out).' },
                    { name: 'Subscribers by plan', desc: 'Active subscriptions per plan.' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Subscriber', desc: 'Name, type (user / provider / captain) and id.' },
                    { name: 'Plan', desc: 'Plan name in the panel language.' },
                    { name: 'Starts at / Ends at', desc: 'End "-" = no end date (e.g. the free trial).' },
                    { name: 'Auto-renew', desc: 'On or off, plus "In grace period" when a renewal failed.' },
                    { name: 'Status', desc: 'Active / inactive / expired / cancelled / suspended.' },
                    { name: '👁️ Details', desc: 'Everything: price at subscription, days left, grace end, and the same actions. Opens by itself from a subscription notification.' },
                    { name: 'Days + Extend', desc: '1–3650 days. Added to the end date if still running, otherwise counted from today. Free, no wallet.' },
                    { name: 'Suspend', desc: 'Active only, after confirmation. The assistant locks with a "subscription suspended" message.' },
                    { name: 'Reactivate', desc: 'Suspended only. Back to active; clears the grace period.' },
                    { name: 'Cancel', desc: 'Active or suspended. Status cancelled, auto-renew off; access ends at once.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Compensate a customer with an extra week',
                steps: ['Find the subscription and click 👁️ to check the dates.', 'Type 7 in "Days" and click "Extend".', 'The customer gets an in-app notification.'],
            },
            {
                title: 'Customer says the assistant is locked',
                steps: ['Find their subscription: if "Suspended", check why and click "Reactivate" if appropriate.', 'If "In grace period" the renewal failed (usually wallet balance) — ask them to top up.', 'If there is no active subscription and the trial is used, check "Trial control".'],
            },
        ],
        affects: [
            { text: 'Assistant chat and tools for users, providers and captains: suspend/cancel lock usage; extend lengthens it.' },
            { text: 'Customer notifications: every admin adjustment is sent in-app (no push).' },
            { route: 'admin.ai-plans.index', text: 'AI plans: each subscription points at a plan; limits are read from the plan at use time.' },
            { route: 'admin.ai-usage-sessions.index', text: 'Usage sessions: each session belongs to a subscription.' },
            { route: 'admin.ai-trial-control.index', text: 'Trial control: the free trial creates a subscription here.' },
        ],
        warnings: [
            { level: 'warning', text: 'Admin adjustments are free: cancel refunds nothing; extend and reactivate charge nothing.' },
            { level: 'warning', text: 'Cancel is final from this screen: there is no button to restore a cancelled subscription; the customer must subscribe again.' },
            { level: 'warning', text: 'Extending a subscription with no end date (e.g. the trial) gives it an end date that many days from now — it becomes time-limited.' },
            { level: 'info', text: 'Extend does not change the status: a cancelled or expired subscription stays so even when extended.' },
            { level: 'info', text: 'With view only: numbers, table and details are shown without the action buttons.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-subscriptions/index.vue',
            'Modules/AI/app/Http/Controllers/AiSubscriptionAdminController.php, AiSubscriptionController.php',
            'Modules/AI/app/Services/AiSubscriptionPurchaseService.php (admin* methods)',
            'Modules/AI/app/Services/AiSubscriptionReportService.php (overview)',
            'Modules/AI/app/Services/AiChatUsageGuard.php (suspended → REASON_SUBSCRIPTION_SUSPENDED)',
            'Modules/AI/app/Http/Resources/AiSubscriptionResource.php',
        ],
        api: [
            'GET  /api/admin/v1/ai-subscriptions/overview',
            'GET  /api/admin/v1/ai-subscriptions            (page, per_page)',
            'GET  /api/admin/v1/ai-subscriptions/{subscription}',
            'POST /api/admin/v1/ai-subscriptions/{subscription}/extend (days 1-3650) · /suspend · /reactivate · /cancel',
            'POST|PUT|DELETE /api/admin/v1/ai-subscriptions[/{subscription}] — generic CRUD, not used by this screen',
        ],
        tables: ['ai_subscriptions (status, auto_renew, ends_at, grace_ends_at, current_plan_price, current_plan_duration_days)', 'ai_subscription_payments'],
        permissions: [
            'ai-subscriptions.view (screen, overview, list, details)',
            'ai-subscriptions.update (extend, suspend, reactivate, cancel)',
            'ai-subscriptions.create / ai-subscriptions.delete (generic store / destroy; no button on the screen)',
        ],
        decisions: [
            {
                ar: 'الـ MRR وإيرادات الشهر بيتحسبوا لكل عملة لوحدها (mrr_by_currency وrevenue_this_month_by_currency: [{currency, amount}]). mrr وrevenue_this_month لسه موجودين للي بيستخدمهم بس بيرجعوا null لو فيه أكتر من عملة. مفيش تحويل عملات.',
                en: 'MRR and revenue this month are computed per currency (mrr_by_currency and revenue_this_month_by_currency: [{currency, amount}]). mrr and revenue_this_month stay for old readers but are null when there is more than one currency. No currency conversion.',
            },
            {
                ar: 'بعد أي إجراء، الصف بيتبدّل بالرد اللي مفيهوش بيانات المشترك (owner مش متحمّل)، فاسم المشترك بيختفي لحد ما الصفحة تتحمّل تاني.',
                en: 'After any action the row is replaced with a response that has no owner loaded, so the subscriber name disappears until the list reloads.',
            },
            {
                ar: 'نوع المشترك بيتعرض بـ t("ai_subscriptions.<type>")، ومفيش مفتاح ai_subscriptions.driver، فالكابتن بيظهر كمفتاح خام.',
                en: 'The subscriber type uses t("ai_subscriptions.<type>") and there is no ai_subscriptions.driver key, so captains show a raw key.',
            },
            {
                ar: 'التمديد بيحوّل الاشتراك اللي من غير نهاية (ends_at فاضي) لاشتراك بينتهي بعد الأيام دي. مقصود؟',
                en: 'Extending a subscription with no end (ends_at null) turns it into one that ends after those days. Intended?',
            },
        ],
    },
};
