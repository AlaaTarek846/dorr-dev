import middlewarePipeline from './middlewarePipeline';
import { canAccessAdminRoute } from '../modules/admin/adminRoutePermission';
import { useAuthStore } from '../stores/auth';

export function setupGuards(router) {
    router.beforeEach(async (to, from, next) => {
        const requiredPermission = to.meta?.permission;

        if (requiredPermission) {
            const allowed = await canAccessAdminRoute(requiredPermission);

            if (! allowed) {
                const authStore = useAuthStore();

                return next(
                    authStore.isAuthenticated
                        ? { name: 'Page404' }
                        : { name: 'admin.login' },
                );
            }
        }

        const middleware = to.meta.middleware;

        if (! middleware) {
            return next();
        }

        const middlewareArray = Array.isArray(middleware) ? middleware : [middleware];

        const context = {
            to,
            from,
            next,
        };

        return middlewareArray[0]({
            ...context,
            next: middlewarePipeline(context, middlewareArray, 1),
        });
    });

    return router;
}
