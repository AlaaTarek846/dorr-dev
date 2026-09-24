<?php

namespace Modules\Admin\Repositories;

use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Models\Permission;
use Modules\Admin\Repositories\Concerns\ScopesAdminApiGuard;
use Spatie\Permission\PermissionRegistrar;

class PermissionRepository extends BaseRepository
{
    use ScopesAdminApiGuard;

    protected array $with = ['serviceCategory.translation'];

    protected array $orderBy = [
        'group_name' => 'asc',
        'name' => 'asc',
    ];

    public function __construct(Permission $model)
    {
        $this->model = $model;
    }

    protected function buildIndexQuery(): Builder
    {
        return $this->scopeAdminApiGuard(parent::buildIndexQuery());
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
            'group_name' => $data['group_name'] ?? null,
            'service_category_id' => $data['service_category_id'] ?? null,
        ];
    }

    protected function afterStore(Model $model, array $data): void
    {
        $this->forgetPermissionCache();
    }

    protected function afterUpdate(Model $model, array $data): void
    {
        $this->forgetPermissionCache();
    }

    protected function beforeDestroy(Model $model): void
    {
        $this->forgetPermissionCache();
    }

    protected function forgetPermissionCache(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
