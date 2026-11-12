/**
 * Guide: source code (ZIP) download switch (admin.ai-site-download.index).
 * Source of truth checked: views/ai-site-download/index.vue, views/ai-site-offers/ModalOfferPrices.vue,
 * Modules/AI/routes/admin.php (admin/v1/ai-site-download, admin/v1/ai-site-offers/{offer}/prices),
 * Http/Controllers/AiSiteDownloadSettingController.php, Services/Sites/AiSiteProjectService.php
 * (sourceDownloadOffer, sourceDownloadStatus, assertCanDownload, buySourceDownload, purchase),
 * Models/AiSiteOffer.php (KIND_SOURCE_DOWNLOAD, priceFor), Models/AiSiteProject.php (sourceDownloadPurchases).
 */
export default {
    route: 'admin.ai-site-download.index',
    updated: '2026-11-10',

    ar: {
        summary: 'المفتاح اللي بيتحكم في زرار "نزّل موقعك كملف ZIP" عند العميل في أداة بناء المواقع: مسموح ولا لأ، ومجاني ولا بفلوس، وبكام في كل دولة.',
        audience: 'مدير التسعير أو المنتج. الفتح محتاج "تحميل سورس الموقع: عرض"، وأي تغيير محتاج "تعديل". إدارة الأسعار محتاجة كمان صلاحية "عروض المواقع" (شوف ملاحظة المطورين).',
        purpose: [
            'تقفل تحميل السورس خالص، أو تفتحه مجاني للكل، أو تبيعه.',
            'في وضع "مدفوع": كل موقع بيتفتح مرة واحدة من محفظة العميل بسعر بلده.',
        ],
        sections: [
            {
                title: 'كارت التشغيل والتسعير',
                items: [
                    { name: 'السماح بتحميل السورس', desc: 'بيتحفظ فورًا. لو مقفول: العميل مش هيقدر ينزّل ملفات أي موقع، حتى اللي دفع قبل كده.' },
                    { name: 'مجاني / مدفوع', desc: 'بيتحفظ فورًا ومقفول لو التحميل مقفول. مجاني: أي عميل ينزّل من غير مقابل. مدفوع: كل موقع محتاج شراء مرة واحدة.' },
                ],
            },
            {
                title: 'كارت السعر لكل دولة',
                items: [
                    { name: 'الشارة', desc: '"مجاني للجميع"، أو "مسعّر في X دولة"، أو "مفيش أسعار".' },
                    { name: 'تنبيه أصفر', desc: 'بيظهر لو مدفوع ومفيش ولا سعر: محدش هيقدر يفتح التحميل.' },
                    { name: 'إدارة الأسعار', desc: 'نافذة فيها صف لكل دولة بعملتها. سيب السعر فاضي لو مش هتبيعه في الدولة دي.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'بيع تحميل السورس',
                steps: [
                    'اتأكد إن "السماح بتحميل السورس" شغال.',
                    'دوس "إدارة الأسعار" واكتب السعر في الدول اللي هتبيع فيها واحفظ.',
                    'دوس "مدفوع". الشارة هتقول مسعّر في كام دولة.',
                ],
            },
            {
                title: 'وقف التحميل مؤقتًا',
                steps: [
                    'اقفل "السماح بتحميل السورس". الزرار بيختفي أو بيتقفل عند كل العملاء لحد ما تفتحه تاني.',
                ],
            },
        ],
        affects: [
            { text: 'أداة بناء المواقع في تطبيق اليوزر ومقدم الخدمة: زرار تحميل الـ ZIP (مقفول / مجاني / "افتح بسعر كذا").' },
            { text: 'محفظة العميل: شراء فتح التحميل بيتخصم منها بعملة بلده.' },
            { route: 'admin.ai-site-offers.index', text: 'عروض المواقع: التحميل نفسه عرض من نوع خاص (source_download) ومش بيظهر في قايمة العروض، بس أسعاره بتتحفظ بنفس endpoints العروض.' },
            { route: 'admin.ai-sites.index', text: 'مواقع العملاء: الموقع الموقوف مينفعش يتشترى له التحميل.' },
        ],
        warnings: [
            { level: 'danger', text: 'لو "مدفوع" ومفيش سعر للدولة، العميل في الدولة دي مش هيقدر يشتري ولا ينزّل خالص.' },
            { level: 'warning', text: 'لو رجعت من "مجاني" لـ "مدفوع"، العملاء اللي كانوا بينزّلوا مجانًا هيتقفل عليهم لحد ما يشتروا، لأن الفتح المجاني مش بيتسجل كشراء.' },
            { level: 'info', text: 'قبل أول مرة حد يفتح الشاشة دي، التحميل كان مجاني ومفتوح للكل. أول فتح بيعمل الإعداد ده مفعّل ومجاني.' },
            { level: 'info', text: 'لو معاك صلاحية العرض بس: المفاتيح بتظهر مقفولة وزرار "إدارة الأسعار" مش بيظهر.' },
        ],
    },

    en: {
        summary: 'The switch behind the customer’s "download your site as a ZIP" button in the AI site builder: allowed or not, free or paid, and the price per country.',
        audience: 'Pricing or product owner. Opening needs "Source code download: view"; any change needs "update". Managing prices also needs the "Site offers" permission (see developer notes).',
        purpose: [
            'Close source download, open it free for everyone, or sell it.',
            'In "Paid" mode each site is unlocked once from the customer wallet at their country price.',
        ],
        sections: [
            {
                title: 'On/off and pricing card',
                items: [
                    { name: 'Allow source download', desc: 'Saved immediately. Off: no customer can download any site files, even ones already paid for.' },
                    { name: 'Free / Paid', desc: 'Saved immediately; disabled while download is off. Free: anyone downloads at no cost. Paid: each site needs a one-time purchase.' },
                ],
            },
            {
                title: 'Price per country card',
                items: [
                    { name: 'Badge', desc: '"Free for everyone", "Priced in X countries" or "No prices".' },
                    { name: 'Yellow warning', desc: 'Paid with no price at all: nobody can unlock the download.' },
                    { name: 'Manage prices', desc: 'A row per country in its currency. Leave empty to not sell there.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Sell source download',
                steps: ['Make sure "Allow source download" is on.', 'Click "Manage prices", enter prices for the countries you sell in and save.', 'Click "Paid"; the badge shows how many countries are priced.'],
            },
            {
                title: 'Pause downloads',
                steps: ['Switch off "Allow source download"; the button is closed for every customer until you switch it back on.'],
            },
        ],
        affects: [
            { text: 'Site builder in the user and provider apps: the ZIP download button (closed / free / "unlock for X").' },
            { text: 'Customer wallet: the unlock purchase is charged in their country currency.' },
            { route: 'admin.ai-site-offers.index', text: 'Site offers: the download is a special offer (source_download) hidden from the offers list, but its prices use the offer price endpoints.' },
            { route: 'admin.ai-sites.index', text: 'Customer websites: a disabled site cannot buy the download.' },
        ],
        warnings: [
            { level: 'danger', text: 'Paid with no price for a country means customers there can neither buy nor download.' },
            { level: 'warning', text: 'Switching back from Free to Paid locks customers who downloaded for free until they buy — a free download is not recorded as a purchase.' },
            { level: 'info', text: 'Before anyone opens this screen, download is free and open to all; the first visit creates this setting as on and free.' },
            { level: 'info', text: 'With view only: the switches are disabled and "Manage prices" is hidden.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-site-download/index.vue',
            'resources/js/modules/admin/themes/theme-1/views/ai-site-offers/ModalOfferPrices.vue (shared, not edited here)',
            'Modules/AI/app/Http/Controllers/AiSiteDownloadSettingController.php',
            'Modules/AI/app/Services/Sites/AiSiteProjectService.php (sourceDownloadStatus, buySourceDownload)',
            'Modules/AI/app/Models/AiSiteOffer.php (kind source_download)',
        ],
        api: [
            'GET|PUT /api/admin/v1/ai-site-download          (is_active, is_free; GET creates the offer on first look)',
            'GET|PUT /api/admin/v1/ai-site-offers/{offer}/prices (used by the prices modal — group ai-site-offers)',
        ],
        tables: ['ai_site_offers (kind = source_download)', 'ai_site_offer_prices', 'ai_site_purchases (project_id)'],
        permissions: [
            'ai-site-download.view (screen)',
            'ai-site-download.update (on/off, free/paid)',
            'ai-site-offers.view / ai-site-offers.update (prices modal)',
        ],
        decisions: [
            {
                ar: 'نافذة الأسعار بتكلم /ai-site-offers/{offer}/prices اللي محمية بصلاحيات "عروض المواقع"، فأدمن معاه صلاحية الشاشة دي بس هياخد 403 لما يفتح أو يحفظ الأسعار. محتاج قرار: endpoint أسعار خاص بـ ai-site-download، أو نضيف ai-site-download كـ reader لـ ai-site-offers؟',
                en: 'The prices modal calls /ai-site-offers/{offer}/prices, guarded by the "Site offers" permissions, so an admin with only this screen gets 403 opening or saving prices. NEEDS-DECISION: a prices endpoint under ai-site-download, or ai-site-download as a reader (and writer) of ai-site-offers?',
            },
        ],
    },
};
