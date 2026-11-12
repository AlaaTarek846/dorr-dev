<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Screen permissions of the Rides / Freelance screens brought up to the admin screen standard on 2026-11-11
 * (docs/screens/README.md): captain services, special offers, reported messages, checklist templates, and the
 * freelance catalogs group the skill tests screen reads. Frozen copy of the AdminPermissionSeeder rows; added here
 * too because re-running that seeder wipes every role. The super-admin role gets them, so nobody loses access.
 */
return new class extends Migration
{
    private const GUARD = 'admin_api';

    /** group => [service category module_name, actions] */
    private const GROUPS = [
        'driver-services' => ['passenger_ride', ['view', 'create', 'update', 'delete']],
        'freelance-special-offers' => ['freelance', ['view']],
        'freelance-message-reports' => ['freelance', ['view', 'update']],
        'freelance-checklist-templates' => ['freelance', ['view', 'create', 'update', 'delete']],
        'freelance-catalogs' => ['freelance', ['view', 'create', 'update', 'delete', 'multiple-delete']],
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
