<?php

namespace Modules\Freelance\Services\Verification;

use Illuminate\Support\Collection;
use Modules\Freelance\Models\FreelanceDocumentRequirement;
use Modules\Freelance\Models\FreelanceDocumentType;
use Modules\Freelance\Models\Freelancer;

/**
 * Which identity documents a freelancer must upload (FL-107): what the admin made required in their country — for
 * everyone, or for their residency (citizen / resident) — of the types still switched on. One place for the request,
 * the admin's check and the expiry sweep.
 */
class FreelanceDocumentRequirements
{
    /** @return Collection<int, FreelanceDocumentType> */
    public function for(Freelancer $freelancer): Collection
    {
        return FreelanceDocumentRequirement::query()
            ->with('documentType.translations')
            ->where('country_id', $freelancer->country_id)
            ->where('is_required', true)
            ->where(fn ($q) => $q->whereNull('residency')->when($freelancer->residency !== null, fn ($q) => $q->orWhere('residency', $freelancer->residency)))
            ->get()
            ->map(fn (FreelanceDocumentRequirement $r) => $r->documentType)
            ->filter(fn (?FreelanceDocumentType $t) => $t !== null && $t->status)
            ->unique('id')
            ->sortBy('sort_order')
            ->values();
    }

    /** The country asks something only of citizens or of residents, and the freelancer hasn't said which they are. */
    public function needsResidency(Freelancer $freelancer): bool
    {
        return $freelancer->residency === null
            && FreelanceDocumentRequirement::query()->where('country_id', $freelancer->country_id)->where('is_required', true)->whereNotNull('residency')->exists();
    }
}
