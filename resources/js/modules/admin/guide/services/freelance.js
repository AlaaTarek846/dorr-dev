/**
 * Service map: freelance (Modules/Freelance admin screens).
 *
 * Titles reuse the sidebar's i18n keys (components/layout/admin/Sidebar.vue → freelanceItems), so a screen is never named twice.
 * A screen is "documented" once a guide file exists for its route in ../screens/freelance/.
 */
export default {
    key: 'freelance',
    module: 'Freelance',
    title: 'sidebar.freelance',
    icon: 'ri-briefcase-4-line',

    intro: {
        ar: 'الشاشات دي بتدير سوق الشغل الحر: المحترفين وفرقهم، المشاريع والخدمات والاستشارات والعروض، النزاعات والبلاغات، والقوائم اللي التطبيق بيختار منها.',
        en: 'These screens run the freelance marketplace: freelancers and teams, projects, services, consultations and offers, disputes and reports, and the lists the app picks from.',
    },

    firstRun: [
        {
            route: 'admin.freelance.switches',
            ar: 'شغّل الخدمة ومميزاتها في الدول اللي هتفتح فيها.',
            en: 'Switch the service and its features on in the countries you open.',
        },
        {
            route: 'admin.freelance.policies',
            ar: 'اضبط الأسعار والعمولات والحدود.',
            en: 'Set the prices, commissions and limits.',
        },
        {
            route: 'admin.freelance.specialties',
            ar: 'جهّز التخصصات والمهارات وقوالب قوائم التحقق اللي التطبيق بيختار منها.',
            en: 'Prepare the specialties, skills and checklist templates the app picks from.',
        },
        {
            route: 'admin.freelance.verifications',
            ar: 'راجع توثيق المحترفين.',
            en: 'Review the freelancers\' verifications.',
        },
    ],

    flow: [
        { group: 'setup', ar: 'الخدمة شغّالة في الدولة وبأنهي قواعد؟', en: 'Is the service on in the country, and under which rules?' },
        { group: 'catalogs', ar: 'العميل والمحترف بيختاروا من القوائم', en: 'Client and freelancer pick from the lists' },
        { group: 'work', ar: 'مشروع أو خدمة أو استشارة أو عرض خاص', en: 'A project, service, consultation or special offer' },
        { group: 'people', ar: 'أي نزاع أو بلاغ بيتراجع هنا', en: 'Any dispute or report is reviewed here' },
    ],

    groups: [
        {
            key: 'setup',
            title: 'sidebar.freelance_groups.setup',
            icon: 'ri-toggle-line',
            screens: [
                { route: 'admin.freelance.overview', title: 'freelance.overview.title' },
                { route: 'admin.freelance.switches', title: 'freelance.switches.title' },
                { route: 'admin.freelance.policies', title: 'freelance.policies.title' },
                { route: 'admin.freelance.plans', title: 'freelance.plans.title' },
                { route: 'admin.freelance.countries', title: 'freelance.countries.title' },
                { route: 'admin.freelance.insights', title: 'freelance.insights.title' },
                { route: 'admin.freelance.reports', title: 'freelance.reports.title' },
                { route: 'admin.freelance.audit', title: 'freelance.audit.title' },
            ],
        },
        {
            key: 'work',
            title: 'sidebar.freelance_groups.work',
            icon: 'ri-survey-line',
            screens: [
                { route: 'admin.freelance.projects', title: 'freelance.projects.title' },
                { route: 'admin.freelance.oversight', title: 'freelance.oversight.title' },
                { route: 'admin.freelance.late', title: 'freelance.late.title' },
                { route: 'admin.freelance.services', title: 'freelance.services.title' },
                { route: 'admin.freelance.consultations', title: 'freelance.consultations.title' },
                { route: 'admin.freelance.specialOffers', title: 'freelance.specialOffers.title' },
                { route: 'admin.freelance.loyalty', title: 'freelance.loyalty.title' },
                { route: 'admin.freelance.promos', title: 'freelance.promos.title' },
            ],
        },
        {
            key: 'people',
            title: 'sidebar.freelance_groups.people',
            icon: 'ri-shield-user-line',
            screens: [
                { route: 'admin.freelance.freelancers', title: 'freelance.freelancers.title' },
                { route: 'admin.freelance.teams', title: 'freelance.teams.title' },
                { route: 'admin.freelance.verifications', title: 'freelance.verifications.title' },
                { route: 'admin.freelance.disputes', title: 'freelance.disputes.title' },
                { route: 'admin.freelance.sanctions', title: 'freelance.sanctions.title' },
                { route: 'admin.freelance.reviews', title: 'freelance.reviews.title' },
                { route: 'admin.freelance.messageReports', title: 'freelance.messageReports.title' },
                { route: 'admin.freelance.quality', title: 'freelance.quality.title' },
                { route: 'admin.freelance.risk', title: 'freelance.risk.title' },
                { route: 'admin.freelance.support', title: 'freelance.support.title' },
                { route: 'admin.freelance.community', title: 'freelance.community.title' },
                { route: 'admin.freelance.rights', title: 'freelance.rights.title' },
            ],
        },
        {
            key: 'catalogs',
            title: 'sidebar.freelance_groups.catalogs',
            icon: 'ri-list-check-2',
            screens: [
                { route: 'admin.freelance.specialties', title: 'freelance.specialties.title' },
                { route: 'admin.freelance.skills', title: 'freelance.skills.title' },
                { route: 'admin.freelance.skillTests', title: 'freelance.skill_tests.title' },
                { route: 'admin.freelance.document-types', title: 'freelance.document_types.title' },
                { route: 'admin.freelance.checklistTemplates', title: 'freelance.checklist_templates.title' },
            ],
        },
    ],
};
