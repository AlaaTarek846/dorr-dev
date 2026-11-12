/**
 * Guide: freelance plans (admin.freelance.plans).
 * Source of truth checked: views/freelance/plans/index.vue, Modules/Freelance/app/Http/Controllers/Admin/FreelancePlanController.php and
 * FreelanceOfferPackController.php (the plans permission), Models/FreelancePlan.php, Services/Plans/FreelancePerks.php (what a live
 * subscription opens), Services/Plans/FreelancePlanDefaults.php + config('freelance.plans', 'freelance.crm'), docs/modules/freelance/API.md
 * (Phase 7, FS-703 the freelancer's CRM), locales freelance.plans.
 */
export default {
    route: 'admin.freelance.plans',
    updated: '2026-11-12',

    ar: {
        summary: 'مكتب الباقات: إيه اللي بتدّيه كل باقة للمحترف (أو للفريق)، سعرها الشهري والسنوي، مين مشترك فيها، وباقات العروض الإضافية اللي المحترف بيشتريها لوحده.',
        audience: 'مسؤول التسعير والاشتراكات في الشغل الحر. محتاج صلاحية "باقات الفريلانس": عرض للقراءة، وتعديل عشان يغيّر باقة أو سعر.',
        purpose: [
            'تحدد مزايا كل باقة: العروض في الشهر، العمولة، الفترة التجريبية، الشارة، أولوية الظهور، إحصائيات الأداء، إدارة العملاء (CRM)، وعدد الخدمات الجاهزة.',
            'تضبط سعر كل باقة (شهري وسنوي) والتوفير في السنوي.',
            'تتابع المشتركين وحالات الدفع، وإيراد آخر 30 يوم.',
            'تظبط باقات العروض الإضافية وأسعارها، وتشوف مين اشترى.',
        ],
        sections: [
            {
                title: 'فوق الشاشة',
                items: [
                    { name: 'محترفين / فرق', desc: 'بيبدّل الشاشة كلها بين باقات المحترفين وباقات الفرق (اللي بيدفعها مالك الفريق من محفظته). تبويبي العروض الإضافية والمشتريات بيظهروا للمحترفين بس.' },
                    { name: 'كروت الأرقام', desc: 'المشتركين دلوقتي (اشتراك شغّال)، اللي في التجربة، اللي فشل دفعهم، وإيراد آخر 30 يوم لكل عملة.' },
                    { name: 'التبويبات', desc: 'الباقات، الأسعار، المشتركين، العروض الإضافية، المشتريات.' },
                ],
            },
            {
                title: 'تبويب الباقات',
                items: [
                    { name: 'كارت الباقة', desc: 'الاسم والكود والوصف، شارة "مجانية" أو "معروضة/غير معروضة"، وسطر لكل ميزة: العروض، العمولة، التجربة، المزايا (شارة، أولوية، إحصائيات، دفتر العملاء، خدمات جاهزة)، وعدد المشتركين.' },
                    { name: 'تعديل', desc: 'بيفتح نافذة تعديل الباقة. الباقة المجانية مفيهاش تعديل: أرقامها دايمًا من سياسة الدولة.' },
                ],
            },
            {
                title: 'نافذة تعديل الباقة',
                items: [
                    { name: 'الاسم والوصف (عربي / إنجليزي)', desc: 'الاسمين إجباريين (لحد 60 حرف). الوصف اختياري (لحد 300 حرف). بيظهروا للمحترف في التطبيق بلغته.' },
                    { name: 'الأشخاص في الفريق / المشاريع مع بعض', desc: 'باقة الفريق بس. الفاضي = من غير حد، والأكبر بين الرقم ده ورقم السياسة هو اللي بيتطبق.' },
                    { name: 'عروض بلا حد / العروض المجانية في الشهر', desc: 'لو "بلا حد" اتفعّل الرقم بيتبعت فاضي وبيتقفل. غير كده الرقم من 0 لـ 100000. لو رقم سياسة الدولة أعلى بيتاخد هو.' },
                    { name: 'العمولة %', desc: 'باقة المحترف بس، من 0 لـ 100. الفاضي = عمولة الدولة. العمولة بتقلّل بس ومش بتزوّد.' },
                    { name: 'التجربة المجانية (أيام)', desc: 'من 0 لـ 90 يوم قبل أول دفع.' },
                    { name: 'شارة الباقة', desc: 'باقة المحترف بس. بتظهر جنب اسم المحترف عند العملاء طول ما اشتراكه ساري.' },
                    { name: 'أولوية الظهور', desc: 'باقة المحترف بس، من 0 لـ 3. وزن في ترتيب "الموصى بهم" بس؛ ترتيبات الأرخص والأسرع مش بتتأثر. أي قيد على الشغل أو إيقاف بيشيل الأولوية.' },
                    {
                        name: 'إحصائيات الأداء',
                        desc: 'باقة المحترف بس. مقفولة (0) / أساسية (1: آخر 30 يوم) / كاملة (2: 30 و90 و365 يوم مع تفصيل الدخل حسب التخصص وأهم العملاء وتحليل المعرض). بتفتح شاشة "إحصائيات أدائي" في تطبيق المحترف.',
                    },
                    {
                        name: 'إدارة العملاء (CRM)',
                        desc: 'باقة المحترف بس (مش بتتطبق على باقة الفريق ولا على المجانية). دفتر عملاء خاص بالمحترف في التطبيق، والعميل مبيشوفش منه حاجة. '
                            + 'مقفولة (0): الشاشة بتظهر مقفولة وبتعرض أول باقة بتفتحها. '
                            + 'عملاء وملاحظات (1): قايمة العملاء (اللي اشتغل معاهم أو بيشتغل معاهم، والعملاء المحتملين اللي عرضه مستني أو دعوة لسه متردش عليها)، المشاريع السابقة مع كل عميل، ملاحظات (لحد 50 لكل عميل)، ونجمة. '
                            + 'عملاء وملاحظات وتذكيرات (2): كل اللي فوق + تصنيف العميل (VIP / عادي / محتمل / متوقف) وتذكيرات متابعة (لحد 30 مستنيين، لحد سنة قدام) والمحترف بيوصله إشعار مرة واحدة وقت التذكير. '
                            + 'الافتراضي: بلس = 1، برو = 2.',
                    },
                    { name: 'أقصى عدد خدمات جاهزة', desc: 'باقة المحترف بس، من 1 لـ 1000. الفاضي = حسب سياسة الدولة، ولو رقم الدولة أعلى بيتاخد هو (الباقة عمرها ما بتقلّل الرقم).' },
                    { name: 'معروضة للفريلانسرز', desc: 'لو اتقفلت الباقة بتختفي عن المشتركين الجداد، واللي مشترك فيها بيكمّل لحد ما تخلص فترته.' },
                ],
            },
            {
                title: 'تبويب الأسعار',
                items: [
                    { name: 'اختيار الباقة', desc: 'الباقات المدفوعة بس (المجانية ملهاش سعر).' },
                    { name: 'سطر الدولة', desc: 'الشهري والسنوي بعملة الدولة، وعمود التوفير السنوي بالنسبة. المنصة حاليًا بسعر واحد للعالم كله، فبيظهر سطر واحد (الدولة الدولية).' },
                    { name: 'حفظ', desc: 'السعر لازم يكون أكبر من صفر، وسعر السنة لازم يكون أقل من اثني عشر شهر. الأسعار بتتخزن بأصغر وحدة للعملة والشاشة بتقسمها على كسور العملة.' },
                    { name: 'ملء الأسعار الناقصة', desc: 'بيضيف الباقات والأسعار الناقصة (باقات العروض الإضافية كمان) من الأرقام الافتراضية. أي سعر اتظبط قبل كده مبيتلمسش.' },
                ],
            },
            {
                title: 'تبويب المشتركين',
                items: [
                    { name: 'الفلاتر', desc: 'الحالة (في التجربة / نشطة / فشل الدفع / انتهت) مع عدد كل حالة، الباقة، والبحث باسم المحترف أو موبايله (للفرق: اسم الفريق أو اللي بيدفع).' },
                    { name: 'الجدول', desc: 'المحترف (أو الفريق واللي بيدفع)، الباقة والفترة، الحالة (مع "من غير تجديد" لو اتلغى التجديد)، السعر، وتاريخ الانتهاء أو التجديد. للقراءة بس.' },
                ],
            },
            {
                title: 'تبويبا العروض الإضافية والمشتريات (محترفين بس)',
                items: [
                    { name: 'كروت الباقات', desc: 'كل باقة: الاسم، عدد العروض اللي بتضيفها، عدد اللي اتباع في آخر 30 يوم، وزرار تعديل (الاسم، عدد العروض، معروضة). تعديل الباقة مبيغيّرش مشتريات اتعملت قبل كده.' },
                    { name: 'سعر كل دولة', desc: 'زي الباقات: سعر واحد لكل باقة عروض، وفلتر "الدول اللي مالهاش سعر".' },
                    { name: 'المشتريات', desc: 'مين اشترى أنهي باقة، الباقي من عروضها، السعر، وتاريخ انتهاء الصلاحية.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'تفتح إدارة العملاء (CRM) لباقة',
                steps: [
                    'اختار "محترفين" فوق، وافتح تبويب "الباقات".',
                    'دوس "تعديل" على الباقة (مش المجانية).',
                    'في "إدارة العملاء (CRM)" اختار المستوى: عملاء وملاحظات، أو عملاء وملاحظات وتذكيرات.',
                    'دوس "حفظ". المحترفين المشتركين في الباقة بياخدوا المستوى الجديد من أول طلب جاي من التطبيق.',
                ],
            },
            {
                title: 'تنزّل مستوى الـ CRM أو تقفله',
                steps: [
                    'افتح تعديل الباقة وغيّر المستوى (أو اختار "مقفولة").',
                    'احفظ. شاشة العملاء عند المحترف بتتقفل بدل ما تتمسح، واللي كتبه (ملاحظات ونجوم وتصنيفات وتذكيرات) بيفضل محفوظ ويرجع لما الباقة ترجع.',
                    'النزول لتحت مستوى 2 بيوقف إشعارات التذكيرات.',
                ],
            },
            {
                title: 'تغيّر سعر باقة',
                steps: [
                    'افتح تبويب "الأسعار" واختار الباقة.',
                    'اكتب السعر الشهري والسنوي وراقب عمود التوفير.',
                    'دوس "حفظ" على السطر.',
                ],
            },
            {
                title: 'توقف بيع باقة من غير ما تقطعها عن مشتركيها',
                steps: [
                    'افتح تعديل الباقة وطفّي "معروضة للفريلانسرز".',
                    'احفظ. الباقة بتختفي عن المشتركين الجداد.',
                    'شوف التحذير تحت: مزايا الباقة (الشارة والأولوية والإحصائيات والـ CRM) بتقف لما الباقة متكونش معروضة.',
                ],
            },
        ],
        affects: [
            { text: 'تطبيق المحترف (freelanceApp): شاشة "إحصائيات أدائي" وشاشة العملاء، وشارة الباقة وأولوية الظهور في الترتيب.' },
            { route: 'admin.freelance.policies', text: 'العروض المجانية والعمولة وحدود الخدمات الجاهزة وحدود الفرق الأساسية بتتظبط من الأسعار والقواعد؛ الباقة بتعلّي عليها بس.' },
            { route: 'admin.freelance.switches', text: 'مفتاح "الاشتراكات" في الدولة لازم يكون شغّال، وإلا المحترف بيتعامل كأنه على الباقة المجانية.' },
            { route: 'admin.freelance.teams', text: 'باقات الفرق بتفتح مساحة الفريق (الأشخاص والمشاريع والعروض).' },
        ],
        warnings: [
            { level: 'warning', text: 'لو باقة اتقفلت ("معروضة" مطفية) مزاياها كلها (الشارة والأولوية والإحصائيات والـ CRM) بتقف عند مشتركيها فورًا، حتى لو لسه فترة اشتراكهم ماخلصتش.' },
            { level: 'info', text: 'مزايا الباقة بتيجي من اشتراك ساري بس (تجربة أو مدفوع أو فشل تجديد جوه أيام السماح)، وفي دولة مفتاح الاشتراكات فيها شغّال. غير كده المحترف على المجانية: CRM مقفول.' },
            { level: 'info', text: 'تقسيم مستويات الـ CRM (1 عملاء وملاحظات، 2 كمان تصنيفات وتذكيرات) قرار مبدئي ممكن يتغيّر من هنا من غير كود.' },
            { level: 'info', text: 'مفيش حقل CRM لباقة الفريق ولا للمجانية: الخادم بيتجاهله لو اتبعت.' },
            { level: 'info', text: 'كل تعديل في باقة أو سعر بيتسجّل في سجل المراجعة.' },
        ],
    },

    en: {
        summary: 'The plans desk: what each plan gives a freelancer (or a team), its monthly and yearly price, who is subscribed, and the extra-offer packs a freelancer buys on their own.',
        audience: 'Whoever owns freelance pricing and subscriptions. Needs the "Freelance plans" permission: view to read, update to change a plan or a price.',
        purpose: [
            'Set what each plan gives: offers per month, commission, trial, badge, visibility priority, performance numbers, client management (CRM) and ready-made services.',
            'Set each plan\'s monthly and yearly price and see the yearly saving.',
            'Follow the subscribers, their payment state and the last 30 days of revenue.',
            'Manage the extra-offer packs, their price, and who bought them.',
        ],
        sections: [
            {
                title: 'Top of the screen',
                items: [
                    { name: 'Freelancers / Teams', desc: 'Switches the whole screen between the freelancers\' plans and the teams\' plans (paid by the team Owner from their wallet). The extra-offer and purchases tabs exist for freelancers only.' },
                    { name: 'Number cards', desc: 'Subscribers now (a live subscription), on trial, payment failed, and the last 30 days of revenue per currency.' },
                    { name: 'Tabs', desc: 'Plans, prices, subscribers, extra offers, purchases.' },
                ],
            },
            {
                title: 'Plans tab',
                items: [
                    { name: 'Plan card', desc: 'Name, code and description, a "Free" or "offered / not offered" badge, and a line per perk: offers, commission, trial, perks (badge, priority, numbers, client book, ready-made services) and the number of subscribers.' },
                    { name: 'Edit', desc: 'Opens the plan editor. The Free plan has none: its numbers always follow the country\'s policy.' },
                ],
            },
            {
                title: 'Plan editor',
                items: [
                    { name: 'Name and description (Arabic / English)', desc: 'Both names are required (up to 60 characters). The description is optional (up to 300). The freelancer sees them in the app in their own language.' },
                    { name: 'People in the team / Projects at once', desc: 'Team plans only. Empty = no cap; the larger of this and the policy\'s number applies.' },
                    { name: 'Unlimited offers / Free offers per month', desc: 'With "unlimited" on, the number is sent empty and locked. Otherwise 0 to 100000. If the country\'s own number is higher, that one is used.' },
                    { name: 'Commission %', desc: 'Freelancer plans only, 0 to 100. Empty keeps the country\'s commission. It only lowers, never raises.' },
                    { name: 'Free trial (days)', desc: '0 to 90 days before the first payment.' },
                    { name: 'Plan badge', desc: 'Freelancer plans only. Shown by the freelancer\'s name to clients while the subscription is live.' },
                    { name: 'Visibility priority', desc: 'Freelancer plans only, 0 to 3. A weight in the "Recommended" order only; cheapest / fastest orders are not affected. A work limit or suspension removes the priority.' },
                    {
                        name: 'Performance numbers',
                        desc: 'Freelancer plans only. Locked (0) / Basic (1: last 30 days) / Full (2: 30, 90 and 365 days with earnings by specialty, top clients and portfolio analytics). Opens the "My performance" screen in the freelancer app.',
                    },
                    {
                        name: 'Client management (CRM)',
                        desc: 'Freelancer plans only (not applied to team plans or the Free plan). The freelancer\'s private client book in the app; the client sees none of it. '
                            + 'Locked (0): the screen shows locked and offers the first plan that opens it. '
                            + 'Clients and notes (1): the client list (people they work or worked with, plus leads: a waiting offer or an unanswered invitation), past projects per client, notes (up to 50 per client) and a star. '
                            + 'Clients, notes and reminders (2): everything above + a client label (VIP / regular / lead / paused) and follow-up reminders (up to 30 pending, up to a year ahead), with one notification when a reminder is due. '
                            + 'Default: Plus = 1, Pro = 2.',
                    },
                    { name: 'Most ready-made services', desc: 'Freelancer plans only, 1 to 1000. Empty = the country\'s policy; if the country\'s own number is higher it is used (a plan never lowers it).' },
                    { name: 'Offered to freelancers', desc: 'Turning it off hides the plan from new subscribers; current ones keep it until their period ends.' },
                ],
            },
            {
                title: 'Prices tab',
                items: [
                    { name: 'Plan picker', desc: 'Paid plans only (Free has no price).' },
                    { name: 'Country row', desc: 'Monthly and yearly in the country\'s currency, and the yearly saving as a percent. The platform has one price for the whole world, so one row (the international country) is listed.' },
                    { name: 'Save', desc: 'A price must be above zero, and a year must cost less than twelve months. Prices are stored in the currency\'s minor unit; the screen divides by the currency\'s decimals.' },
                    { name: 'Fill missing prices', desc: 'Adds the missing plans and prices (and the extra-offer packs) from the defaults. A price already set is never touched.' },
                ],
            },
            {
                title: 'Subscribers tab',
                items: [
                    { name: 'Filters', desc: 'State (on trial / active / payment failed / ended) with a count each, plan, and search by freelancer name or phone (for teams: team name or payer).' },
                    { name: 'Table', desc: 'Freelancer (or team and payer), plan and period, state (with "not renewing" when renewal was turned off), price, and the end / renewal date. Read only.' },
                ],
            },
            {
                title: 'Extra offers and Purchases tabs (freelancers only)',
                items: [
                    { name: 'Pack cards', desc: 'Each pack: name, offers it adds, how many sold in the last 30 days, and an edit button (name, offers, offered). Editing a pack never changes purchases already made.' },
                    { name: 'Price per country', desc: 'Like the plans: one price per pack, and the "countries without a price" filter.' },
                    { name: 'Purchases', desc: 'Who bought which pack, how many of its offers are left, the price and the expiry date.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Open the CRM for a plan',
                steps: [
                    'Choose "Freelancers" on top and open the "Plans" tab.',
                    'Click "Edit" on the plan (not Free).',
                    'Under "Client management (CRM)" pick the level: Clients and notes, or Clients, notes and reminders.',
                    'Click "Save". Freelancers on that plan get the new level from the next request their app makes.',
                ],
            },
            {
                title: 'Lower or lock a plan\'s CRM',
                steps: [
                    'Open the plan editor and change the level (or pick "Locked").',
                    'Save. The freelancer\'s client screen locks instead of being erased; what they wrote (notes, stars, labels, reminders) is kept and comes back with the plan.',
                    'Dropping below level 2 stops the reminder notifications.',
                ],
            },
            {
                title: 'Change a plan\'s price',
                steps: [
                    'Open the "Prices" tab and pick the plan.',
                    'Type the monthly and yearly price and watch the saving column.',
                    'Click "Save" on the row.',
                ],
            },
            {
                title: 'Stop selling a plan without cutting off its subscribers',
                steps: [
                    'Open the plan editor and switch "Offered to freelancers" off.',
                    'Save. The plan disappears for new subscribers.',
                    'Read the warning below: the plan\'s perks (badge, priority, numbers, CRM) stop while the plan is not offered.',
                ],
            },
        ],
        affects: [
            { text: 'The freelancer app (freelanceApp): the "My performance" and clients screens, the plan badge and the visibility priority in the ordering.' },
            { route: 'admin.freelance.policies', text: 'Free offers, commission, ready-made service limits and the base team limits are set in Prices & rules; a plan only raises them.' },
            { route: 'admin.freelance.switches', text: 'The country\'s "subscriptions" switch must be on, or the freelancer is treated as on the Free plan.' },
            { route: 'admin.freelance.teams', text: 'Team plans open the team\'s room (people, projects, offers).' },
        ],
        warnings: [
            { level: 'warning', text: 'When a plan is switched off ("Offered" off), all its perks (badge, priority, numbers, CRM) stop for its subscribers right away, even if their period has not ended.' },
            { level: 'info', text: 'A plan\'s perks come only from a live subscription (trial, paid, or a failed renewal inside its grace days), in a country where the subscriptions switch is on. Otherwise the freelancer is on Free: CRM locked.' },
            { level: 'info', text: 'The CRM level split (1 clients and notes, 2 also labels and reminders) is a provisional decision and can be changed here without code.' },
            { level: 'info', text: 'There is no CRM field for team plans or the Free plan: the server ignores it if sent.' },
            { level: 'info', text: 'Every plan and price change is written to the audit log.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/freelance/plans/index.vue (plans, prices, subscribers, packs, purchases; the editor is a WalletModal)',
            'Modules/Freelance/app/Http/Controllers/Admin/FreelancePlanController.php (index, update, prices, setPrice, fillPrices, subscriptions)',
            'Modules/Freelance/app/Http/Controllers/Admin/FreelanceOfferPackController.php (packs, prices, purchases)',
            'Modules/Freelance/app/Models/FreelancePlan.php (crm, analytics, boost, badge, audience)',
            'Modules/Freelance/app/Services/Plans/FreelancePerks.php (crmLevel, planForCrm)', 'Modules/Freelance/app/Services/Plans/FreelancePlanDefaults.php',
            'Modules/Freelance/config/config.php (plans.*.crm, crm.* limits)',
        ],
        api: [
            'GET  /api/admin/v1/freelance-plans                         (plans incl. crm, subscribers per plan, revenue_30d, defaults)',
            'PUT  /api/admin/v1/freelance-plans/{id}                    (name, description, offers_*, commission_bp, trial_days, status, badge, boost, analytics, crm 0..2, services_max, team_*)',
            'GET  /api/admin/v1/freelance-plans/{id}/prices · PUT …/prices/{country}',
            'POST /api/admin/v1/freelance-plans/fill-prices',
            'GET  /api/admin/v1/freelance-plans/subscriptions           (?audience, status, plan, search, page)',
            'GET  /api/admin/v1/freelance-plans/packs · PUT …/packs/{id} · GET …/packs/{id}/prices · PUT …/packs/{id}/prices/{country}',
            'GET  /api/admin/v1/freelance-plans/pack-purchases          (?pack, search, page)',
        ],
        tables: [
            'freelance_plans (crm, analytics, boost, badge, services_max, audience, team_members_max, team_projects_max, status)', 'freelance_plan_prices',
            'freelance_subscriptions', 'freelance_team_subscriptions', 'freelance_subscription_invoices', 'freelance_team_subscription_invoices',
            'freelance_offer_packs', 'freelance_offer_pack_prices', 'freelance_offer_credits',
        ],
        permissions: ['freelance-plans.view (screen, lists, prices)', 'freelance-plans.update (edit a plan or a pack, set prices, fill missing prices)'],
        decisions: [
            {
                ar: 'مستويات الـ CRM: 0 مفيش، 1 عملاء ومشاريع سابقة وملاحظات ونجمة، 2 كمان تصنيفات وتذكيرات. التقسيم قرار مبدئي بيتغيّر من الشاشة دي من غير كود.',
                en: 'CRM levels: 0 none, 1 clients, past projects, notes and a star, 2 also labels and reminders. The split is provisional and changes on this screen without code.',
            },
            {
                ar: 'الحقل crm بيتجاهله الخادم لباقة الفريق وللباقة المجانية، زي الشارة والأولوية والإحصائيات.',
                en: 'The server ignores `crm` for team plans and the Free plan, like the badge, boost and analytics.',
            },
        ],
    },
};
