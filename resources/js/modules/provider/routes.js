import AuthCoverLayout from '../../layouts/AuthCoverLayout.vue';
import AuthLayout from '../../layouts/AuthLayout.vue';
import { resolvePage } from '../../dashboard/resolvePage';
import { resolveShell } from '../../dashboard/resolveShell';
import providerAuth from '../../router/middleware/providerAuth';
import providerGuest from '../../router/middleware/providerGuest';

const page = (viewPath) => resolvePage('provider', viewPath);

export default [
    {
        path: '/',
        component: AuthLayout,
        children: [
            {
                path: '',
                redirect: { name: 'provider.login' },
            },
            {
                path: 'login',
                name: 'provider.login',
                component: page('Login'),
                meta: { middleware: [providerGuest] },
            },
            {
                path: 'oauth/callback',
                name: 'provider.oauth.callback',
                component: page('OAuthCallback'),
                meta: { middleware: [providerGuest] },
            },
            {
                path: 'verify-email',
                name: 'provider.verify-email',
                component: page('VerifyEmail'),
                meta: { middleware: [providerGuest] },
            },
        ],
    },
    {
        path: '/',
        component: AuthCoverLayout,
        children: [
            {
                path: 'sign-up',
                name: 'provider.sign-up',
                component: page('SignUp'),
                meta: { middleware: [providerGuest] },
            },
            {
                path: 'create-password',
                name: 'provider.create-password',
                component: page('CreatePassword'),
                meta: { middleware: [providerGuest] },
            },
            {
                path: 'forgot-password',
                name: 'provider.forgot-password',
                component: page('ForgotPassword'),
                meta: { middleware: [providerGuest] },
            },
            {
                path: 'reset-password',
                name: 'provider.reset-password',
                component: page('ResetPassword'),
                meta: { middleware: [providerGuest] },
            },
        ],
    },
    {
        path: '/',
        component: resolveShell('provider'),
        children: [
            {
                path: 'dashboard',
                name: 'provider.dashboard',
                component: page('Dashboard'),
                meta: { middleware: [providerAuth] },
            },
            {
                path: 'profile',
                name: 'provider.profile',
                component: page('profile/index'),
                meta: { middleware: [providerAuth] },
            },
        ],
    },
];
