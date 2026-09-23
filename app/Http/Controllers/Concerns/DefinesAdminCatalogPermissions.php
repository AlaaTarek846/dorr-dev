<?php

namespace App\Http\Controllers\Concerns;

use App\Support\Admin\AdminPermissionMiddleware;
use Illuminate\Routing\Controllers\Middleware;

trait DefinesAdminCatalogPermissions
{
    abstract protected static function adminPermissionGroup(): string;

    protected static function permissionsOnDropdown(): bool
    {
        return true;
    }

    /**
     * @return list<array{0: string, 1: list<string>}>
     */
    protected static function extraAdminPermissionActionMethods(): array
    {
        return [];
    }

    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::catalog(
            static::adminPermissionGroup(),
            static::permissionsOnDropdown(),
            static::extraAdminPermissionActionMethods(),
        );
    }
}
