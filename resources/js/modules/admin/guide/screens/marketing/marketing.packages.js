/**
 * Guide: AI Marketing Director packages (admin.marketing.packages).
 * Source of truth checked: views/marketing/packages/index.vue, Modules/Marketing/routes/admin.php (packages),
 * Modules/Marketing/app/Http/Controllers/Admin/PackageController.php (index, show, store, update, destroy, validated),
 * Modules/Marketing/app/Models/MarketingPackage.php (LIMITS), locales adminMarketing.packages.
 */
export default {
    route: 'admin.marketing.packages',
    updated: '2026-11-11',

    ar: {
        summary: 'الباقات اللي العميل بيشترك فيها: مدتها بالأيام، حدود كل فترة، وسعرها في كل دولة.',
        audience: 'مدير الخدمة. العرض محتاج "باقات التسويق: عرض"، والإضافة والتعديل والحذف كل واحدة بصلاحيتها.',
        purpose: [
            'تعمل باقة جديدة أو تعدّل حدود باقة.',
            'تحط سعر الباقة في كل دولة أو توقفه.',
            'توقف بيع باقة من غير ما تمسحها.',
        ],
        sections: [
            {
                title: 'الجدول',
                items: [
                    { name: 'الباقة', desc: 'الاسم بلغة اللوحة، والكود تحتيه.' },
                    { name: 'المدة', desc: 'عدد أيام الفترة الواحدة.' },
                    { name: 'الحدود', desc: 'المشاريع النشطة في نفس الوقت، مرات البحث، مرات توليد الخطة، والأعضاء في كل مشروع (غير المالك). الفاضي = بلا حد.' },
                    { name: 'الأسعار', desc: 'كود الدولة والسعر. السعر المشطوب موقوف.' },
                    { name: 'المشتركين', desc: 'الاشتراكات السارية دلوقتي على الباقة.' },
                    { name: 'الحالة', desc: 'الباقة المقفولة مش بتتباع، والاشتراكات اللي اتباعت بتكمل.' },
                ],
            },
            {
                title: 'نافذة الباقة',
                items: [
                    { name: 'الكود', desc: 'حروف وأرقام و - و _ بس، ومينفعش يتكرر.' },
                    { name: 'الاسم والوصف', desc: 'عربي وإنجليزي (الاسم مطلوب في الاتنين).' },
                    { name: 'الأسعار', desc: 'بالوحدة الكبيرة للعملة (مثلاً 99.50). بتتخزن مضروبة في 100. شيل سطر الدولة عشان تمسح سعرها.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'إضافة باقة',
                steps: [
                    'دوس "إضافة باقة".',
                    'اكتب الكود والمدة والاسم بالعربي والإنجليزي.',
                    'حط الحدود (أو سيبها فاضية لو بلا حد).',
                    'أضف سعر لكل دولة واحفظ.',
                ],
            },
        ],
        affects: [
            { route: 'admin.marketing.countries', text: 'السعر مش بيبان في دولة الخدمة فيها مقفولة.' },
            { route: 'admin.marketing.operations', text: 'الاشتراكات اللي اتباعت بتظهر في التشغيل.' },
        ],
        warnings: [
            { level: 'warning', text: 'الحذف لباقة عليها اشتراكات بيقفلها ويخفيها بس (حذف ناعم)، والمشتركين بيكملوا فترتهم.' },
            { level: 'info', text: 'تغيير الحدود بيأثر على الاشتراكات السارية كمان، لأن الحد بيتقرا من الباقة وقت الاستخدام.' },
        ],
    },

    en: {
        summary: 'The packages customers subscribe to: the period in days, the limits per period and the price in each country.',
        audience: 'The service manager. Viewing needs "Marketing packages: view"; create, update and delete each need their own permission.',
        purpose: [
            'Create a package or change its limits.',
            'Set or pause the package price in each country.',
            'Stop selling a package without removing it.',
        ],
        sections: [
            {
                title: 'Table',
                items: [
                    { name: 'Package', desc: 'The name in the panel language, the code under it.' },
                    { name: 'Period', desc: 'Days in one period.' },
                    { name: 'Limits', desc: 'Active projects at once, research runs, plan generations and members per project (owner aside). Empty = no limit.' },
                    { name: 'Prices', desc: 'Country code and price. A struck-through price is paused.' },
                    { name: 'Subscribers', desc: 'Subscriptions running on the package now.' },
                    { name: 'Status', desc: 'An inactive package is not sold; subscriptions already sold carry on.' },
                ],
            },
            {
                title: 'Package window',
                items: [
                    { name: 'Code', desc: 'Letters, digits, - and _ only; unique.' },
                    { name: 'Name and description', desc: 'Arabic and English (the name is required in both).' },
                    { name: 'Prices', desc: 'In the currency\'s major unit (e.g. 99.50), stored ×100. Remove a country\'s line to remove its price.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Add a package',
                steps: [
                    'Click "Add package".',
                    'Fill the code, period and the Arabic and English name.',
                    'Set the limits (leave empty for no limit).',
                    'Add a price per country and save.',
                ],
            },
        ],
        affects: [
            { route: 'admin.marketing.countries', text: 'A price is not offered in a country where the service is off.' },
            { route: 'admin.marketing.operations', text: 'Sold subscriptions show in Operations.' },
        ],
        warnings: [
            { level: 'warning', text: 'Deleting a package with subscriptions only switches it off and hides it (soft delete); subscribers finish their period.' },
            { level: 'info', text: 'Changing limits also affects running subscriptions: the limit is read from the package when used.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/marketing/packages/index.vue',
            'Modules/Marketing/app/Http/Controllers/Admin/PackageController.php',
            'Modules/Marketing/app/Models/MarketingPackage.php (LIMITS)',
        ],
        api: [
            'GET /api/admin/v1/marketing/packages',
            'POST /api/admin/v1/marketing/packages   {code, name{ar,en}, description{ar,en}, period_days, limits{}, is_active, sort_order, prices[{country_id, price_minor, status}]}',
            'PUT /api/admin/v1/marketing/packages/{id}   (same fields, all optional)',
            'DELETE /api/admin/v1/marketing/packages/{id}',
        ],
        tables: ['marketing_packages', 'marketing_package_prices'],
        permissions: ['marketing-packages.view', 'marketing-packages.create', 'marketing-packages.update', 'marketing-packages.delete'],
        noTranslatableText: 'Name and description are a JSON {ar, en} column on marketing_packages, edited through the Arabic / English tabs, not a translation table.',
        decisions: [],
    },
};
