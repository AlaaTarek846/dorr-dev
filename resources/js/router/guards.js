import middlewarePipeline from './middlewarePipeline';

export function setupGuards(router) {
    router.beforeEach((to, from, next) => {
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
