<?php

namespace Modules\Chat\Http\Middleware;

use App\Services\General\AuditLogger;
use App\Support\Admin\AdminPermissionMiddleware;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\Middleware;
use Modules\Admin\Models\Admin;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The permission check of every account-verification admin route (AV-004, REQ-10-03): same rule as
 * Spatie's `permission:` middleware, but a member of staff turned away is written to the audit log
 * (`chat_verification.denied`) — someone without the right trying to approve a request is part of
 * the trail (AT-09), not just a 403.
 */
class EnsureVerificationPermission
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $admin = $request->user(AdminPermissionMiddleware::GUARD);

        if (! $admin instanceof Admin || ! self::allows($admin, $permission)) {
            $this->audit->record('chat_verification.denied', null, null, [
                'permission' => $permission,
                'method' => $request->method(),
                'path' => '/'.$request->path(),
            ], $admin instanceof Admin ? $admin : null);

            throw new AuthorizationException;
        }

        return $next($request);
    }

    public static function allows(Admin $admin, string $permission): bool
    {
        try {
            return $admin->hasRole('super-admin', AdminPermissionMiddleware::GUARD)
                || $admin->hasPermissionTo($permission, AdminPermissionMiddleware::GUARD);
        } catch (Throwable) {
            return false; // the permission isn't seeded (yet) — nobody holds it
        }
    }

    /**
     * Controller middleware like AdminPermissionMiddleware::fromActionMethodMap(), through this check.
     *
     * @param  list<array{0: string, 1: list<string>}>  $actionMethods
     * @return list<Middleware>
     */
    public static function map(string $group, array $actionMethods): array
    {
        return array_map(
            fn (array $pair) => new Middleware(self::class.':'.$group.'.'.$pair[0], only: $pair[1]),
            $actionMethods,
        );
    }
}
