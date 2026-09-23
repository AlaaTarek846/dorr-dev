import adminAxios from '../../api/adminAxios';
import { useAuthStore } from '../../stores/auth';

export async function ensureAdminPermissionsLoaded() {
    const store = useAuthStore();

    if (! store.isAuthenticated) {
        return false;
    }

    if (store.permission_names.length > 0) {
        return true;
    }

    try {
        await store.refreshSession(adminAxios);

        return true;
    } catch {
        store.logout();

        return false;
    }
}

export function adminHasPermission(permissionName) {
    const store = useAuthStore();

    return store.permission.includes(permissionName);
}

export async function canAccessAdminRoute(permissionName) {
    if (! permissionName) {
        return true;
    }

    const loaded = await ensureAdminPermissionsLoaded();

    if (! loaded) {
        return false;
    }

    return adminHasPermission(permissionName);
}
