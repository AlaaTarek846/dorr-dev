<?php

namespace Modules\Admin\Models;

use App\Models\ServiceCategory;
use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    use SearchFilterTrait;

    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }
}
