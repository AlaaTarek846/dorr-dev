import { useAuthStore } from '../../stores/auth';
import { canAccessAdminRoute } from './adminRoutePermission';

export async function permissionBeforeEnter(to, from, next) {
    const required = to.meta?.permission;

    if (! required) {
        return next();
    }

    const allowed = await canAccessAdminRoute(required);

    if (allowed) {
        return next();
    }

    const store = useAuthStore();

    if (! store.isAuthenticated) {
        return next({ name: 'admin.login' });
    }

    return next({ name: 'Page404' });
}
