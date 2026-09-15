import AuthCoverLayout from '../../layouts/AuthCoverLayout.vue';
import AuthLayout from '../../layouts/AuthLayout.vue';
import UserLayout from '../../layouts/UserLayout.vue';
import userAuth from '../../router/middleware/userAuth';
import userGuest from '../../router/middleware/userGuest';

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
                component: () => import('./views/Login.vue'),
                meta: { middleware: [userGuest] },
            },
            {
                path: 'verify-email',
                name: 'user.verify-email',
                component: () => import('./views/VerifyEmail.vue'),
                meta: { middleware: [userGuest] },
            },
            {
                path: 'oauth/callback',
                name: 'user.oauth.callback',
                component: () => import('./views/OAuthCallback.vue'),
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
                component: () => import('./views/SignUp.vue'),
                meta: { middleware: [userGuest] },
            },
            {
                path: 'create-password',
                name: 'user.create-password',
                component: () => import('./views/CreatePassword.vue'),
                meta: { middleware: [userGuest] },
            },
            {
                path: 'forgot-password',
                name: 'user.forgot-password',
                component: () => import('./views/ForgotPassword.vue'),
                meta: { middleware: [userGuest] },
            },
            {
                path: 'reset-password',
                name: 'user.reset-password',
                component: () => import('./views/ResetPassword.vue'),
                meta: { middleware: [userGuest] },
            },
        ],
    },
    {
        path: '/',
        component: UserLayout,
        children: [
            {
                path: 'dashboard',
                name: 'user.dashboard',
                component: () => import('./views/Dashboard.vue'),
                meta: { middleware: [userAuth] },
            },
            {
                path: 'profile',
                name: 'user.profile',
                component: () => import('./views/profile/index.vue'),
                meta: { middleware: [userAuth] },
            },
        ],
    },
];
