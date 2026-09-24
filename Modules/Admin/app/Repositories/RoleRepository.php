<?php

namespace Modules\Admin\Repositories;

use App\Exceptions\ConflictException;
use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Models\Role;
use Modules\Admin\Repositories\Concerns\ResolvesAuthenticatedAdmin;
use Modules\Admin\Repositories\Concerns\ScopesAdminApiGuard;
use Spatie\Permission\PermissionRegistrar;

class RoleRepository extends BaseRepository
{
    use ResolvesAuthenticatedAdmin, ScopesAdminApiGuard;

    protected array $with = ['permissions'];

    protected array $withCount = [
        'permissions',
        'admins',
    ];

    protected array $orderBy = [
        'name' => 'asc',
    ];

    public function __construct(Role $model)
    {
        $this->model = $model;
    }

    public function query(): Builder
    {
        return $this->applyRoleVisibilityScope(
            $this->scopeAdminApiGuard($this->model->newQuery()),
        );
    }

    protected function buildIndexQuery(): Builder
    {
        if ($this->shouldListOnlyTrashed()) {
            return $this->query()->onlyTrashed();
        }

        return $this->query();
    }

    protected function applyRoleVisibilityScope(Builder $query): Builder
    {
        if ($this->authenticatedAdminIsSuperAdmin()) {
            return $query;
        }

        return $query->where('name', '!=', $this->superAdminRoleName());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function prepareData(array $data): array
    {
        return [
            'name' => $data['name'],
            'guard_name' => $this->adminApiGuard(),
        ];
    }

    protected function afterStore(Model $model, array $data): void
    {
        $this->syncRolePermissions($model, $data['permission_names'] ?? null);
        $this->forgetPermissionCache();
    }

    protected function afterUpdate(Model $model, array $data): void
    {
        $this->syncRolePermissions($model, $data['permission_names'] ?? null);
        $this->forgetPermissionCache();
    }

    protected function beforeDestroy(Model $model): void
    {
        if ($model->name === 'super-admin' && $model->guard_name === $this->adminApiGuard()) {
            throw new ConflictException(
                __('api.cannot_delete_role'),
                409,
                null,
                'cannot_delete_role',
            );
        }
    }

    /**
     * @param  list<string>|null  $permissionNames
     */
    protected function syncRolePermissions(Model $model, ?array $permissionNames): void
    {
        if ($permissionNames === null) {
            return;
        }

        /** @var Role $model */
        $model->syncPermissions($permissionNames);
    }

    protected function forgetPermissionCache(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
