import AuthLayout from '../../layouts/AuthLayout.vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import guest from '../../router/middleware/guest';
import auth from '../../router/middleware/auth';

export default [
    {
        path: '/',
        component: AuthLayout,
        children: [
            {
                path: '',
                redirect: { name: 'admin.login' },
            },
            {
                path: 'login',
                name: 'admin.login',
                component: () => import('./views/Login.vue'),
                meta: { middleware: [guest] },
            },
        ],
    },
    {
        path: '/',
        component: AdminLayout,
        children: [
            {
                path: 'dashboard',
                name: 'admin.dashboard',
                component: () => import('./views/Dashboard.vue'),
                meta: { middleware: [auth] },
            },
            {
                path: 'flags',
                name: 'admin.flags.index',
                component: () => import('./views/flag/index.vue'),
                meta: { middleware: [auth] },
            },
            {
                path: 'countries',
                name: 'admin.countries.index',
                component: () => import('./views/country/index.vue'),
                meta: { middleware: [auth] },
            },
            {
                path: 'currencies',
                name: 'admin.currencies.index',
                component: () => import('./views/currency/index.vue'),
                meta: { middleware: [auth] },
            },
            {
                path: 'languages',
                name: 'admin.languages.index',
                component: () => import('./views/language/index.vue'),
                meta: { middleware: [auth] },
            },
            {
                path: 'profile',
                name: 'admin.profile',
                component: () => import('./views/profile/index.vue'),
                meta: { middleware: [auth] },
            },
            {
                path: 'dashboard-themes',
                name: 'admin.dashboard-themes.index',
                component: () => import('./views/dashboard-theme/index.vue'),
                meta: { middleware: [auth] },
            },
            {
                path: 'platform-settings',
                name: 'admin.platform-settings',
                component: () => import('./views/platform-settings/index.vue'),
                meta: { middleware: [auth] },
            },
            {
                path: 'ai-settings',
                name: 'admin.ai-settings',
                component: () => import('./views/ai-settings/index.vue'),
                meta: { middleware: [auth] },
            },
            {
                path: 'users',
                name: 'admin.users.index',
                component: () => import('./views/user/index.vue'),
                meta: { middleware: [auth] },
            },
            {
                path: 'service-categories',
                name: 'admin.service-categories.index',
                component: () => import('./views/service-category/index.vue'),
                meta: { middleware: [auth] },
            },
            {
                path: 'providers',
                name: 'admin.providers.index',
                component: () => import('./views/provider/index.vue'),
                meta: { middleware: [auth] },
            },
        ],
    },
];
