<?php

namespace Modules\Chat\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * A model of an educational / work group whose files live on the private disk (GroupMedia): it
 * says who may still open them, checked again on every download.
 */
interface ProtectedMediaOwner
{
    public function protectedMediaAllows(Model $viewer): bool;
}
