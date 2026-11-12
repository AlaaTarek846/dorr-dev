<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * FS-605: the permission of the freelance quality desk (verified works, practical challenges, reputation corrections).
 * Frozen copy of the AdminPermissionSeeder row; added here too because re-running that seeder wipes every role. The
 * super-admin role gets it, so nobody loses access.
 */
return new class extends Migration
{
    private const GUARD = 'admin_api';

    /** group => [service category module_name, actions] */
    private const GROUPS = [
        'freelance-quality' => ['freelance', ['view', 'update']],
    ];

    public function up(): void
    {
        $superAdminId = DB::table('roles')->where('name', 'super-admin')->where('guard_name', self::GUARD)->value('id');

        foreach (self::GROUPS as $group => [$module, $actions]) {
            $categoryId = DB::table('service_categories')->where('module_name', $module)->value('id');

            foreach ($actions as $action) {
                $name = "{$group}.{$action}";
                $id = DB::table('permissions')->where('name', $name)->where('guard_name', self::GUARD)->value('id');

                if ($id === null) {
                    $id = DB::table('permissions')->insertGetId([
                        'name' => $name,
                        'guard_name' => self::GUARD,
                        'group_name' => $group,
                        'service_category_id' => $categoryId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                if ($superAdminId !== null) {
                    DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $id, 'role_id' => $superAdminId]);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** The rows usually predate this migration (AdminPermissionSeeder), so rolling back leaves them and their grants alone. */
    public function down(): void
    {
    }
};
