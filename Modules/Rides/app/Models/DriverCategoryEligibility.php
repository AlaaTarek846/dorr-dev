<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;

/** Whether a driver may take one ride category (computed; the admin can override). */
class DriverCategoryEligibility extends Model
{
    protected $table = 'driver_category_eligibility';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['admin_override' => 'boolean', 'checked_at' => 'datetime'];
    }
}
