import { createRouter, createWebHistory } from 'vue-router';
import providerRoutes from '../modules/provider/routes';
import { setupGuards } from './guards';
import '../layouts/AuthLayout.vue';
import '../layouts/ProviderLayout.vue';

const router = createRouter({
    history: createWebHistory('/provider'),
    routes: [...providerRoutes],
});

setupGuards(router);

export default router;
