<?php

namespace Tests\Support;

use Database\Seeders\Admin\AdminPermissionSeeder;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tests that exercise an admin screen's behaviour (not its permissions) act as an admin holding every permission
 * AdminPermissionSeeder declares - like super-admin. Permission rules themselves are tested by the screens' own tests.
 *
 *   Sanctum::actingAs(AdminPermissions::grant($admin), ['*'], 'admin_api');
 */
final class AdminPermissions
{
    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  T  $admin
     * @return T
     */
    public static function grant($admin)
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $names = self::all();

        foreach ($names as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }

        $admin->givePermissionTo($names);

        return $admin;
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $groups = (new ReflectionMethod(AdminPermissionSeeder::class, 'permissionGroups'))->invoke(new AdminPermissionSeeder);
        $names = [];

        foreach ($groups as $group => $definition) {
            foreach ($definition['actions'] as $action) {
                $names[] = "{$group}.{$action}";
            }
        }

        return $names;
    }
}
