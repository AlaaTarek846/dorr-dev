<?php

namespace Database\Seeders\Admin;

use App\Models\ServiceCategory;
use Database\Seeders\Concerns\TruncatesBeforeSeeding;
use Illuminate\Database\Seeder;
use Modules\Admin\Models\Admin;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AdminPermissionSeeder extends Seeder
{
    use TruncatesBeforeSeeding;

    private const GUARD = 'admin_api';

    /**
     * Permission groups: key = group_name column; module_name links to service_categories.module_name.
     *
     * @return array<string, array{module_name: string, actions: list<string>}>
     */
    private function permissionGroups(): array
    {
        return [
            'admins' => [
                'module_name' => 'general_services',
                'actions' => [
                    'view',
                    'create',
                    'update',
                    'delete',
                    'change-status',
                    'multiple-delete',
                ],
            ],
            'flags' => [
                'module_name' => 'general_services',
                'actions' => [
                    'view',
                    'create',
                    'update',
                    'delete',
                    'change-status',
                    'multiple-delete',
                ],
            ],
            'countries' => [
                'module_name' => 'general_services',
                'actions' => [
                    'view',
                    'create',
                    'update',
                    'delete',
                    'change-status',
                    'multiple-delete',
                ],
            ],
            'languages' => [
                'module_name' => 'general_services',
                'actions' => [
                    'view',
                    'create',
                    'update',
                    'delete',
                    'change-status',
                    'multiple-delete',
                ],
            ],
            'currencies' => [
                'module_name' => 'general_services',
                'actions' => [
                    'view',
                    'create',
                    'update',
                    'delete',
                    'change-status',
                    'multiple-delete',
                ],
            ],
            'dashboard_themes' => [
                'module_name' => 'general_services',
                'actions' => [
                    'view',
                    'create',
                    'update',
                    'delete',
                    'change-status',
                    'multiple-delete',
                ],
            ],
            'platform_settings' => [
                'module_name' => 'general_services',
                'actions' => [
                    'view',
                    'update',
                ],
            ],
            'service_categories' => [
                'module_name' => 'general_services',
                'actions' => [
                    'view',
                    'create',
                    'update',
                    'delete',
                    'change-status',
                    'multiple-delete',
                ],
            ],
            'roles' => [
                'module_name' => 'general_services',
                'actions' => [
                    'view',
                    'create',
                    'update',
                    'delete',
                    'change-status',
                    'multiple-delete',
                ],
            ],
            'users' => [
                'module_name' => 'system_users',
                'actions' => [
                    'view',
                    'create',
                    'update',
                    'delete',
                    'multiple-delete',
                ],
            ],
        ];
    }

    public function run(): void
    {
        $this->truncatePermissionModels(Permission::class, Role::class);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach ($this->permissionGroups() as $groupName => $group) {
            $serviceCategoryId = $this->resolveServiceCategoryId($group['module_name']);

            foreach ($group['actions'] as $action) {
                $name = "{$groupName}.{$action}";

                Permission::query()->updateOrCreate(
                    [
                        'name' => $name,
                        'guard_name' => self::GUARD,
                    ],
                    [
                        'group_name' => $groupName,
                        'service_category_id' => $serviceCategoryId,
                    ],
                );
            }
        }

        $role = Role::query()->updateOrCreate(
            [
                'name' => 'super-admin',
                'guard_name' => self::GUARD,
            ],
        );

        $role->syncPermissions(
            Permission::query()->where('guard_name', self::GUARD)->pluck('name'),
        );

        $admin = Admin::query()->where('email', 'admin@admin.com')->first();

        if ($admin !== null) {
            $admin->syncRoles([$role]);
        }
    }

    private function resolveServiceCategoryId(string $moduleName): ?int
    {
        $id = ServiceCategory::query()
            ->where('module_name', $moduleName)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }
}
