/**
 * Guide: edit packs (admin.ai-site-edit-packs.index) - more AI edits for an existing site, bought from the wallet.
 * Same view as admin.ai-site-offers.index with route meta.kind = 'edit_pack'.
 * Source of truth checked: views/ai-site-offers/*.vue, composables/useAiSiteOffers.js,
 * Modules/AI/app/Models/AiSiteOffer.php (+ Concerns/HasFlatTranslatedText.php), Repositories/AiSiteOfferRepository.php,
 * Http/Controllers/AiSiteOfferController.php, Http/Requests/AiSiteOfferRequest.php,
 * Http/Controllers/AiSiteProjectController.php (editPacks / packsPayload), Services/Sites/AiSiteProjectService.php (buyEditPack).
 */
export default {
    route: 'admin.ai-site-edit-packs.index',
    updated: '2026-11-10',

    ar: {
        summary: 'هنا بتعمل "باقات التعديل": لما العميل يخلّص التعديلات المسموحة لموقعه، يقدر يشتري باقة تعديلات زيادة لنفس الموقع من محفظته. لكل باقة اسم ووصف بكل لغة، وعدد تعديلات، وسعر لكل دولة.',
        audience: 'مسؤول التسعير أو المنتج. نفس صلاحيات "عروض المواقع": عرض، إضافة، تعديل، حذف، تغيير الحالة، حذف متعدد.',
        purpose: [
            'بيع تعديلات زيادة بالذكاء الاصطناعي لموقع موجود بعد ما رصيده يخلص.',
            'الباقة بتظهر للعميل في الدولة اللي ليها سعر بس.',
            'الاسم والوصف مكتوبين بكل لغة، والعميل بيشوفهم بلغة التطبيق بتاعه.',
        ],
        sections: [
            {
                title: 'شريط الأدوات',
                items: [
                    { name: 'البحث', desc: 'بالاسم أو الوصف (في أي لغة) أو بالكود.' },
                    { name: 'الكل / مفعّلة / متوقفة', desc: 'فلترة سريعة وجنب كل واحدة العدد. العدد بتاع باقات التعديل بس.' },
                    { name: 'المحذوفات', desc: 'بتظهر لو فيه باقات في السلة: ترجيع أو حذف نهائي.' },
                    { name: 'حذف المحدد', desc: 'نقل كذا باقة للسلة مرة واحدة.' },
                    { name: 'إضافة باقة', desc: 'بيفتح فورم باقة جديدة.' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'الاسم', desc: 'بلغة لوحة التحكم وتحته رقم الباقة. دوس عليه يفتح التعديل.' },
                    { name: 'الكود', desc: 'معرّف ثابت، العميل مش بيشوفه.' },
                    { name: 'عدد البناء/التعديل المشمول', desc: 'عدد التعديلات اللي العميل بياخدها للموقع.' },
                    { name: 'الدول', desc: 'عدد الدول اللي ليها سعر. "مفيش سعر" = الباقة مش هتظهر.' },
                    { name: 'الحالة', desc: 'تشغيل وإيقاف سريع (صلاحية "تغيير الحالة").' },
                    { name: '🌍 الأسعار', desc: 'سعر الباقة لكل دولة بعملتها. من غير صلاحية التعديل بتفتح للقراءة بس.' },
                    { name: '✏️ / 🗑️', desc: 'تعديل، أو نقل للسلة. في السلة: ↩️ ترجيع و🗑️ حذف نهائي.' },
                ],
            },
            {
                title: 'فورم الباقة',
                items: [
                    { name: 'تاب لكل لغة', desc: 'الاسم إجباري في كل لغة (2 لـ 150 حرف)، والوصف اختياري (لحد 2000).' },
                    { name: 'الكود', desc: 'إجباري ومميز: حروف إنجليزي وأرقام و - و _ بس.' },
                    { name: 'عدد التعديلات', desc: 'من 1 لـ 500 لموقع واحد.' },
                    { name: 'الترتيب والحالة', desc: 'الأصغر بيظهر الأول. مقفولة = مش ظاهرة ومحدش يقدر يشتريها.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'إضافة باقة 10 تعديلات',
                steps: [
                    'دوس "إضافة باقة".',
                    'في كل تاب لغة اكتب الاسم (مثلًا "باقة 10 تعديلات") والوصف.',
                    'اكتب الكود (مثلًا edit_pack_10) وعدد التعديلات 10، ودوس حفظ.',
                    'دوس 🌍 "الأسعار" واكتب السعر لكل دولة، ودوس حفظ.',
                ],
            },
            {
                title: 'إيقاف باقة أو ترجيعها',
                steps: ['اقفل زرار الحالة للإيقاف المؤقت.', 'لو في السلة: "المحذوفات" ثم ↩️ ترجيع.'],
            },
        ],
        affects: [
            { text: 'تفاصيل الموقع في تطبيق Android وiOS (كارت "باقات التعديل"): الباقات المتاحة بسعر وعملة دولة العميل.' },
            { text: 'التعديل بالذكاء الاصطناعي: بعد ما تعديلات الموقع نفسه تخلص، بيستخدم الباقات اللي اتشرت.' },
            { route: 'admin.ai-site-offers.index', text: 'عروض المواقع: نفس الشاشة للنوع "موقع كامل".' },
        ],
        warnings: [
            { level: 'warning', text: 'الحذف بينقل الباقة للسلة: بتبطل تتباع، والتعديلات اللي العملاء اشتروها قبل كده تفضل شغّالة.' },
            { level: 'warning', text: 'الحذف النهائي مش هيتم لو الباقة اتباعت قبل كده.' },
            { level: 'info', text: 'تغيير عدد التعديلات بيسري على المشتريات الجديدة بس.' },
            { level: 'info', text: 'الاسم إجباري في كل لغة متفعّلة.' },
        ],
    },

    en: {
        summary: 'Create "edit packs": when a customer has used the edits included with a site, they can buy more edits for that same site from the wallet. Each pack has a name and description per language, a number of edits, and a price per country.',
        audience: 'Pricing or product owner. Same "Website offers" permissions: view, create, update, delete, change status, multiple delete.',
        purpose: [
            'Sell extra AI edits for an existing site once its own allowance is used up.',
            'A pack is shown only in countries that have a price for it.',
            'Name and description per language; customers see them in their app language.',
        ],
        sections: [
            {
                title: 'Toolbar',
                items: [
                    { name: 'Search', desc: 'By name or description (any language) or code.' },
                    { name: 'All / Active / Inactive', desc: 'Quick filters with counts — counting edit packs only.' },
                    { name: 'Trash', desc: 'Shown when packs are in the trash: restore or delete for good.' },
                    { name: 'Delete selected', desc: 'Move several packs to the trash at once.' },
                    { name: 'Add pack', desc: 'Opens a new pack form.' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Name', desc: 'In the panel language with the pack id; click to edit.' },
                    { name: 'Code', desc: 'Stable identifier, not shown to customers.' },
                    { name: 'Included builds/edits', desc: 'Edits the customer gets for the site.' },
                    { name: 'Countries', desc: 'Countries with a price. "No price set" = not shown.' },
                    { name: 'Status', desc: 'Quick on/off toggle ("change status").' },
                    { name: '🌍 Prices', desc: 'Price per country in its currency; read-only without "update".' },
                    { name: '✏️ / 🗑️', desc: 'Edit, or move to the trash. In the trash: ↩️ restore and 🗑️ delete for good.' },
                ],
            },
            {
                title: 'Pack form',
                items: [
                    { name: 'One tab per language', desc: 'Name required in every language (2–150), description optional (up to 2000).' },
                    { name: 'Code', desc: 'Required and unique: letters, numbers, - and _ only.' },
                    { name: 'Edits', desc: '1–500 for one site.' },
                    { name: 'Order & status', desc: 'Smaller shows first. Off = hidden and cannot be bought.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Add a 10-edits pack',
                steps: ['Click "Add pack".', 'Write the name (e.g. "10 edits pack") and description in each language tab.', 'Enter the code (e.g. edit_pack_10) and 10 edits, save.', 'Click 🌍 "Prices", enter a price per country, save.'],
            },
            {
                title: 'Pause or restore a pack',
                steps: ['Switch its status off to pause.', 'If trashed: "Trash" then ↩️ restore.'],
            },
        ],
        affects: [
            { text: 'Site details in the Android and iOS apps ("Edit packs" card): available packs in the customer’s country price and currency.' },
            { text: 'AI edits: once the site’s own edits are used, the bought packs are used.' },
            { route: 'admin.ai-site-offers.index', text: 'Website offers: the same screen for the "whole site" kind.' },
        ],
        warnings: [
            { level: 'warning', text: 'Delete moves the pack to the trash: no longer sold; edits customers already bought keep working.' },
            { level: 'warning', text: 'Deleting for good is refused once the pack was sold.' },
            { level: 'info', text: 'Changing the edits count only affects new purchases.' },
            { level: 'info', text: 'The name is required in every active language.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-site-offers/index.vue (route meta.kind = edit_pack)',
            'resources/js/modules/admin/themes/theme-1/views/ai-site-offers/ModalOffer.vue, ModalOfferPrices.vue',
            'resources/js/composables/useAiSiteOffers.js, stores/aiSiteOffers.js',
            'Modules/AI/app/Http/Controllers/AiSiteOfferController.php (group ai-site-offers)',
            'Modules/AI/app/Repositories/AiSiteOfferRepository.php (?kind=edit_pack)',
            'Modules/AI/app/Models/AiSiteOffer.php + AiSiteOfferTranslation.php',
            'Modules/AI/app/Http/Controllers/AiSiteProjectController.php (editPacks), Services/Sites/AiSiteProjectService.php (buyEditPack)',
        ],
        api: [
            'GET|POST       /api/admin/v1/ai-site-offers?kind=edit_pack',
            'GET|PUT|DELETE /api/admin/v1/ai-site-offers/{offer} · PATCH …/status · POST …/restore · DELETE …/force',
            'POST           /api/admin/v1/ai-site-offers/delete-multiple',
            'GET|PUT        /api/admin/v1/ai-site-offers/{offer}/prices',
            'Apps: GET /api/{user|provider}/v1/ai-sites/{project}/edit-packs — same keys (packs[]), text in X-Locale',
        ],
        tables: ['ai_site_offers (kind = edit_pack, status, deleted_at)', 'ai_site_offer_translations', 'ai_site_offer_prices', 'ai_site_purchases (project_id)'],
        permissions: [
            'ai-site-offers.view · create · update · delete · change-status · multiple-delete (shared with website offers)',
        ],
        decisions: [],
    },
};
