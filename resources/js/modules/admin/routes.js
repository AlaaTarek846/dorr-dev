import AuthLayout from '../../layouts/AuthLayout.vue';
import { resolvePage } from '../../dashboard/resolvePage';
import { resolveShell } from '../../dashboard/resolveShell';
import guest from '../../router/middleware/guest';
import auth from '../../router/middleware/auth';

const page = (viewPath) => resolvePage('admin', viewPath);

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
                component: page('Login'),
                meta: { middleware: [guest] },
            },
        ],
    },
    {
        path: '/',
        component: resolveShell('admin'),
        children: [
            {
                path: 'dashboard',
                name: 'admin.dashboard',
                component: page('Dashboard'),
                meta: { middleware: [auth] },
            },
            {
                path: 'flags',
                name: 'admin.flags.index',
                component: page('flag/index'),
                meta: { middleware: [auth] },
            },
            {
                path: 'countries',
                name: 'admin.countries.index',
                component: page('country/index'),
                meta: { middleware: [auth] },
            },
            {
                path: 'currencies',
                name: 'admin.currencies.index',
                component: page('currency/index'),
                meta: { middleware: [auth] },
            },
            {
                path: 'languages',
                name: 'admin.languages.index',
                component: page('language/index'),
                meta: { middleware: [auth] },
            },
            {
                path: 'profile',
                name: 'admin.profile',
                component: page('profile/index'),
                meta: { middleware: [auth] },
            },
            {
                path: 'dashboard-themes',
                name: 'admin.dashboard-themes.index',
                component: page('dashboard-theme/index'),
                meta: { middleware: [auth] },
            },
            {
                path: 'platform-settings',
                name: 'admin.platform-settings',
                component: page('platform-settings/index'),
                meta: { middleware: [auth] },
            },
            {
                path: 'ai-settings',
                name: 'admin.ai-settings',
                component: page('ai-settings/index'),
                meta: { middleware: [auth] },
            },
            {
                path: 'users',
                name: 'admin.users.index',
                component: page('user/index'),
                meta: { middleware: [auth] },
            },
            {
                path: 'service-categories',
                name: 'admin.service-categories.index',
                component: page('service-category/index'),
                meta: { middleware: [auth] },
            },
            {
                path: 'providers',
                name: 'admin.providers.index',
                component: page('provider/index'),
                meta: { middleware: [auth] },
            },
        ],
    },
];
