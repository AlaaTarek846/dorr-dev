/**
 * Guide: website offers (admin.ai-site-offers.index) - whole AI websites sold from the wallet.
 * Source of truth checked: views/ai-site-offers/*.vue, composables/useAiSiteOffers.js,
 * Modules/AI/app/Models/AiSiteOffer.php (+ Concerns/HasFlatTranslatedText.php), Repositories/AiSiteOfferRepository.php,
 * Http/Controllers/AiSiteOfferController.php, Http/Requests/AiSiteOfferRequest.php, Http/Resources/AiSiteOfferResource.php,
 * Http/Controllers/AiSiteProjectController.php (offers), Services/Sites/AiSiteProjectService.php (purchase).
 */
export default {
    route: 'admin.ai-site-offers.index',
    updated: '2026-11-10',

    ar: {
        summary: 'هنا بتعمل "عروض المواقع": العميل اللي مش مشترك في باقة فيها بناء مواقع يقدر يشتري موقع واحد بالذكاء الاصطناعي من محفظته. لكل عرض اسم ووصف بكل لغة، وعدد مرات بناء/تعديل، وسعر لكل دولة.',
        audience: 'مسؤول التسعير أو المنتج. محتاج صلاحيات "عروض المواقع": عرض، إضافة، تعديل، حذف، تغيير الحالة، حذف متعدد.',
        purpose: [
            'بيع موقع لوحده (مثلًا بورتفوليو) لعميل مالوش باقة فيها مواقع.',
            'العرض بيظهر في التطبيق في الدولة اللي ليها سعر بس. دولة من غير سعر = العرض مش ظاهر هناك.',
            'الاسم والوصف مكتوبين بكل لغة، والعميل بيشوفهم بلغة التطبيق بتاعه.',
        ],
        sections: [
            {
                title: 'شريط الأدوات',
                items: [
                    { name: 'البحث', desc: 'بالاسم أو الوصف (في أي لغة) أو بالكود.' },
                    { name: 'الكل / مفعّلة / متوقفة', desc: 'فلترة سريعة وجنب كل واحدة العدد. العدد بتاع عروض المواقع بس، مش باقات التعديل.' },
                    { name: 'المحذوفات', desc: 'بتظهر لو فيه عروض في السلة: ترجّع عرض أو تحذفه نهائيًا.' },
                    { name: 'حذف المحدد', desc: 'تحدد كذا عرض وتنقلهم للسلة مرة واحدة (صلاحية "حذف متعدد").' },
                    { name: 'إضافة عرض', desc: 'بيفتح فورم عرض جديد (صلاحية "إضافة").' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'الاسم', desc: 'بلغة لوحة التحكم، وتحته رقم العرض. دوس عليه يفتح التعديل.' },
                    { name: 'الكود', desc: 'معرّف ثابت، العميل مش بيشوفه.' },
                    { name: 'عدد البناء/التعديل المشمول', desc: 'العميل بياخد العدد ده مع الشراء.' },
                    { name: 'الدول', desc: 'عدد الدول اللي ليها سعر. "مفيش سعر" = العرض مش هيظهر لحد.' },
                    { name: 'الحالة', desc: 'زرار تشغيل وإيقاف سريع (صلاحية "تغيير الحالة"). من غيرها بيظهر شارة بس.' },
                    { name: 'تاريخ الإنشاء', desc: 'وتحته آخر تعديل، وفي السلة تاريخ الحذف.' },
                    { name: '🌍 الأسعار', desc: 'سعر العرض لكل دولة مفعّلة بعملتها. من غير صلاحية التعديل بتفتح للقراءة بس.' },
                    { name: '✏️ / 🗑️', desc: 'تعديل، أو نقل للسلة. في السلة: ↩️ ترجيع و🗑️ حذف نهائي.' },
                ],
            },
            {
                title: 'فورم العرض',
                items: [
                    { name: 'تاب لكل لغة', desc: 'زي فورم الدول: الاسم إجباري في كل لغة (2 لـ 150 حرف)، والوصف اختياري (لحد 2000 حرف). التاب بيحمر لو فيه غلط.' },
                    { name: 'الكود', desc: 'إجباري ومميز: حروف إنجليزي وأرقام و - و _ بس (لحد 60).' },
                    { name: 'عدد البناء/التعديل المشمول', desc: 'من 1 لـ 500. كل بناء أو تعديل بالذكاء الاصطناعي على الموقع بيخصم واحد.' },
                    { name: 'الترتيب', desc: 'الأصغر بيظهر الأول في التطبيق. فاضي = 0.' },
                    { name: 'الحالة', desc: 'مقفولة = مش ظاهر في التطبيق ومحدش يقدر يشتريه.' },
                ],
            },
            {
                title: 'نافذة الأسعار',
                items: [
                    { name: 'صف لكل دولة مفعّلة', desc: 'اكتب السعر بعملة الدولة. فاضي = مش بيتباع هناك.' },
                    { name: 'حفظ', desc: 'بيستبدل القايمة كلها: أي دولة سبتها فاضية بتبطل تبيع العرض.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'إضافة عرض موقع وبيعه في مصر',
                steps: [
                    'دوس "إضافة عرض".',
                    'في كل تاب لغة اكتب الاسم والوصف.',
                    'اكتب الكود وعدد البناء/التعديل، وسيب الحالة مفتوحة، ودوس حفظ.',
                    'دوس 🌍 "الأسعار" على العرض، واكتب سعر مصر بالجنيه، ودوس حفظ.',
                ],
            },
            {
                title: 'ترجمة عرض موجود',
                steps: ['دوس على اسم العرض.', 'انقل بين تابات اللغات وعدّل الاسم والوصف.', 'دوس حفظ.'],
            },
            {
                title: 'إيقاف عرض أو ترجيعه من السلة',
                steps: [
                    'للإيقاف المؤقت: اقفل زرار الحالة.',
                    'لو نقلته للسلة: دوس "المحذوفات" ثم ↩️ ترجيع.',
                ],
            },
        ],
        affects: [
            { text: 'شاشة "مواقعي" في تطبيق Android وiOS لليوزر ومقدم الخدمة: قايمة العروض اللي تتشري (الاسم والوصف والسعر بلغة وعملة العميل).' },
            { text: 'الشراء من المحفظة: بيتسجل شراء بعدد البناء/التعديل وقت الشراء.' },
            { route: 'admin.ai-site-edit-packs.index', text: 'باقات التعديل: نفس الشاشة ونفس الجدول بس للنوع "باقة تعديل".' },
            { route: 'admin.ai-sites.index', text: 'المواقع: المواقع اللي اتعملت بعرض من هنا.' },
        ],
        warnings: [
            { level: 'warning', text: 'الحذف بينقل العرض للسلة: بيبطل يتباع على طول، واللي اشتروه قبل كده يفضل شغّال. تقدر ترجّعه في أي وقت.' },
            { level: 'warning', text: 'الحذف النهائي مش هيتم لو العرض اتباع قبل كده (عليه عمليات شراء)، والشاشة هتقولك السبب.' },
            { level: 'warning', text: 'عرض من غير سعر في أي دولة مش هيظهر لأي عميل حتى لو مفعّل.' },
            { level: 'info', text: 'تغيير عدد البناء/التعديل بيسري على المشتريات الجديدة بس؛ كل شراء بيحفظ العدد وقت الشراء.' },
            { level: 'info', text: 'زرار "تحميل السورس" ليه شاشته لوحده (تحميل سورس الموقع)، ومش بيظهر هنا.' },
            { level: 'info', text: 'الاسم إجباري في كل لغة متفعّلة. لو ضفت لغة جديدة للمنصة، افتح كل عرض واكتب له النص.' },
        ],
    },

    en: {
        summary: 'Create "website offers": a customer whose plan has no site builder can buy one AI-built website from the wallet. Each offer has a name and description per language, a number of builds/edits, and a price per country.',
        audience: 'Pricing or product owner. Needs the "Website offers" permissions: view, create, update, delete, change status, multiple delete.',
        purpose: [
            'Sell a single website (e.g. a portfolio) to a customer without a site-builder plan.',
            'An offer is shown in the app only in countries that have a price for it. No price = not shown there.',
            'Name and description are written per language; customers see them in their app language.',
        ],
        sections: [
            {
                title: 'Toolbar',
                items: [
                    { name: 'Search', desc: 'By name or description (any language) or code.' },
                    { name: 'All / Active / Inactive', desc: 'Quick filters with counts — counting website offers only, not edit packs.' },
                    { name: 'Trash', desc: 'Shown when offers are in the trash: restore or delete for good.' },
                    { name: 'Delete selected', desc: 'Move several checked offers to the trash at once ("multiple delete").' },
                    { name: 'Add offer', desc: 'Opens a new offer form ("create").' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Name', desc: 'In the panel language with the offer id; click to edit.' },
                    { name: 'Code', desc: 'Stable identifier, not shown to customers.' },
                    { name: 'Included builds/edits', desc: 'What the customer gets with the purchase.' },
                    { name: 'Countries', desc: 'How many countries have a price. "No price set" = nobody sees it.' },
                    { name: 'Status', desc: 'Quick on/off toggle ("change status"); a badge without it.' },
                    { name: 'Created at', desc: 'With last update, and the deletion date in the trash.' },
                    { name: '🌍 Prices', desc: 'Price per active country in its currency; read-only without "update".' },
                    { name: '✏️ / 🗑️', desc: 'Edit, or move to the trash. In the trash: ↩️ restore and 🗑️ delete for good.' },
                ],
            },
            {
                title: 'Offer form',
                items: [
                    { name: 'One tab per language', desc: 'Like the countries form: name required in every language (2–150 chars), description optional (up to 2000). A tab turns red on errors.' },
                    { name: 'Code', desc: 'Required and unique: letters, numbers, - and _ only (up to 60).' },
                    { name: 'Included builds/edits', desc: '1–500. Every AI build or edit of the site uses one.' },
                    { name: 'Order', desc: 'Smaller shows first in the app. Empty = 0.' },
                    { name: 'Status', desc: 'Off = hidden from the app and cannot be bought.' },
                ],
            },
            {
                title: 'Prices window',
                items: [
                    { name: 'One row per active country', desc: 'Enter the price in that country’s currency. Empty = not sold there.' },
                    { name: 'Save', desc: 'Replaces the whole list: any country left empty stops selling the offer.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Add a website offer and sell it in Egypt',
                steps: ['Click "Add offer".', 'Write the name and description in each language tab.', 'Enter the code and builds/edits, keep status on, save.', 'Click 🌍 "Prices", enter the EGP price for Egypt, save.'],
            },
            {
                title: 'Translate an existing offer',
                steps: ['Click the offer name.', 'Switch language tabs and edit name and description.', 'Save.'],
            },
            {
                title: 'Pause an offer, or restore it from the trash',
                steps: ['To pause: switch its status off.', 'If trashed: open "Trash" and click ↩️ restore.'],
            },
        ],
        affects: [
            { text: '"My sites" in the Android and iOS apps for users and providers: the offers list (name, description, price in the customer’s language and currency).' },
            { text: 'Wallet purchase: a purchase row is saved with the builds/edits count at that moment.' },
            { route: 'admin.ai-site-edit-packs.index', text: 'Edit packs: the same screen and table for the "edit pack" kind.' },
            { route: 'admin.ai-sites.index', text: 'Sites: websites built with an offer from here.' },
        ],
        warnings: [
            { level: 'warning', text: 'Delete moves the offer to the trash: it stops being sold right away; what customers already bought keeps working. Restore any time.' },
            { level: 'warning', text: 'Deleting for good is refused once the offer was sold (it has purchases); the screen says why.' },
            { level: 'warning', text: 'An offer with no country price is not shown to any customer, even when active.' },
            { level: 'info', text: 'Changing builds/edits only affects new purchases; each purchase keeps its own count.' },
            { level: 'info', text: 'The "source download" switch has its own screen (Site source download) and is not listed here.' },
            { level: 'info', text: 'The name is required in every active language. After adding a platform language, open each offer and write its text.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-site-offers/index.vue (countries-style list; route meta.kind picks site / edit_pack)',
            'resources/js/modules/admin/themes/theme-1/views/ai-site-offers/ModalOffer.vue (useCatalogTranslationFields)',
            'resources/js/modules/admin/themes/theme-1/views/ai-site-offers/ModalOfferPrices.vue (canEdit = ai-site-offers.update; also used by ai-site-download)',
            'resources/js/composables/useAiSiteOffers.js, stores/aiSiteOffers.js',
            'Modules/AI/app/Http/Controllers/AiSiteOfferController.php (CatalogController, group ai-site-offers; prices = view, syncPrices = update)',
            'Modules/AI/app/Repositories/AiSiteOfferRepository.php (TranslatableRepository; ?kind=; source_download excluded; trash rules)',
            'Modules/AI/app/Models/AiSiteOffer.php + AiSiteOfferTranslation.php + Concerns/HasFlatTranslatedText.php',
            'Modules/AI/app/Http/Controllers/AiSiteProjectController.php (offers), Services/Sites/AiSiteProjectService.php (purchase)',
        ],
        api: [
            'GET|POST       /api/admin/v1/ai-site-offers            (?kind=site|edit_pack, ?status_counts=1, search, trashed)',
            'GET|PUT|DELETE /api/admin/v1/ai-site-offers/{offer}',
            'PATCH          /api/admin/v1/ai-site-offers/{offer}/status',
            'POST           /api/admin/v1/ai-site-offers/delete-multiple',
            'POST           /api/admin/v1/ai-site-offers/{offer}/restore',
            'DELETE         /api/admin/v1/ai-site-offers/{offer}/force',
            'GET|PUT        /api/admin/v1/ai-site-offers/{offer}/prices',
            'Apps: GET /api/{user|provider}/v1/ai-sites/offers — same keys, text in X-Locale, is_active kept',
        ],
        tables: ['ai_site_offers (kind, status, deleted_at)', 'ai_site_offer_translations (name, description)', 'ai_site_offer_prices', 'ai_site_purchases.offer_id (restrict)'],
        permissions: [
            'ai-site-offers.view (screen, list, prices read-only)',
            'ai-site-offers.create · ai-site-offers.update (edit, prices, restore)',
            'ai-site-offers.delete (trash, force delete) · ai-site-offers.change-status · ai-site-offers.multiple-delete',
        ],
        decisions: [
            {
                ar: 'الحذف بقى "سلة محذوفات" بدل ما العرض المتباع كان بيتقفل بس: النتيجة واحدة للعميل (مش بيتباع)، والمشتريات بتفضل شايفة العرض (withTrashed).',
                en: 'Delete is now the trash instead of "switch off if sold": the same for customers (not sold), and purchases still see the offer (withTrashed).',
            },
        ],
    },
};
