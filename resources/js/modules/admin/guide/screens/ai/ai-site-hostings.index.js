/**
 * Guide: hosted customer websites (admin.ai-site-hostings.index).
 * Source of truth checked: views/ai-site-hostings/index.vue, Modules/AI/routes/admin.php (admin/v1/ai-site-hostings),
 * Http/Controllers/AiSiteAdminHostingController.php, Http/Resources/AiSiteHostingResource.php,
 * Models/AiSiteHosting.php (statuses, isLive), Services/Sites/AiSiteHostingService.php (subscribe, renew,
 * processDue: grace, suspend, delete after N days), Http/Middleware/ServeHostedSiteMiddleware.php.
 */
export default {
    route: 'admin.ai-site-hostings.index',
    updated: '2026-11-10',

    ar: {
        summary: 'كل موقع عميل منشور على عنوانه الخاص (استضافة شهري أو سنوي). من هنا بتشوف حالة كل استضافة ومدفوعة لحد إمتى، وبتوقفها من الإدارة لو فيه مخالفة، أو ترجّعها، أو تشيلها وتحرر العنوان.',
        audience: 'فريق المراجعة والدعم. الفتح محتاج "المواقع المستضافة: عرض". الإيقاف والتشغيل محتاجين "تعديل"، والإزالة محتاجة "حذف".',
        purpose: [
            'متابعة الاستضافات: شغالة، في فترة سماح، ملغية لحد نهاية المدة، أو موقوفة.',
            'إيقاف عنوان مخالف فورًا (إساءة، احتيال...).',
            'تحرير عنوان محجوز لما يكون لازم.',
        ],
        sections: [
            {
                title: 'الفلاتر',
                items: [
                    { name: 'بحث بالعنوان', desc: 'بجزء من اسم العنوان (subdomain). دوس Enter.' },
                    { name: 'الحالة', desc: 'شغّال، فترة سماح، ملغي (لحد النهاية)، موقوف.' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'العنوان', desc: 'اسم العنوان (بيفتح الموقع في تاب جديد)، وتحته اسم الموقع.' },
                    { name: 'المالك', desc: 'اسم العميل ورقمه.' },
                    { name: 'الباقة', desc: 'اسم باقة الاستضافة، والمبلغ والعملة والفترة (شهري / سنوي) اللي اتدفعت.' },
                    { name: 'الحالة', desc: 'لو الموقع ظاهر للناس: شغّال / فترة سماح / ملغي (لحد النهاية). لو مش ظاهر: "موقوف من الإدارة" أو "أوفلاين" (المدة خلصت). شارة "من غير تجديد تلقائي" لو العميل قفل التجديد.' },
                    { name: 'مدفوع لحد', desc: 'نهاية المدة المدفوعة.' },
                    { name: 'إيقاف', desc: 'بيظهر للاستضافة الظاهرة. الموقع بيقف عن الظهور فورًا، والتجديد التلقائي بيتوقف، والعميل مش هيقدر يجدد بإيده.' },
                    { name: 'تفعيل', desc: 'بيظهر للاستضافة الموقوفة من الإدارة. بيشيل إيقاف الإدارة بس؛ لو المدة كمان خلصت بتفضل أوفلاين لحد ما العميل يجدد.' },
                    { name: '🗑️ إزالة', desc: 'بعد تأكيد: بتمسح الاستضافة وبتحرر العنوان. العميل بيخسر الموقع العام (الموقع نفسه في أداة البناء بيفضل).' },
                ],
            },
        ],
        tasks: [
            {
                title: 'إيقاف عنوان مخالف',
                steps: [
                    'ابحث بالعنوان.',
                    'دوس على العنوان واتأكد من المخالفة.',
                    'دوس "إيقاف". الحالة هتبقى "موقوف من الإدارة".',
                ],
            },
            {
                title: 'ترجيع استضافة اتوقفت بالغلط',
                steps: [
                    'اختار حالة الاستضافة أو ابحث بالعنوان.',
                    'دوس "تفعيل". لو المدة المدفوعة لسه شغالة الموقع بيرجع فورًا.',
                ],
            },
        ],
        affects: [
            { text: 'الموقع العام على عنوانه: بيقف أو يرجع فورًا.' },
            { text: 'أداة بناء المواقع عند العميل: حالة الاستضافة، والتجديد بيترفض وهي موقوفة من الإدارة.' },
            { route: 'admin.ai-site-hosting-plans.index', text: 'باقات الاستضافة: كل استضافة مربوطة بباقة من هناك وسعرها في بلد العميل.' },
            { route: 'admin.ai-sites.index', text: 'مواقع العملاء: الموقع الموقوف هناك مش بيتعرض على عنوانه حتى لو الاستضافة شغالة.' },
        ],
        warnings: [
            { level: 'danger', text: 'الإزالة نهائية: العنوان بيتحرر وممكن حد تاني ياخده، ومفيش استرجاع فلوس تلقائي للمدة الباقية.' },
            { level: 'warning', text: 'الإيقاف من الإدارة مش بيرجّع فلوس ومش بيبعت إشعار للعميل.' },
            { level: 'info', text: 'النظام لوحده: لما المدة تخلص بيحاول يجدد من المحفظة، ولو فشل بيدي فترة سماح (3 أيام افتراضيًا)، وبعدها بيوقف الموقع، وبعد 30 يوم موقوف بيمسح الاستضافة ويحرر العنوان.' },
            { level: 'info', text: 'الاستضافة الموقوفة من الإدارة مش بتتمسح تلقائيًا بعد 30 يوم، لأن العد ده للاستضافات اللي وقفت لعدم الدفع بس.' },
            { level: 'info', text: 'لو معاك صلاحية العرض بس: أزرار الإيقاف والتفعيل والإزالة مش بتظهر.' },
        ],
    },

    en: {
        summary: 'Every customer website published on its own address (monthly or yearly hosting). See each hosting’s state and paid-until date, suspend it for abuse, resume it, or remove it and free the address.',
        audience: 'Moderation and support. Opening needs "Hosted websites: view"; suspend/resume need "update"; remove needs "delete".',
        purpose: [
            'Follow hostings: active, in grace, cancelled until the end, or suspended.',
            'Take an abusive address offline at once.',
            'Free a reserved address when needed.',
        ],
        sections: [
            {
                title: 'Filters',
                items: [
                    { name: 'Search by address', desc: 'Part of the subdomain; press Enter.' },
                    { name: 'Status', desc: 'Active, grace period, cancelled (until end), suspended.' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Address', desc: 'The subdomain (opens the site in a new tab) with the site name under it.' },
                    { name: 'Owner', desc: 'Customer name and id.' },
                    { name: 'Plan', desc: 'Hosting plan name and the amount, currency and period (monthly / yearly) paid.' },
                    { name: 'Status', desc: 'When served: active / grace / cancelled (until end). When not: "Suspended by admin" or "Offline" (period over). "No auto-renew" badge when the customer turned renewal off.' },
                    { name: 'Paid until', desc: 'End of the paid period.' },
                    { name: 'Suspend', desc: 'Shown for a live hosting. The site goes offline at once, auto-renew is skipped and the customer cannot renew by hand.' },
                    { name: 'Enable', desc: 'Shown for an admin-suspended hosting. Lifts only the admin suspension; if the period also ended it stays offline until the customer renews.' },
                    { name: '🗑️ Remove', desc: 'After confirmation: deletes the hosting and frees the address. The public site is gone (the site itself stays in the builder).' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Take an abusive address offline',
                steps: ['Search for the address.', 'Open it and confirm the abuse.', 'Click "Suspend"; the status becomes "Suspended by admin".'],
            },
            {
                title: 'Bring back a hosting suspended by mistake',
                steps: ['Filter or search for it.', 'Click "Enable"; if the paid period is still running the site is back at once.'],
            },
        ],
        affects: [
            { text: 'The public site on its address goes offline or back at once.' },
            { text: 'Site builder in the customer app: hosting state; renewing is refused while admin-suspended.' },
            { route: 'admin.ai-site-hosting-plans.index', text: 'Hosting plans: each hosting points at a plan and its price in the customer country.' },
            { route: 'admin.ai-sites.index', text: 'Customer websites: a site disabled there is not served even if its hosting is live.' },
        ],
        warnings: [
            { level: 'danger', text: 'Remove is final: the address is freed (someone else can take it) and nothing is refunded for the remaining period.' },
            { level: 'warning', text: 'An admin suspension refunds nothing and does not notify the customer.' },
            { level: 'info', text: 'Automatically: at the end of the period the system renews from the wallet; on failure it gives a grace period (3 days by default), then takes the site offline, and after 30 days offline deletes the hosting and frees the address.' },
            { level: 'info', text: 'Admin-suspended hostings are not auto-deleted after 30 days — that countdown only applies to hostings suspended for non-payment.' },
            { level: 'info', text: 'With view only: suspend, enable and remove are hidden.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-site-hostings/index.vue',
            'Modules/AI/app/Http/Controllers/AiSiteAdminHostingController.php',
            'Modules/AI/app/Http/Resources/AiSiteHostingResource.php',
            'Modules/AI/app/Models/AiSiteHosting.php (isLive)',
            'Modules/AI/app/Services/Sites/AiSiteHostingService.php (renew, processDue)',
        ],
        api: [
            'GET    /api/admin/v1/ai-site-hostings            (?search, status, page, per_page)',
            'GET    /api/admin/v1/ai-site-hostings/{hosting}',
            'POST   /api/admin/v1/ai-site-hostings/{hosting}/suspend · /resume   (admin_suspended, suspended_at)',
            'DELETE /api/admin/v1/ai-site-hostings/{hosting}  (hard delete, frees the subdomain)',
        ],
        tables: ['ai_site_hostings (status, admin_suspended, auto_renew, ends_at, grace_ends_at, suspended_at)', 'ai_site_hosting_plans', 'ai_site_hosting_payments'],
        permissions: [
            'ai-site-hostings.view (screen, list)',
            'ai-site-hostings.update (suspend, resume)',
            'ai-site-hostings.delete (destroy)',
        ],
        decisions: [
            {
                ar: 'استضافة "شغّال" بتجديد تلقائي اتوقفت من الإدارة وعدّت نهاية مدتها: الجدولة مش بتجددها ولا بتعلّمها موقوفة. لما تتفعّل تاني بتظهر للناس لحد الدورة الجاية للجدولة رغم إن المدة خلصت. محتاج قرار.',
                en: 'An "active" auto-renew hosting that is admin-suspended past its end date is neither renewed nor marked suspended by the scheduler; on resume it is served until the next scheduler pass despite the lapsed period. NEEDS-DECISION.',
            },
            {
                ar: 'الإزالة والإيقاف من الإدارة مش بيبعتوا إشعار للعميل ومش بيرجّعوا فلوس.',
                en: 'Admin remove and suspend send no customer notification and refund nothing.',
            },
        ],
    },
};
