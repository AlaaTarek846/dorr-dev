import AuthCoverLayout from '../../layouts/AuthCoverLayout.vue';
import AuthLayout from '../../layouts/AuthLayout.vue';
import ProviderLayout from '../../layouts/provider/ProviderLayout.vue';
import providerAuth from '../../router/middleware/providerAuth';
import providerGuest from '../../router/middleware/providerGuest';

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
                component: () => import('./views/Login.vue'),
                meta: { middleware: [providerGuest] },
            },
            {
                path: 'oauth/callback',
                name: 'provider.oauth.callback',
                component: () => import('./views/OAuthCallback.vue'),
                meta: { middleware: [providerGuest] },
            },
            {
                path: 'verify-email',
                name: 'provider.verify-email',
                component: () => import('./views/VerifyEmail.vue'),
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
                component: () => import('./views/SignUp.vue'),
                meta: { middleware: [providerGuest] },
            },
            {
                path: 'create-password',
                name: 'provider.create-password',
                component: () => import('./views/CreatePassword.vue'),
                meta: { middleware: [providerGuest] },
            },
            {
                path: 'forgot-password',
                name: 'provider.forgot-password',
                component: () => import('./views/ForgotPassword.vue'),
                meta: { middleware: [providerGuest] },
            },
            {
                path: 'reset-password',
                name: 'provider.reset-password',
                component: () => import('./views/ResetPassword.vue'),
                meta: { middleware: [providerGuest] },
            },
        ],
    },
    {
        path: '/',
        component: ProviderLayout,
        children: [
            {
                path: 'dashboard',
                name: 'provider.dashboard',
                component: () => import('./views/Dashboard.vue'),
                meta: { middleware: [providerAuth] },
            },
            {
                path: 'profile',
                name: 'provider.profile',
                component: () => import('./views/profile/index.vue'),
                meta: { middleware: [providerAuth] },
            },
        ],
    },
];
