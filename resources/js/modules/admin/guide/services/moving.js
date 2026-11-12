/**
 * Service map: furniture moving (Modules/Moving + the provider platform in Modules/Provider).
 *
 * Titles reuse the sidebar's i18n keys (components/layout/admin/Sidebar.vue → movingItems), so a screen is never named twice.
 * A screen is "documented" once a guide file exists for its route in ../screens/moving/.
 */
export default {
    key: 'moving',
    module: 'Moving',
    title: 'sidebar.moving',
    icon: 'ri-truck-line',

    intro: {
        ar: 'الشاشات دي بتدير خدمة نقل العفش: الطلبات والعروض، والشركات والأفراد اللي بيقدموا الخدمة وفروعهم وعربياتهم، والمستندات المطلوبة منهم، والبلاغات والتذاكر وسياسات الإلغاء.',
        en: 'These screens run furniture moving: requests and offers, the companies and individuals who do the moves with their branches and vehicles, the papers asked of them, and cases, tickets and cancellation policies.',
    },

    firstRun: [
        {
            route: 'admin.moving.vehicle_types',
            ar: 'جهّز أنواع العربيات (ربع نقل، نص نقل...) والدول والخدمات اللي كل نوع يشتغل فيها.',
            en: 'Prepare the vehicle types (quarter truck, half truck…), the countries and the services each one works in.',
        },
        {
            route: 'admin.moving.document_types',
            ar: 'حدد المستندات المطلوبة من المنشأة والعربية والسواق في كل دولة.',
            en: 'Decide the papers asked of the establishment, the vehicle and the driver in each country.',
        },
        {
            route: 'admin.moving.establishments',
            ar: 'راجع المنشآت ومستنداتها وعربياتها ووافق عليها علشان تبدأ تستقبل الطلبات.',
            en: 'Review the establishments, their papers and vehicles, and approve them so they start receiving requests.',
        },
    ],

    flow: [
        { group: 'setup', ar: 'الدولة فيها أنواع عربيات ومستندات مطلوبة؟', en: 'Does the country have vehicle types and required papers?' },
        { group: 'establishments', ar: 'المنشأة متوافق عليها وعندها عربية متوافق عليها من النوع المطلوب؟', en: 'Is the establishment approved, with an approved vehicle of the asked type?' },
        { group: 'operations', ar: 'الطلب بيوصل للفروع القريبة، والعميل بيختار عرض، وأي مشكلة بتتابَع', en: 'The request reaches nearby branches, the customer picks an offer, and any problem is followed up' },
    ],

    groups: [
        {
            key: 'operations',
            title: 'sidebar.moving_groups.operations',
            icon: 'ri-truck-line',
            screens: [
                { route: 'admin.moving.requests', title: 'moving.requests.title' },
                { route: 'admin.moving.tickets', title: 'moving.tickets.title' },
                { route: 'admin.moving.cases', title: 'moving.cases.title' },
            ],
        },
        {
            key: 'establishments',
            title: 'sidebar.moving_groups.establishments',
            icon: 'ri-store-2-line',
            screens: [
                { route: 'admin.moving.establishments', title: 'moving.establishments.title' },
                { route: 'admin.moving.establishments.show', title: 'moving.establishments.title' },
            ],
        },
        {
            key: 'setup',
            title: 'sidebar.moving_groups.setup',
            icon: 'ri-settings-4-line',
            screens: [
                { route: 'admin.moving.vehicle_types', title: 'moving.vehicle_types.title' },
                { route: 'admin.moving.document_types', title: 'moving.document_types.title' },
                { route: 'admin.moving.policies', title: 'moving.policies.title' },
            ],
        },
    ],
};
