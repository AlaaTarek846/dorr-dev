/**
 * Service map: AI Marketing Director (Modules/Marketing admin screens, MK-A15).
 *
 * Titles reuse the sidebar's i18n keys (components/layout/admin/Sidebar.vue → marketingItems), so a screen is never named twice.
 * A screen is "documented" once a guide file exists for its route in ../screens/marketing/.
 */
export default {
    key: 'marketing',
    module: 'Marketing',
    title: 'adminMarketing.sidebar',
    icon: 'ri-megaphone-line',

    intro: {
        ar: 'الشاشات دي بتدير مدير التسويق الذكي: الخدمة ومميزاتها شغّالة في أنهي دولة، الباقات وحدودها وأسعارها، ومتابعة الاشتراكات والمشاريع والمهام من غير ما تشوف محتوى خطط أو أبحاث العملاء.',
        en: 'These screens run the AI Marketing Director: where the service and its features are on, the packages with their limits and prices, and a watch over subscriptions, projects and jobs without ever seeing a customer\'s plans or research.',
    },

    firstRun: [
        {
            route: 'admin.marketing.countries',
            ar: 'شغّل الخدمة "marketing" ومميزاتها في الدول اللي هتفتح فيها.',
            en: 'Switch the "marketing" service and its features on in the countries you open.',
        },
        {
            route: 'admin.marketing.packages',
            ar: 'اعمل باقة واحدة على الأقل بحدودها وسعرها في كل دولة.',
            en: 'Create at least one package with its limits and a price in each country.',
        },
        {
            route: 'admin.marketing.operations',
            ar: 'تابع الاشتراكات والمهام اللي فشلت.',
            en: 'Watch the subscriptions and the failed jobs.',
        },
    ],

    flow: [
        { group: 'setup', ar: 'الخدمة شغّالة في الدولة وفيه باقة بسعر؟', en: 'Is the service on in the country, with a priced package?' },
        { group: 'operations', ar: 'العميل اشترك وعمل مشروع وبحث وخطة', en: 'The customer subscribed and ran a project, research and plans' },
    ],

    groups: [
        {
            key: 'setup',
            title: 'adminMarketing.groups.setup',
            icon: 'ri-toggle-line',
            screens: [
                { route: 'admin.marketing.countries', title: 'adminMarketing.countries.title' },
                { route: 'admin.marketing.packages', title: 'adminMarketing.packages.title' },
            ],
        },
        {
            key: 'operations',
            title: 'adminMarketing.groups.operations',
            icon: 'ri-pulse-line',
            screens: [
                { route: 'admin.marketing.operations', title: 'adminMarketing.operations.title' },
            ],
        },
    ],
};
