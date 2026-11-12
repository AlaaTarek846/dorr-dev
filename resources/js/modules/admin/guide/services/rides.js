/**
 * Service map: passenger rides (Modules/Rides + Modules/Driver admin screens).
 *
 * Titles reuse the sidebar's i18n keys (components/layout/admin/Sidebar.vue → ridesItems), so a screen is never named twice.
 * A screen is "documented" once a guide file exists for its route in ../screens/rides/.
 */
export default {
    key: 'rides',
    module: 'Rides',
    title: 'sidebar.rides',
    icon: 'ri-taxi-line',

    intro: {
        ar: 'الشاشات دي بتدير خدمة التوصيل: الرحلات والكباتن والبلاغات، والأسعار والمناطق، والخدمات اللي كل نوع عربية يشتغل فيها في كل دولة.',
        en: 'These screens run the rides service: rides, captains and cases, prices and zones, and which services each kind of vehicle works in per country.',
    },

    firstRun: [
        {
            route: 'admin.rides.settings',
            ar: 'اضبط إعدادات الرحلات العامة (مفاتيح الخرايط وغيرها) واختبر الاتصال.',
            en: 'Set the general ride settings (maps keys and the rest) and test the connection.',
        },
        {
            route: 'admin.rides.categories',
            ar: 'جهّز فئات الرحلات وأسعارها وقواعدها لكل دولة.',
            en: 'Prepare the ride categories, their prices and rules per country.',
        },
        {
            route: 'admin.rides.driver_services',
            ar: 'حدد لكل دولة أنهي نوع عربية يشتغل في أنهي خدمة. دولة ما اتحفظتش بتشتغل بالافتراضي.',
            en: 'Per country, decide which kind of vehicle works in which service. A country never saved works with the defaults.',
        },
        {
            route: 'admin.rides.drivers',
            ar: 'راجع الكباتن ووثايقهم وفعّلهم.',
            en: 'Review the captains and their documents, then activate them.',
        },
    ],

    flow: [
        { group: 'setup', ar: 'الدولة فيها فئات وأسعار وخدمات مفتوحة؟', en: 'Does the country have categories, prices and open services?' },
        { group: 'captains', ar: 'الكابتن مفعّل وعربيته تشتغل في الخدمة؟', en: 'Is the captain active and allowed in the service?' },
        { group: 'operations', ar: 'الرحلة بتتسجل وأي بلاغ بيتابَع', en: 'The ride is logged and any case is followed up' },
    ],

    groups: [
        {
            key: 'operations',
            title: 'sidebar.rides_groups.operations',
            icon: 'ri-taxi-line',
            screens: [
                { route: 'admin.rides.rides', title: 'rides.rides.title' },
                { route: 'admin.rides.rides.show', title: 'rides.rides.title' },
                { route: 'admin.rides.cases', title: 'rides.cases.title' },
                { route: 'admin.rides.cases.show', title: 'rides.cases.title' },
                { route: 'admin.rides.safety', title: 'rides.safety.title' },
                { route: 'admin.rides.quality', title: 'rides.quality.title' },
                { route: 'admin.rides.wasl', title: 'rides.wasl_logs.title' },
            ],
        },
        {
            key: 'captains',
            title: 'sidebar.rides_groups.captains',
            icon: 'ri-steering-2-line',
            screens: [
                { route: 'admin.rides.drivers', title: 'rides.drivers.title' },
                { route: 'admin.rides.drivers.show', title: 'rides.drivers.title' },
                { route: 'admin.rides.document-types', title: 'rides.document_types.title' },
                { route: 'admin.rides.driver_services', title: 'rides.driver_services.title' },
                { route: 'admin.rides.ranks', title: 'rides.ranks.title' },
                { route: 'admin.rides.loyalty', title: 'rides.loyalty.title' },
            ],
        },
        {
            key: 'setup',
            title: 'sidebar.rides_groups.setup',
            icon: 'ri-settings-4-line',
            screens: [
                { route: 'admin.rides.settings', title: 'rides.settings.title' },
                { route: 'admin.rides.switches', title: 'rides.switches.title' },
                { route: 'admin.rides.categories', title: 'rides.categories.title' },
                { route: 'admin.rides.policies', title: 'rides.policies.title' },
                { route: 'admin.rides.zones', title: 'rides.zones.title' },
                { route: 'admin.rides.lists', title: 'rides.lists.title' },
            ],
        },
    ],
};
