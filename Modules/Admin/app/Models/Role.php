<?php

namespace Modules\Admin\Models;

use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

class Role extends SpatieRole
{
    use SearchFilterTrait;

    /**
     * Admin employees assigned to this role (admin_api guard).
     */
    public function admins(): MorphToMany
    {
        return $this->morphedByMany(
            Admin::class,
            'model',
            config('permission.table_names.model_has_roles'),
            app(PermissionRegistrar::class)->pivotRole,
            config('permission.column_names.model_morph_key'),
        );
    }
}
