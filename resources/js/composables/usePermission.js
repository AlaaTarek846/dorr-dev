import { storeToRefs } from 'pinia';
import { useAuthStore } from '../stores/auth';

export function usePermission() {
    const authStore = useAuthStore();
    const { permission_names: permissionNames } = storeToRefs(authStore);

    function can(permissionName) {
        if (! permissionName) {
            return false;
        }

        return permissionNames.value.includes(permissionName);
    }

    function canAny(names) {
        if (! Array.isArray(names) || names.length === 0) {
            return false;
        }

        return names.some((name) => can(name));
    }

    function canAll(names) {
        if (! Array.isArray(names) || names.length === 0) {
            return false;
        }

        return names.every((name) => can(name));
    }

    return {
        permissionNames,
        can,
        canAny,
        canAll,
    };
}
