<?php

namespace Modules\Admin\Repositories\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait ScopesAdminApiGuard
{
    protected function adminApiGuard(): string
    {
        return 'admin_api';
    }

    protected function scopeAdminApiGuard(Builder $query): Builder
    {
        return $query->where($query->getModel()->getTable().'.guard_name', $this->adminApiGuard());
    }
}
