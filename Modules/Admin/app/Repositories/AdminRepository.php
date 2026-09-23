<?php

namespace Modules\Admin\Repositories;

use App\Exceptions\ConflictException;
use App\Models\ServiceCategory;
use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Models\Admin;
use Modules\Admin\Models\Role;
use Modules\Admin\Repositories\Concerns\ResolvesAuthenticatedAdmin;
use Modules\Admin\Repositories\Concerns\ScopesAdminApiGuard;
use Spatie\Permission\PermissionRegistrar;

class AdminRepository extends BaseRepository
{
    use ResolvesAuthenticatedAdmin, ScopesAdminApiGuard;

    protected array $with = [
        'country.flag',
        'services.category.translations',
        'services.category.translation',
        'roles',
    ];

    protected array $orderBy = [
        'id' => 'desc',
    ];

    public function __construct(Admin $model)
    {
        $this->model = $model;
    }

    public function query(): Builder
    {
        return $this->applyEmployeeVisibilityScope($this->model->newQuery());
    }

    protected function buildIndexQuery(): Builder
    {
        if ($this->shouldListOnlyTrashed()) {
            return $this->applyEmployeeVisibilityScope(parent::buildIndexQuery()->onlyTrashed());
        }

        return $this->applyEmployeeVisibilityScope(parent::buildIndexQuery());
    }

    protected function applyEmployeeVisibilityScope(Builder $query): Builder
    {
        $authAdmin = $this->authenticatedAdmin();

        if ($authAdmin !== null) {
            $query->where($query->getModel()->getTable().'.id', '!=', $authAdmin->id);
        }

        if (! $this->authenticatedAdminIsSuperAdmin()) {
            $query->whereDoesntHave('roles', function (Builder $roleQuery): void {
                $roleQuery
                    ->where('name', $this->superAdminRoleName())
                    ->where('guard_name', $this->adminApiGuard());
            });
        }

        return $query;
    }

    public function changeStatus(int|string $id, bool $status): Admin
    {
        $admin = $this->query()->findOrFail($id);
        $admin->update(['status' => $status]);

        return $this->refresh($admin);
    }

    /**
     * @return list<string>
     */
    protected function reservedPayloadKeys(): array
    {
        return array_merge(parent::reservedPayloadKeys(), [
            'service_category_ids',
            'role_id',
            'remove_avatar',
        ]);
    }

    protected function afterStore(Model $model, array $data): void
    {
        parent::afterStore($model, $data);

        if (array_key_exists('service_category_ids', $data)) {
            $this->syncServiceCategories($model, $data['service_category_ids'] ?? []);
        }

        if (array_key_exists('role_id', $data)) {
            $this->syncRole($model, $data['role_id']);
        }
    }

    protected function afterUpdate(Model $model, array $data): void
    {
        parent::afterUpdate($model, $data);

        if (array_key_exists('service_category_ids', $data)) {
            $this->syncServiceCategories($model, $data['service_category_ids'] ?? []);
        }

        if (array_key_exists('role_id', $data)) {
            $this->syncRole($model, $data['role_id']);
        }

        if (! empty($data['remove_avatar']) && method_exists($model, 'deleteSingleMedia')) {
            $model->deleteSingleMedia('avatar');
        }
    }

    /**
     * @param  list<int|string>|null  $categoryIds
     */
    protected function syncServiceCategories(Admin $admin, ?array $categoryIds): void
    {
        $categoryIds = collect($categoryIds ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($categoryIds === []) {
            $admin->services()->delete();

            return;
        }

        $leafIds = ServiceCategory::query()
            ->whereIn('id', $categoryIds)
            ->whereDoesntHave('children')
            ->where('status', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $admin->services()->whereNotIn('service_category_id', $leafIds)->delete();

        $existing = $admin->services()->pluck('service_category_id')->map(fn ($id) => (int) $id)->all();

        foreach ($leafIds as $categoryId) {
            if (! in_array($categoryId, $existing, true)) {
                $admin->services()->create(['service_category_id' => $categoryId]);
            }
        }
    }

    protected function syncRole(Admin $admin, int|string|null $roleId): void
    {
        if ($roleId === null || $roleId === '') {
            $admin->syncRoles([]);

            return;
        }

        $role = Role::query()
            ->where('guard_name', $this->adminApiGuard())
            ->findOrFail($roleId);

        if (
            $role->name === $this->superAdminRoleName()
            && ! $this->authenticatedAdminIsSuperAdmin()
        ) {
            throw new ConflictException(
                __('api.unauthorized'),
                403,
                null,
                'cannot_assign_super_admin_role',
            );
        }

        $admin->syncRoles([$role]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
