<?php

namespace App\Http\Middleware;

use App\Support\Admin\AdminPermissionMiddleware;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;

/**
 * One dashboard area guarded by two permissions: reading it needs `{group}.view`, changing anything in it (POST, PUT,
 * PATCH, DELETE) needs `{group}.update`. For areas made of many small screens (the AI pages) where a per-method map on
 * every controller would say the same thing. Route usage: `admin.area:ai-routing`.
 */
class AdminAreaPermission
{
    public function __construct(private readonly PermissionMiddleware $permission) {}

    public function handle(Request $request, Closure $next, string $group): Response
    {
        $action = $request->isMethodSafe() ? 'view' : 'update';

        return $this->permission->handle($request, $next, "{$group}.{$action}", AdminPermissionMiddleware::GUARD);
    }
}
