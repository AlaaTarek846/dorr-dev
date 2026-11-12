/**
 * Guide: site hosting plans (admin.ai-site-hosting-plans.index).
 * Source of truth checked: views/ai-site-hosting-plans/*.vue, composables/useAiSiteHostingPlans.js,
 * Modules/AI/app/Models/AiSiteHostingPlan.php (+ Concerns/HasFlatTranslatedText.php), Repositories/AiSiteHostingPlanRepository.php,
 * Http/Controllers/AiSiteHostingPlanController.php, Http/Requests/AiSiteHostingPlanRequest.php,
 * Http/Resources/AiSiteHostingPlanResource.php, Http/Controllers/AiSiteHostingController.php (plans, subscribe),
 * Services/Sites/AiSiteHostingService.php (subscribe, renew, processDue), Modules/AI/config/config.php (sites.hosting).
 */
export default {
    route: 'admin.ai-site-hosting-plans.index',
    updated: '2026-11-10',

    ar: {
        summary: 'هنا بتعمل باقات الاستضافة: العميل بينشر موقعه على عنوان خاص بيه (شهري أو سنوي) وبيدفع من محفظته، والدفع بيتجدد لوحده. لكل باقة اسم ووصف بكل لغة، ومدة، وسعر لكل دولة.',
        audience: 'مسؤول التسعير أو المنتج. محتاج صلاحيات "باقات الاستضافة": عرض، إضافة، تعديل، حذف، تغيير الحالة، حذف متعدد.',
        purpose: [
            'تحديد باقات الاستضافة (شهري / سنوي) اللي العميل يختار منها لما ينشر موقعه.',
            'الباقة بتظهر للعميل في الدولة اللي ليها سعر بس.',
            'الاسم والوصف مكتوبين بكل لغة، والعميل بيشوفهم بلغة التطبيق بتاعه.',
        ],
        sections: [
            {
                title: 'شريط الأدوات',
                items: [
                    { name: 'البحث', desc: 'بالاسم أو الوصف (في أي لغة) أو بالكود.' },
                    { name: 'الكل / مفعّلة / متوقفة', desc: 'فلترة سريعة وجنب كل واحدة العدد.' },
                    { name: 'المحذوفات', desc: 'بتظهر لو فيه باقات في السلة: ترجيع أو حذف نهائي.' },
                    { name: 'حذف المحدد', desc: 'نقل كذا باقة للسلة مرة واحدة (صلاحية "حذف متعدد").' },
                    { name: 'إضافة باقة', desc: 'بيفتح فورم باقة جديدة.' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'الاسم', desc: 'بلغة لوحة التحكم وتحته رقم الباقة. دوس عليه يفتح التعديل.' },
                    { name: 'الكود', desc: 'معرّف ثابت، العميل مش بيشوفه.' },
                    { name: 'فترة الاشتراك', desc: 'شهري أو سنوي: الدفعة الواحدة بتكفي قد إيه.' },
                    { name: 'الدول', desc: 'عدد الدول اللي ليها سعر. "مفيش سعر" = الباقة مش هتظهر.' },
                    { name: 'الحالة', desc: 'تشغيل وإيقاف سريع (صلاحية "تغيير الحالة").' },
                    { name: '🌍 الأسعار', desc: 'سعر الباقة لكل دولة مفعّلة بعملتها. من غير صلاحية التعديل بتفتح للقراءة بس.' },
                    { name: '✏️ / 🗑️', desc: 'تعديل، أو نقل للسلة. في السلة: ↩️ ترجيع و🗑️ حذف نهائي.' },
                ],
            },
            {
                title: 'فورم الباقة',
                items: [
                    { name: 'تاب لكل لغة', desc: 'الاسم إجباري في كل لغة (2 لـ 150 حرف)، والوصف اختياري (لحد 2000).' },
                    { name: 'الكود', desc: 'إجباري ومميز: حروف إنجليزي وأرقام و - و _ بس.' },
                    { name: 'فترة الاشتراك', desc: 'شهري = شهر من تاريخ البداية، سنوي = سنة.' },
                    { name: 'الترتيب', desc: 'الأصغر بيظهر الأول في التطبيق.' },
                    { name: 'الحالة', desc: 'مقفولة = مش معروضة للمواقع الجديدة ومش بتتجدد.' },
                ],
            },
            {
                title: 'نافذة الأسعار',
                items: [
                    { name: 'صف لكل دولة مفعّلة', desc: 'السعر بعملة الدولة. فاضي = مش بتتباع هناك.' },
                    { name: 'حفظ', desc: 'بيستبدل القايمة كلها. التجديد بيتحسب بسعر دولة الاشتراك وقت التجديد.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'إضافة استضافة شهرية في السعودية',
                steps: [
                    'دوس "إضافة باقة".',
                    'في كل تاب لغة اكتب الاسم (مثلًا "استضافة شهرية") والوصف.',
                    'اكتب الكود واختار "شهري"، ودوس حفظ.',
                    'دوس 🌍 "الأسعار" واكتب سعر السعودية بالريال، ودوس حفظ.',
                ],
            },
            {
                title: 'وقف بيع باقة',
                steps: [
                    'اقفل زرار الحالة: مش هتظهر لموقع جديد ومش هتتجدد.',
                    'أو انقلها للسلة. ترجّعها من "المحذوفات" ↩️ في أي وقت.',
                ],
            },
        ],
        affects: [
            { text: 'شاشة نشر الموقع (الاستضافة) في تطبيق Android وiOS لليوزر ومقدم الخدمة: الباقات المتاحة بسعر وعملة دولة العميل.' },
            { text: 'التجديد التلقائي من المحفظة لكل موقع مستضاف على الباقة.' },
            { route: 'admin.ai-site-hostings.index', text: 'المواقع المستضافة: كل استضافة مربوطة بباقة من هنا.' },
        ],
        warnings: [
            { level: 'danger', text: 'لو قفلت الباقة أو نقلتها للسلة، المواقع اللي عليها مش هتتجدد: بتكمل لحد آخر المدة المدفوعة، وبعدين تدخل فترة السماح (3 أيام افتراضيًا) وتتوقف. العميل يقدر يشترك في باقة تانية.' },
            { level: 'warning', text: 'الحذف النهائي مش هيتم لو فيه أي موقع مستضاف على الباقة.' },
            { level: 'warning', text: 'لو شلت سعر دولة، المواقع اللي في الدولة دي بتتجدد بآخر سعر دفعوه.' },
            { level: 'info', text: 'تغيير الفترة (شهري/سنوي) بيسري من التجديد الجاي.' },
            { level: 'info', text: 'الاسم إجباري في كل لغة متفعّلة. لو ضفت لغة جديدة، افتح كل باقة واكتب لها النص.' },
        ],
    },

    en: {
        summary: 'Create hosting plans: a customer publishes their site on its own address (monthly or yearly), pays from the wallet, and it renews by itself. Each plan has a name and description per language, a period, and a price per country.',
        audience: 'Pricing or product owner. Needs the "Hosting plans" permissions: view, create, update, delete, change status, multiple delete.',
        purpose: [
            'Define the hosting plans (monthly / yearly) a customer picks when publishing a site.',
            'A plan is shown only in countries that have a price for it.',
            'Name and description per language; customers see them in their app language.',
        ],
        sections: [
            {
                title: 'Toolbar',
                items: [
                    { name: 'Search', desc: 'By name or description (any language) or code.' },
                    { name: 'All / Active / Inactive', desc: 'Quick filters with counts.' },
                    { name: 'Trash', desc: 'Shown when plans are in the trash: restore or delete for good.' },
                    { name: 'Delete selected', desc: 'Move several plans to the trash at once ("multiple delete").' },
                    { name: 'Add plan', desc: 'Opens a new plan form.' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Name', desc: 'In the panel language with the plan id; click to edit.' },
                    { name: 'Code', desc: 'Stable identifier, not shown to customers.' },
                    { name: 'Billing period', desc: 'Monthly or yearly: how long one payment lasts.' },
                    { name: 'Countries', desc: 'Countries with a price. "No price set" = not shown.' },
                    { name: 'Status', desc: 'Quick on/off toggle ("change status").' },
                    { name: '🌍 Prices', desc: 'Price per active country in its currency; read-only without "update".' },
                    { name: '✏️ / 🗑️', desc: 'Edit, or move to the trash. In the trash: ↩️ restore and 🗑️ delete for good.' },
                ],
            },
            {
                title: 'Plan form',
                items: [
                    { name: 'One tab per language', desc: 'Name required in every language (2–150), description optional (up to 2000).' },
                    { name: 'Code', desc: 'Required and unique: letters, numbers, - and _ only.' },
                    { name: 'Billing period', desc: 'Monthly = one month from the start, yearly = one year.' },
                    { name: 'Order', desc: 'Smaller shows first in the app.' },
                    { name: 'Status', desc: 'Off = not offered to new sites and not renewed.' },
                ],
            },
            {
                title: 'Prices window',
                items: [
                    { name: 'One row per active country', desc: 'Price in that country’s currency. Empty = not sold there.' },
                    { name: 'Save', desc: 'Replaces the whole list. A renewal uses the subscription country’s price at renewal time.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Add monthly hosting in Saudi Arabia',
                steps: ['Click "Add plan".', 'Write the name (e.g. "Monthly hosting") and description in each language tab.', 'Enter the code, pick "Monthly", save.', 'Click 🌍 "Prices", enter the SAR price, save.'],
            },
            {
                title: 'Stop selling a plan',
                steps: ['Switch its status off: not offered to new sites and not renewed.', 'Or move it to the trash; restore it from "Trash" ↩️ any time.'],
            },
        ],
        affects: [
            { text: 'Site publishing (hosting) in the Android and iOS apps for users and providers: available plans in the customer’s country price and currency.' },
            { text: 'Automatic wallet renewal of every site hosted on the plan.' },
            { route: 'admin.ai-site-hostings.index', text: 'Hosted websites: every hosting points at a plan here.' },
        ],
        warnings: [
            { level: 'danger', text: 'Switching a plan off or trashing it stops renewals for the sites on it: they run until the paid period ends, then enter the grace period (3 days by default) and are suspended. The customer can subscribe to another plan.' },
            { level: 'warning', text: 'Deleting for good is refused while any site is hosted on the plan.' },
            { level: 'warning', text: 'Removing a country price makes sites in that country renew at the last price they paid.' },
            { level: 'info', text: 'Changing the period (monthly/yearly) applies from the next renewal.' },
            { level: 'info', text: 'The name is required in every active language. After adding a language, open each plan and write its text.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-site-hosting-plans/index.vue (countries-style list)',
            'resources/js/modules/admin/themes/theme-1/views/ai-site-hosting-plans/ModalHostingPlan.vue (useCatalogTranslationFields)',
            'resources/js/modules/admin/themes/theme-1/views/ai-site-hosting-plans/ModalHostingPlanPrices.vue (canEdit = update)',
            'resources/js/composables/useAiSiteHostingPlans.js, stores/aiSiteHostingPlans.js',
            'Modules/AI/app/Http/Controllers/AiSiteHostingPlanController.php (CatalogController, group ai-site-hosting-plans; prices = view, syncPrices = update)',
            'Modules/AI/app/Repositories/AiSiteHostingPlanRepository.php (TranslatableRepository; trash rules)',
            'Modules/AI/app/Models/AiSiteHostingPlan.php + AiSiteHostingPlanTranslation.php + Concerns/HasFlatTranslatedText.php',
            'Modules/AI/app/Services/Sites/AiSiteHostingService.php (subscribe / renew check isAvailable())',
        ],
        api: [
            'GET|POST       /api/admin/v1/ai-site-hosting-plans            (?status_counts=1, search, trashed)',
            'GET|PUT|DELETE /api/admin/v1/ai-site-hosting-plans/{plan}',
            'PATCH          /api/admin/v1/ai-site-hosting-plans/{plan}/status',
            'POST           /api/admin/v1/ai-site-hosting-plans/delete-multiple',
            'POST           /api/admin/v1/ai-site-hosting-plans/{plan}/restore',
            'DELETE         /api/admin/v1/ai-site-hosting-plans/{plan}/force',
            'GET|PUT        /api/admin/v1/ai-site-hosting-plans/{plan}/prices',
            'Apps: GET /api/{user|provider}/v1/ai-sites/hosting/plans — same keys, text in X-Locale, is_active kept',
        ],
        tables: ['ai_site_hosting_plans (period, status, deleted_at)', 'ai_site_hosting_plan_translations (name, description)', 'ai_site_hosting_plan_prices', 'ai_site_hostings.plan_id (restrict)'],
        permissions: [
            'ai-site-hosting-plans.view (screen, list, prices read-only)',
            'ai-site-hosting-plans.create · ai-site-hosting-plans.update (edit, prices, restore)',
            'ai-site-hosting-plans.delete (trash, force delete) · ai-site-hosting-plans.change-status · ai-site-hosting-plans.multiple-delete',
        ],
        decisions: [
            {
                ar: 'الباقة المقفولة أو اللي في السلة مش بتتباع ولا بتتجدد (زي AiPlan::isAvailable()). قبل كده الحذف كان بيقفلها بس لو عليها مواقع، والتجديد كان بيكمل.',
                en: 'A switched-off or trashed plan is neither sold nor renewed (like AiPlan::isAvailable()). Before, delete only switched it off when sites used it, and renewals went on.',
            },
        ],
    },
};
