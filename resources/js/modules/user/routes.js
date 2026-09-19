import AuthCoverLayout from '../../layouts/AuthCoverLayout.vue';
import AuthLayout from '../../layouts/AuthLayout.vue';
import { resolvePage } from '../../dashboard/resolvePage';
import { resolveShell } from '../../dashboard/resolveShell';
import userAuth from '../../router/middleware/userAuth';
import userGuest from '../../router/middleware/userGuest';

const page = (viewPath) => resolvePage('user', viewPath);

export default [
    {
        path: '/',
        component: AuthLayout,
        children: [
            {
                path: '',
                redirect: { name: 'user.login' },
            },
            {
                path: 'login',
                name: 'user.login',
                component: page('Login'),
                meta: { middleware: [userGuest] },
            },
            {
                path: 'verify-email',
                name: 'user.verify-email',
                component: page('VerifyEmail'),
                meta: { middleware: [userGuest] },
            },
            {
                path: 'oauth/callback',
                name: 'user.oauth.callback',
                component: page('OAuthCallback'),
                meta: { middleware: [userGuest] },
            },
        ],
    },
    {
        path: '/',
        component: AuthCoverLayout,
        children: [
            {
                path: 'sign-up',
                name: 'user.sign-up',
                component: page('SignUp'),
                meta: { middleware: [userGuest] },
            },
            {
                path: 'create-password',
                name: 'user.create-password',
                component: page('CreatePassword'),
                meta: { middleware: [userGuest] },
            },
            {
                path: 'forgot-password',
                name: 'user.forgot-password',
                component: page('ForgotPassword'),
                meta: { middleware: [userGuest] },
            },
            {
                path: 'reset-password',
                name: 'user.reset-password',
                component: page('ResetPassword'),
                meta: { middleware: [userGuest] },
            },
        ],
    },
    {
        path: '/',
        component: resolveShell('user'),
        children: [
            {
                path: 'dashboard',
                name: 'user.dashboard',
                component: page('Dashboard'),
                meta: { middleware: [userAuth] },
            },
            {
                path: 'profile',
                name: 'user.profile',
                component: page('profile/index'),
                meta: { middleware: [userAuth] },
            },
            {
                path: 'chat',
                name: 'user.chat',
                component: page('chat/index'),
                meta: { middleware: [userAuth] },
            },
        ],
    },
];
