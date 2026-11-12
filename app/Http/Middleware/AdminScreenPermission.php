<?php

namespace App\Http\Middleware;

use App\Support\Admin\AdminPermissionMiddleware;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin screen permission for a whole route group (docs/screens/README.md "Screen standard"):
 *
 *   Route::middleware([..., 'admin.screen:ai-intents,ai-routing-rules'])->prefix('admin/v1/ai-intents')
 *
 * The action is read off the request, the same split as AdminPermissionMiddleware::catalog() (countries):
 *   GET / HEAD            → {group}.view
 *   store                 → {group}.create
 *   destroy, forceDestroy → {group}.delete
 *   changeStatus          → {group}.change-status
 *   deleteMultiple        → {group}.multiple-delete
 *   any other write       → {group}.update   (update, restore, test, sync, run, reply…)
 *
 * Extra groups after the first may only READ (GET): the screens whose forms fill a dropdown from this list
 * (routing rules read intents and routing policies…), so giving someone one screen never breaks its form.
 */
class AdminScreenPermission
{
    private const ACTION_BY_METHOD = [
        'store' => 'create',
        'destroy' => 'delete',
        'forceDestroy' => 'delete',
        'changeStatus' => 'change-status',
        'deleteMultiple' => 'multiple-delete',
    ];

    public function handle(Request $request, Closure $next, string $group, string ...$alsoReadableBy): Response
    {
        $admin = $request->user(AdminPermissionMiddleware::GUARD);

        if ($admin === null) {
            throw UnauthorizedException::notLoggedIn();
        }

        $action = self::actionFor($request);
        $allowed = [$group.'.'.$action];

        if ($action === 'view') {
            foreach ($alsoReadableBy as $reader) {
                $allowed[] = $reader.'.view';
            }
        }

        foreach ($allowed as $permission) {
            if ($admin->checkPermissionTo($permission, AdminPermissionMiddleware::GUARD)) {
                return $next($request);
            }
        }

        // Same answer as Spatie's permission middleware on every other admin route.
        throw UnauthorizedException::forPermissions($allowed);
    }

    public static function actionFor(Request $request): string
    {
        if ($request->isMethodSafe()) {
            return 'view';
        }

        $method = $request->route()?->getActionMethod();

        return self::ACTION_BY_METHOD[$method] ?? 'update';
    }
}
