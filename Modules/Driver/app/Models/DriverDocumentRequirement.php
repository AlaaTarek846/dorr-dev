<?php

namespace Modules\Driver\Models;

use App\Models\Country;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Driver\Enums\DriverResidency;

/**
 * A document type required in a country — for one DORR service, or for every one (null service) —
 * from citizens, from residents, or from both (null residency).
 */
class DriverDocumentRequirement extends Model
{
    protected $fillable = ['document_type_id', 'country_id', 'service_category_id', 'residency', 'is_required'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'residency' => DriverResidency::class];
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DriverDocumentType::class, 'document_type_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }
}
