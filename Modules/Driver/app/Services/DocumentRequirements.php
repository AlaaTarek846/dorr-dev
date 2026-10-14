<?php

namespace Modules\Driver\Services;

use App\Models\ServiceCategory;
use Illuminate\Support\Collection;
use Modules\Driver\Enums\DriverResidency;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverDocumentRequirement;
use Modules\Driver\Models\DriverDocumentType;

/**
 * Which document types a driver must have (one place for the work gate and the sign-up screens):
 * required in their country, for every service or the one asked, from both kinds or from the
 * driver's own (citizen / resident), and the type itself switched on.
 */
class DocumentRequirements
{
    /** @return Collection<int, DriverDocumentType> */
    public function for(Driver $driver, ?string $serviceModule = null): Collection
    {
        $serviceId = $serviceModule === null ? null
            : once(fn () => ServiceCategory::query()->where('module_name', $serviceModule)->value('id'));

        return DriverDocumentRequirement::query()
            ->with('documentType.translations')
            ->where('country_id', $driver->country_id)
            ->where('is_required', true)
            ->where(fn ($q) => $q->whereNull('service_category_id')->when($serviceId, fn ($q) => $q->orWhere('service_category_id', $serviceId)))
            ->where(fn ($q) => $q->whereNull('residency')->orWhere('residency', ($driver->residency ?? DriverResidency::Citizen)->value))
            ->get()
            ->map(fn (DriverDocumentRequirement $r) => $r->documentType)
            ->filter(fn (?DriverDocumentType $t) => $t !== null && $t->status)
            ->unique('id')
            ->sortBy('sort_order')
            ->values();
    }
}
