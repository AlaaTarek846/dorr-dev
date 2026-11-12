/**
 * Guide: customer websites built with the AI site builder (admin.ai-sites.index).
 * Source of truth checked: views/ai-sites/index.vue, Modules/AI/routes/admin.php (admin/v1/ai-sites),
 * Http/Controllers/AiSiteAdminProjectController.php, Http/Resources/AiSiteProjectResource.php (preview_url, is_disabled),
 * Services/Sites/AiSiteProjectService.php (delete force, isDisabled checks), AiSiteGenerationService.php,
 * AiSiteHostingService.php, Http/Controllers/AiSiteServeController.php, Http/Middleware/ServeHostedSiteMiddleware.php,
 * Models/AiSiteProject.php (SoftDeletes, disabled_at).
 */
export default {
    route: 'admin.ai-sites.index',
    updated: '2026-11-11',

    ar: {
        summary: 'كل المواقع اللي العملاء بنوها بأداة بناء المواقع بالذكاء الاصطناعي. من هنا بتفتح أي موقع، وتوقفه لو فيه مخالفة، وترجّعه، أو تمسحه نهائيًا.',
        audience: 'فريق المراجعة والدعم. الفتح محتاج "مواقع العملاء: عرض". الإيقاف والتفعيل محتاجين "تعديل"، والحذف محتاج "حذف".',
        purpose: [
            'مراجعة المحتوى: تفتح الموقع وتشوفه زي ما الناس بتشوفه.',
            'الإشراف: توقف موقع مخالف فورًا من غير ما تمسحه.',
            'الدعم: تشوف المواقع اللي البناء فيها فشل وسبب الفشل.',
        ],
        sections: [
            {
                title: 'الفلاتر',
                items: [
                    { name: 'بحث بالعنوان', desc: 'بجزء من اسم الموقع. دوس Enter.' },
                    { name: 'كل الحالات', desc: 'جاري البناء، جاهز، فشل.' },
                    { name: 'كل أنواع الوصول', desc: '"باقة" = الموقع اتبنى من حصة اشتراك الذكاء الاصطناعي. "شراء" = العميل اشترى عرض موقع لوحده.' },
                    { name: 'الموقوفة فقط', desc: 'المواقع اللي اتوقفت من الإدارة بس.' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'الموقع', desc: 'اسم الموقع، وتحته بالأحمر كود آخر خطأ لو البناء فشل.' },
                    { name: 'المالك', desc: 'اسم صاحب الموقع ورقمه.' },
                    { name: 'الوصول', desc: 'باقة أو شراء.' },
                    { name: 'الحالة', desc: 'جاري البناء / جاهز / فشل، أو "موقوف" لو الإدارة وقفته.' },
                    { name: 'تاريخ الإنشاء', desc: 'إمتى العميل بدأ الموقع.' },
                    { name: 'فتح', desc: 'رابط المعاينة العام. بيظهر بس لو الموقع عنده نسخة جاهزة ومش موقوف.' },
                    { name: 'إيقاف / تفعيل', desc: 'إيقاف: الموقع بيقف عن الظهور للناس (المعاينة والاستضافة)، والعميل مش هيقدر يعدّل أو يبني أو يستضيف أو يشتري تحميل. تفعيل بيرجّع كل ده.' },
                    { name: '🗑️ حذف', desc: 'بعد تأكيد: بيمسح الموقع وكل ملفاته واستضافته اللي خلصت. لو الموقع عليه استضافة مدفوعة لسه شغالة (أو موقوفة من الإدارة وفترتها المدفوعة لسه مخلصتش) الحذف بيترفض برسالة، ولازم توقف/تلغي الاستضافة من شاشة استضافة المواقع وتستنى فترتها تخلص الأول.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'التعامل مع بلاغ عن موقع مخالف',
                steps: [
                    'ابحث باسم الموقع.',
                    'دوس "فتح" وراجع المحتوى.',
                    'لو مخالف دوس "إيقاف". الموقع بيقف فورًا على المعاينة وعلى العنوان المستضاف.',
                    'لو المخالفة اتصلحت أو البلاغ طلع غلط، دوس "تفعيل".',
                ],
            },
            {
                title: 'متابعة مواقع البناء فيها فشل',
                steps: [
                    'اختار حالة "فشل".',
                    'اقرا كود الخطأ تحت اسم الموقع، وتابع مع فريق الذكاء الاصطناعي أو العميل.',
                ],
            },
        ],
        affects: [
            { text: 'الموقع العام للعميل (رابط المعاينة والعنوان المستضاف): بيقف أو يرجع.' },
            { text: 'أداة بناء المواقع في تطبيق العميل: التعديل والبناء والاستضافة وشراء التحميل بتتقفل للموقع الموقوف.' },
            { route: 'admin.ai-site-hostings.index', text: 'المواقع المستضافة: الموقع الموقوف مش بيتعرض على عنوانه، والحذف بيمسح استضافته ويحرر العنوان.' },
            { route: 'admin.ai-site-download.index', text: 'تحميل السورس: الموقع الموقوف مينفعش يتشترى له التحميل.' },
        ],
        warnings: [
            { level: 'danger', text: 'الحذف نهائي من الشاشة: ملفات الموقع بتتمسح ومفيش زرار ترجيع. الموقع اللي عليه استضافة مدفوعة لسه وقتها شغال مينفعش يتحذف لحد ما الاستضافة تخلص.' },
            { level: 'warning', text: 'الإيقاف مش بيوقف تجديد الاستضافة المدفوعة. لو الإيقاف هيطول، راجع استضافة الموقع.' },
            { level: 'info', text: 'العميل مش بيوصله إشعار لما توقف أو تفعّل موقعه.' },
            { level: 'info', text: 'لو معاك صلاحية العرض بس: أزرار الإيقاف والتفعيل والحذف مش بتظهر، ورابط "فتح" بيفضل.' },
        ],
    },

    en: {
        summary: 'Every website customers built with the AI site builder. Open any site, take it offline for a violation, bring it back, or delete it for good.',
        audience: 'Moderation and support. Opening needs "Customer websites: view"; disable/enable need "update"; delete needs "delete".',
        purpose: [
            'Content review: open the site as the public sees it.',
            'Moderation: take a violating site offline at once without deleting it.',
            'Support: see failed builds and why they failed.',
        ],
        sections: [
            {
                title: 'Filters',
                items: [
                    { name: 'Search by title', desc: 'Part of the site name; press Enter.' },
                    { name: 'All statuses', desc: 'Building, ready, failed.' },
                    { name: 'All access types', desc: '"Plan" = built from the AI subscription quota. "Purchased" = the customer bought a standalone site offer.' },
                    { name: 'Only disabled', desc: 'Sites taken offline by an admin.' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Site', desc: 'Site name, with the last error code in red when the build failed.' },
                    { name: 'Owner', desc: 'Owner name and id.' },
                    { name: 'Access', desc: 'Plan or purchased.' },
                    { name: 'Status', desc: 'Building / ready / failed, or "Disabled" when an admin took it offline.' },
                    { name: 'Created', desc: 'When the customer started the site.' },
                    { name: 'Open', desc: 'Public preview link; only when the site has a built version and is not disabled.' },
                    { name: 'Disable / Enable', desc: 'Disable: the site stops being served (preview and hosted address) and the customer cannot edit, build, host or buy the download. Enable restores all of it.' },
                    { name: '🗑️ Delete', desc: 'After confirmation: deletes the site, all its files and its ended hosting. While the site has paid hosting that is still running (or paused by an admin before its paid period ends) delete is refused with a message: suspend/cancel the hosting on Site hosting and let its period end first.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Handle a report about a violating site',
                steps: ['Search for the site.', 'Click "Open" and review it.', 'If it violates the rules click "Disable" — it goes offline at once on the preview and its hosted address.', 'When fixed or the report was wrong, click "Enable".'],
            },
            {
                title: 'Follow failed builds',
                steps: ['Pick the "Failed" status.', 'Read the error code under the site name and follow up with the AI team or the customer.'],
            },
        ],
        affects: [
            { text: 'The customer’s public site (preview link and hosted address) goes offline or back online.' },
            { text: 'Site builder in the customer app: editing, building, hosting and buying the download are blocked for a disabled site.' },
            { route: 'admin.ai-site-hostings.index', text: 'Hosted websites: a disabled site is not served on its address; deleting removes its hosting and frees the address.' },
            { route: 'admin.ai-site-download.index', text: 'Source download: a disabled site cannot buy the download.' },
        ],
        warnings: [
            { level: 'danger', text: 'Delete is final from this screen: site files are erased and there is no restore button. A site whose paid hosting still has time left cannot be deleted until that hosting ends.' },
            { level: 'warning', text: 'Disabling does not stop a paid hosting from renewing. For a long suspension, check the site’s hosting.' },
            { level: 'info', text: 'The customer is not notified when you disable or enable their site.' },
            { level: 'info', text: 'With view only: disable, enable and delete are hidden; "Open" stays.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-sites/index.vue',
            'Modules/AI/app/Http/Controllers/AiSiteAdminProjectController.php',
            'Modules/AI/app/Http/Resources/AiSiteProjectResource.php',
            'Modules/AI/app/Services/Sites/AiSiteProjectService.php (delete(force: true))',
            'Modules/AI/app/Http/Controllers/AiSiteServeController.php, Http/Middleware/ServeHostedSiteMiddleware.php',
        ],
        api: [
            'GET    /api/admin/v1/ai-sites         (?search, status, access_type, disabled=1, page, per_page)',
            'GET    /api/admin/v1/ai-sites/{project}',
            'POST   /api/admin/v1/ai-sites/{project}/disable · /enable   (disabled_at)',
            'DELETE /api/admin/v1/ai-sites/{project}  (422 site_admin_hosting_active while AiSiteHosting::hasPaidTimeLeft(); else files + ended hosting removed, project soft-deleted)',
        ],
        tables: ['ai_site_projects (disabled_at, deleted_at)', 'ai_site_versions', 'ai_site_hostings', 'ai_site_purchases'],
        permissions: [
            'ai-sites.view (screen, list)',
            'ai-sites.update (disable, enable)',
            'ai-sites.delete (destroy)',
        ],
        decisions: [
            {
                ar: 'الجدول بيعرض كود الخطأ الخام (last_error) مع إن الـ API بيرجّع رسالة مترجمة (last_error_message).',
                en: 'The table shows the raw error code (last_error) although the API also returns a translated last_error_message.',
            },
        ],
    },
};
