<?php

namespace Modules\Driver\Models;

use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Driver\Enums\EligibilityStatus;

/** Whether a driver may work in one DORR service (rides today; deliveries or parcels later). */
class DriverServiceEligibility extends Model
{
    protected $table = 'driver_service_eligibility';

    protected $fillable = ['driver_id', 'service_category_id', 'status', 'reason', 'decided_by_admin_id', 'decided_at'];

    protected function casts(): array
    {
        return ['status' => EligibilityStatus::class, 'decided_at' => 'datetime'];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }
}
