<?php

namespace Tests\Support;

use Modules\Admin\Models\Admin;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * A test admin who may open every dashboard page — like the super-admin, who holds every permission
 * (the permissions sync migration grants them all). For tests about a page's behaviour, not its permission.
 */
final class AdminAccess
{
    public static function full(Admin $admin): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin->givePermissionTo(Permission::query()->where('guard_name', 'admin_api')->get());

        return $admin;
    }
}
