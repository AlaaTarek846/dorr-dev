import { createRouter, createWebHistory } from 'vue-router';
import userRoutes from '../modules/user/routes';
import { setupGuards } from './guards';
import '../layouts/AuthLayout.vue';
import '../layouts/user/UserLayout.vue';

const router = createRouter({
    history: createWebHistory('/user'),
    routes: [...userRoutes],
});

setupGuards(router);

export default router;
