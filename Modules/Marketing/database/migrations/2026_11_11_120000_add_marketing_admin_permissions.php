<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Admin permissions of the AI Marketing Director screens (MK-A15): the switches per country, the packages and the
 * operations lists. Frozen copy of the AdminPermissionSeeder rows; added here too because re-running that seeder wipes
 * every role. The super-admin role gets them, so the new screens are reachable at once.
 */
return new class extends Migration
{
    private const GUARD = 'admin_api';

    /** group => [service category module_name, actions] (same as AdminPermissionSeeder) */
    private const GROUPS = [
        'marketing-feature-rules' => ['general_services', ['view', 'update']],
        'marketing-packages' => ['general_services', ['view', 'create', 'update', 'delete']],
        'marketing-operations' => ['general_services', ['view']],
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

    /** The rows may predate this migration (AdminPermissionSeeder), so rolling back leaves them and their grants alone. */
    public function down(): void {}
};
