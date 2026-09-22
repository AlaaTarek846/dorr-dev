<?php

namespace App\Support\Admin;

use Illuminate\Routing\Controllers\Middleware;

/**
 * Builds controller middleware for Spatie permissions ({group}.{action}, guard admin_api).
 */
final class AdminPermissionMiddleware
{
    public const GUARD = 'admin_api';

    public static function permission(string $group, string $action): string
    {
        return 'permission:'.$group.'.'.$action.','.self::GUARD;
    }

    /**
     * Full admin catalog (apiResource + trash + status + bulk delete).
     *
     * @param  list<array{0: string, 1: list<string>}>  $extraActionMethods
     * @return list<Middleware>
     */
    public static function catalog(
        string $group,
        bool $secureDropdown = true,
        array $extraActionMethods = [],
    ): array {
        $map = [
            ['view', ['index', 'show']],
            ['create', ['store']],
            ['update', ['update', 'restore']],
            ['delete', ['destroy', 'forceDestroy']],
            ['change-status', ['changeStatus']],
            ['multiple-delete', ['deleteMultiple']],
        ];

        if ($secureDropdown) {
            $map[] = ['view', ['dropdown']];
        }

        return self::fromActionMethodMap($group, [...$map, ...$extraActionMethods]);
    }

    /**
     * apiResource + deleteMultiple (roles).
     *
     * @return list<Middleware>
     */
    public static function apiResourceWithBulkDelete(string $group): array
    {
        return self::fromActionMethodMap($group, [
            ['view', ['index', 'show']],
            ['create', ['store']],
            ['update', ['update']],
            ['delete', ['destroy']],
            ['multiple-delete', ['deleteMultiple']],
        ]);
    }

    /**
     * @param  list<array{0: string, 1: list<string>}>  $actionMethods
     * @return list<Middleware>
     */
    public static function fromActionMethodMap(string $group, array $actionMethods): array
    {
        $middleware = [];

        foreach ($actionMethods as [$action, $methods]) {
            $middleware[] = new Middleware(
                self::permission($group, $action),
                only: $methods,
            );
        }

        return $middleware;
    }
}
