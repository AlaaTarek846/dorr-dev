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
            {
                path: 'errors/404',
                name: 'Page404',
                component: page('errors/404'),
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
                meta: { middleware: [auth], permission: 'flags.view' },
            },
            {
                path: 'countries',
                name: 'admin.countries.index',
                component: page('country/index'),
                meta: { middleware: [auth], permission: 'countries.view' },
            },
            {
                path: 'currencies',
                name: 'admin.currencies.index',
                component: page('currency/index'),
                meta: { middleware: [auth], permission: 'currencies.view' },
            },
            {
                path: 'languages',
                name: 'admin.languages.index',
                component: page('language/index'),
                meta: { middleware: [auth], permission: 'languages.view' },
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
                meta: { middleware: [auth], permission: 'dashboard_themes.view' },
            },
            {
                path: 'platform-settings',
                name: 'admin.platform-settings',
                component: page('platform-settings/index'),
                meta: { middleware: [auth], permission: 'platform_settings.view' },
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
                meta: { middleware: [auth], permission: 'users.view' },
            },
            {
                path: 'employees',
                name: 'admin.employees.index',
                component: page('employee/index'),
                meta: { middleware: [auth], permission: 'admins.view' },
            },
            {
                path: 'roles',
                name: 'admin.roles.index',
                component: page('role/index'),
                meta: { middleware: [auth], permission: 'roles.view' },
            },
            {
                path: 'service-categories',
                name: 'admin.service-categories.index',
                component: page('service-category/index'),
                meta: { middleware: [auth], permission: 'service_categories.view' },
            },
            {
                // No permission: every admin has their own notifications.
                path: 'notifications',
                name: 'admin.notifications.index',
                component: page('notifications/index'),
                meta: { middleware: [auth] },
            },
            {
                path: 'wallet/wallets',
                name: 'admin.wallet.wallets',
                component: page('wallet/wallets/index'),
                meta: { middleware: [auth], permission: 'wallets.view' },
            },
            {
                path: 'wallet/online-transactions',
                name: 'admin.wallet.online-transactions',
                component: page('wallet/online-transactions/index'),
                meta: { middleware: [auth], permission: 'online-transactions.view' },
            },
            {
                path: 'wallet/withdrawals',
                name: 'admin.wallet.withdrawals',
                component: page('wallet/withdrawals/index'),
                meta: { middleware: [auth], permission: 'withdrawal-requests.view' },
            },
            {
                path: 'wallet/financial-entries',
                name: 'admin.wallet.financial-entries',
                component: page('wallet/financial-entries/index'),
                meta: { middleware: [auth], permission: 'financial-entries.view' },
            },
            {
                path: 'wallet/payment-methods',
                name: 'admin.wallet.payment-methods',
                component: page('wallet/payment-methods/index'),
                meta: { middleware: [auth], permission: 'payment-methods.view' },
            },
            {
                path: 'wallet/fee-rules',
                name: 'admin.wallet.fee-rules',
                component: page('wallet/fee-rules/index'),
                meta: { middleware: [auth], permission: 'wallet-fee-rules.view' },
            },
            {
                path: 'wallet/settings',
                name: 'admin.wallet.settings',
                component: page('wallet/settings/index'),
                meta: { middleware: [auth], permission: 'wallet-settings.view' },
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
