import { createRouter, createWebHistory } from 'vue-router';
import adminRoutes from '../modules/admin/routes';
import { setupGuards } from './guards';
import '../layouts/AdminLayout.vue';
import '../layouts/AuthLayout.vue';

const router = createRouter({
    history: createWebHistory('/admin'),
    routes: [...adminRoutes],
});

setupGuards(router);

export default router;
