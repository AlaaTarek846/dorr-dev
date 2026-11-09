<?php

use App\Enums\ServiceAudience;
use App\Models\ServiceCategory;
use Database\Seeders\Admin\AdminPermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Admin\Models\Admin;
use Spatie\Permission\PermissionRegistrar;

/**
 * Brings the dashboard's permissions on a live server in line with AdminPermissionSeeder without re-running it (it
 * empties every role): every permission it lists that is missing is added, each one's group and service follow the
 * seeder, and the super-admin role gets them all. Nothing a person set on another role is touched.
 *
 * With it: Watch & Earn becomes its own service in the dashboard's header (its pages showed under every service),
 * the AI pages, the providers list and the permissions list get permissions of their own.
 */
return new class extends Migration
{
    private const GUARD = 'admin_api';

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('service_categories')) {
            return;
        }

        $watchEarn = $this->watchEarnService();

        $categories = DB::table('service_categories')->whereNotNull('module_name')->pluck('id', 'module_name');
        $ids = [];
        foreach (AdminPermissionSeeder::permissionGroups() as $group => $definition) {
            $categoryId = $categories[$definition['module_name']] ?? null;
            foreach ($definition['actions'] as $action) {
                $name = "{$group}.{$action}";
                $id = DB::table('permissions')->where('name', $name)->where('guard_name', self::GUARD)->value('id');
                $values = ['group_name' => $group, 'service_category_id' => $categoryId, 'updated_at' => now()];
                if ($id === null) {
                    $id = DB::table('permissions')->insertGetId(['name' => $name, 'guard_name' => self::GUARD, 'created_at' => now(), ...$values]);
                } else {
                    DB::table('permissions')->where('id', $id)->update($values);
                }
                $ids[] = $id;
            }
        }

        $superAdmin = DB::table('roles')->where('name', 'super-admin')->where('guard_name', self::GUARD)->value('id');
        if ($superAdmin !== null) {
            DB::table('role_has_permissions')->insertOrIgnore(array_map(fn ($id) => ['permission_id' => $id, 'role_id' => $superAdmin], $ids));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Whoever could open Watch & Earn before keeps it: the new service goes to them (and to every super-admin).
        $weNames = ['watch-earn-settings.view', 'watch-earn-ads.view', 'watch-earn-finance.view'];
        Admin::query()->each(function (Admin $admin) use ($watchEarn, $weNames): void {
            if ($admin->hasRole('super-admin', self::GUARD) || $admin->hasAnyPermission($weNames)) {
                $admin->services()->firstOrCreate(['service_category_id' => $watchEarn->id]);
            }
        });
    }

    public function down(): void
    {
        // Permissions and services added here stay: removing them could take a page away from someone who uses it.
    }

    private function watchEarnService(): ServiceCategory
    {
        $category = ServiceCategory::withTrashed()->where('module_name', 'watch_earn')->first() ?? new ServiceCategory;
        $audiences = [ServiceAudience::Admin->value];
        $legacy = ServiceAudience::legacyFlagsFromAudiences($audiences);
        $category->fill([
            'module_name' => 'watch_earn',
            'audiences' => $audiences,
            'is_login_dashboard' => $legacy['is_login_dashboard'],
            'is_auto_assign' => false,
            'requires_provider' => false,
            'status' => true,
            'sort_order' => $category->exists ? $category->sort_order : ((int) ServiceCategory::withTrashed()->max('sort_order')) + 1,
        ]);
        if ($category->trashed()) {
            $category->restore();
        }
        $category->save();
        foreach (['ar' => 'شاهد واكسب', 'en' => 'Watch & Earn'] as $locale => $name) {
            $category->translations()->updateOrCreate(['locale' => $locale], ['name' => $name]);
        }

        return $category;
    }
};
