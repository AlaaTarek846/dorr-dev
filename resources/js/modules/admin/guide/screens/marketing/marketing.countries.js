/**
 * Guide: AI Marketing Director switches per country (admin.marketing.countries).
 * Source of truth checked: views/marketing/countries/index.vue, Modules/Marketing/routes/admin.php (feature-rules),
 * Modules/Marketing/app/Http/Controllers/Admin/FeatureRuleController.php (options, index, store, copy),
 * Modules/Marketing/app/Services/Features/MarketingFeatures.php (SERVICE, FEATURES, allows), locales adminMarketing.countries.
 */
export default {
    route: 'admin.marketing.countries',
    updated: '2026-11-11',

    ar: {
        summary: 'بتشغّل أو تقفل مدير التسويق الذكي ومميزاته في كل دولة لوحدها. مفيش قاعدة يعني مقفول.',
        audience: 'مدير الخدمة. الفتح محتاج "قواعد مميزات التسويق: عرض"، والتشغيل والنسخ محتاجين "تعديل".',
        purpose: [
            'تفتح الخدمة في دولة جديدة.',
            'تقفل ميزة واحدة (زي التصدير أو الفريق) من غير ما تقفل الخدمة كلها.',
            'تنسخ إعدادات دولة جاهزة لدولة تانية.',
        ],
        sections: [
            {
                title: 'فوق',
                items: [
                    { name: 'الدولة', desc: 'الدول المفعّلة بس. الجدول بيعرض مفاتيح الدولة المختارة.' },
                    { name: 'إتاحة الخدمات', desc: 'رابط لشاشة إتاحة الخدمات، اللي ممكن تقفل الخدمة كلها في دولة حتى لو المفتاح هنا شغّال.' },
                    { name: 'نسخ من دولة', desc: 'بينسخ كل قواعد دولة تانية على الدولة المختارة (بيكتب فوق الموجود).' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'marketing', desc: 'الخدمة نفسها. لو مقفولة، مفيش أي ميزة بتشتغل في الدولة.' },
                    { name: 'research / plans / finance / export / team', desc: 'البحث، الخطط، الأرقام المالية، التصدير، وفريق المشروع. كل واحدة مفتاح لوحدها.' },
                    { name: 'محتاجة الخدمة', desc: 'شارة بتظهر لما ميزة شغّالة والخدمة نفسها مقفولة: الميزة مش هتشتغل.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'فتح الخدمة في دولة جديدة',
                steps: [
                    'اختار الدولة.',
                    'استخدم "نسخ من دولة" من دولة شغّالة، أو شغّل المفاتيح واحد واحد.',
                    'اتأكد إن "marketing" شغّال.',
                    'اتأكد إن فيه باقة ليها سعر في الدولة دي من شاشة الباقات.',
                ],
            },
        ],
        affects: [
            { route: 'admin.marketing.packages', text: 'العميل مش هيقدر يشترك في دولة مفيهاش سعر لباقة، حتى لو الخدمة شغّالة.' },
        ],
        warnings: [
            { level: 'info', text: 'التغيير بيوصل للتطبيقات خلال دقايق (الكاش 5 دقايق).' },
            { level: 'warning', text: 'النسخ بيكتب فوق قواعد الدولة المختارة.' },
        ],
    },

    en: {
        summary: 'Switch the AI Marketing Director and each of its features on or off per country. No rule means off.',
        audience: 'The service manager. Opening needs "Marketing feature rules: view"; toggling and copying need "update".',
        purpose: [
            'Open the service in a new country.',
            'Close one feature (export, team…) without closing the whole service.',
            'Copy a ready country\'s setup to another one.',
        ],
        sections: [
            {
                title: 'Top',
                items: [
                    { name: 'Country', desc: 'Active countries only. The table shows the switches of the chosen one.' },
                    { name: 'Service availability', desc: 'Link to the availability screen, which can close the whole service in a country even when the switch here is on.' },
                    { name: 'Copy from a country', desc: 'Copies every rule of another country onto the chosen one (overwrites).' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'marketing', desc: 'The service itself. While it is off, no feature works in the country.' },
                    { name: 'research / plans / finance / export / team', desc: 'Research, plans, the finance figures, export and the project team, each with its own switch.' },
                    { name: 'Needs the service', desc: 'Shown when a feature is on but the service itself is off: the feature will not work.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Open the service in a new country',
                steps: [
                    'Pick the country.',
                    'Use "Copy from a country" from a working one, or switch the features on one by one.',
                    'Make sure "marketing" is on.',
                    'Make sure a package has a price in that country (Packages screen).',
                ],
            },
        ],
        affects: [
            { route: 'admin.marketing.packages', text: 'A customer cannot subscribe in a country where no package has a price, even with the service on.' },
        ],
        warnings: [
            { level: 'info', text: 'Changes reach the apps within minutes (5-minute cache).' },
            { level: 'warning', text: 'Copying overwrites the chosen country\'s rules.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/marketing/countries/index.vue',
            'Modules/Marketing/app/Http/Controllers/Admin/FeatureRuleController.php',
            'Modules/Marketing/app/Services/Features/MarketingFeatures.php',
        ],
        api: [
            'GET /api/admin/v1/marketing/feature-rules/options',
            'GET /api/admin/v1/marketing/feature-rules?country_id=',
            'POST /api/admin/v1/marketing/feature-rules   {feature, country_id, enabled, note?}',
            'POST /api/admin/v1/marketing/feature-rules/copy   {from_country_id, to_country_id}',
        ],
        tables: ['marketing_feature_rules'],
        permissions: ['marketing-feature-rules.view (options, index)', 'marketing-feature-rules.update (store, copy)'],
        decisions: [],
    },
};
