<?php

namespace Modules\Admin\Repositories\Concerns;

use Modules\Admin\Models\Admin;

trait ResolvesAuthenticatedAdmin
{
    protected function authenticatedAdmin(): ?Admin
    {
        $user = request()->user('admin_api');

        return $user instanceof Admin ? $user : null;
    }

    protected function authenticatedAdminIsSuperAdmin(): bool
    {
        $admin = $this->authenticatedAdmin();

        return $admin !== null
            && $admin->hasRole($this->superAdminRoleName(), $this->adminApiGuard());
    }

    protected function superAdminRoleName(): string
    {
        return 'super-admin';
    }
}
