<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * The dashboard's Watch & Earn permissions (same list as AdminPermissionSeeder), added on deploy without re-running
 * that seeder — it rebuilds every permission and role, and resets the default admin. Only adds what's missing and
 * gives it to the super-admin role; nothing else is touched.
 */
return new class extends Migration
{
    private const GUARD = 'admin_api';

    private const PERMISSIONS = [
        'watch-earn-settings' => ['view', 'update'],
        'watch-earn-ads' => ['view', 'update'],
        'watch-earn-finance' => ['view'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $category = Schema::hasTable('service_categories')
            ? DB::table('service_categories')->where('module_name', 'general_services')->value('id')
            : null;
        $hasCategory = Schema::hasColumn('permissions', 'service_category_id');
        $hasGroup = Schema::hasColumn('permissions', 'group_name');

        $ids = [];
        foreach (self::PERMISSIONS as $group => $actions) {
            foreach ($actions as $action) {
                $name = "{$group}.{$action}";
                $id = DB::table('permissions')->where('name', $name)->where('guard_name', self::GUARD)->value('id');
                if ($id === null) {
                    $row = ['name' => $name, 'guard_name' => self::GUARD, 'created_at' => now(), 'updated_at' => now()];
                    if ($hasGroup) {
                        $row['group_name'] = $group;
                    }
                    if ($hasCategory) {
                        $row['service_category_id'] = $category;
                    }
                    $id = DB::table('permissions')->insertGetId($row);
                }
                $ids[] = $id;
            }
        }

        $role = DB::table('roles')->where('name', 'super-admin')->where('guard_name', self::GUARD)->value('id');
        if ($role !== null) {
            foreach ($ids as $id) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $id, 'role_id' => $role]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $names = [];
        foreach (self::PERMISSIONS as $group => $actions) {
            foreach ($actions as $action) {
                $names[] = "{$group}.{$action}";
            }
        }
        $ids = DB::table('permissions')->whereIn('name', $names)->where('guard_name', self::GUARD)->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
