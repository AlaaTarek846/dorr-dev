<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Screen permissions for the AI service (rolled out screen by screen with the in-panel guide).
 * Same rows AdminPermissionSeeder declares; added here too because re-running that seeder wipes every role.
 * The super-admin role gets them, so nobody loses access; other roles get them from the roles screen.
 */
return new class extends Migration
{
    private const GUARD = 'admin_api';

    /** Keep in sync with AdminPermissionSeeder::permissionGroups(). */
    private const GROUPS = [
        'ai-settings' => ['view', 'update'],
        'ai-plans' => ['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete'],
    ];

    public function up(): void
    {
        $categoryId = DB::table('service_categories')->where('module_name', 'ai_assistant')->value('id');
        $superAdminId = DB::table('roles')->where('name', 'super-admin')->where('guard_name', self::GUARD)->value('id');

        foreach (self::GROUPS as $group => $actions) {
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

    public function down(): void
    {
        $names = collect(self::GROUPS)->flatMap(fn ($actions, $group) => array_map(fn ($a) => "{$group}.{$a}", $actions));

        $ids = DB::table('permissions')->whereIn('name', $names)->where('guard_name', self::GUARD)->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
