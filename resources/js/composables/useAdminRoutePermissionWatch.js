import { watch } from 'vue';
import { storeToRefs } from 'pinia';
import { useRoute, useRouter } from 'vue-router';
import { adminHasPermission, ensureAdminPermissionsLoaded } from '../modules/admin/adminRoutePermission';
import { useAuthStore } from '../stores/auth';

/**
 * Re-run route permission checks when store.permission changes (e.g. after /me or role update).
 */
export function useAdminRoutePermissionWatch() {
    const route = useRoute();
    const router = useRouter();
    const authStore = useAuthStore();
    const { permission_names: permissionNames, isAuthenticated } = storeToRefs(authStore);

    async function enforceCurrentRoute() {
        const required = route.meta?.permission;

        if (! required || ! isAuthenticated.value) {
            return;
        }

        await ensureAdminPermissionsLoaded();

        if (! adminHasPermission(required)) {
            await router.replace({ name: 'Page404' });
        }
    }

    watch(
        permissionNames,
        () => {
            enforceCurrentRoute();
        },
        { deep: true },
    );

    watch(
        () => route.name,
        () => {
            enforceCurrentRoute();
        },
    );

    enforceCurrentRoute();
}
