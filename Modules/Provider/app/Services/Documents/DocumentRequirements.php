<?php

namespace Modules\Provider\Services\Documents;

use Illuminate\Support\Collection;
use Modules\Provider\Enums\DocumentStatus;
use Modules\Provider\Enums\DocumentSubject;
use Modules\Provider\Models\DocumentRequirement;
use Modules\Provider\Models\DocumentType;
use Modules\Provider\Models\Establishment;
use Modules\Provider\Models\ProviderDocument;

/**
 * Which papers and photos an establishment, one of its vehicles or one of its drivers must have, from what the admin
 * set for the country, the services it offers, the vehicle type and the establishment type. The photo showing the
 * vehicle with its plate is always asked (owner's call), even if the admin forgot to list it.
 */
class DocumentRequirements
{
    /** @return Collection<int, DocumentType> */
    public function for(Establishment $establishment, DocumentSubject $subject, ?int $vehicleTypeId = null): Collection
    {
        $serviceIds = $establishment->services()->wherePivot('status', true)->pluck('service_categories.id')->all();

        $typeIds = DocumentRequirement::query()
            ->where('country_id', $establishment->country_id)
            ->where('is_required', true)
            ->where(fn ($q) => $q->whereNull('service_category_id')->orWhereIn('service_category_id', $serviceIds))
            ->where(fn ($q) => $q->whereNull('establishment_type')->orWhere('establishment_type', $establishment->type->value))
            ->where(fn ($q) => $q->whereNull('vehicle_type_id')->when($vehicleTypeId, fn ($q) => $q->orWhere('vehicle_type_id', $vehicleTypeId)))
            ->pluck('document_type_id');

        $query = DocumentType::query()->with('translations')
            ->where('status', true)
            ->where('subject', $subject->value)
            ->where(function ($q) use ($typeIds, $subject) {
                $q->whereIn('id', $typeIds);
                if ($subject === DocumentSubject::Vehicle) {
                    $q->orWhere('code', DocumentType::PLATE_PHOTO);
                }
            })
            ->orderBy('sort_order');

        return $query->get();
    }

    /**
     * Required types with no approved (and unexpired) or pending upload yet.
     *
     * @return list<string> codes
     */
    public function missing(Establishment $establishment, DocumentSubject $subject, int $subjectId, ?int $vehicleTypeId = null): array
    {
        $have = $this->latest($subject, $subjectId)
            ->filter(fn (ProviderDocument $d) => $d->status === DocumentStatus::Pending || $d->isValid())
            ->pluck('document_type_id')->all();

        return $this->for($establishment, $subject, $vehicleTypeId)
            ->reject(fn (DocumentType $t) => in_array($t->id, $have, true))
            ->pluck('code')->values()->all();
    }

    /**
     * Required types that are not approved and valid right now — what blocks work.
     *
     * @return list<string> codes
     */
    public function notValid(Establishment $establishment, DocumentSubject $subject, int $subjectId, ?int $vehicleTypeId = null): array
    {
        $valid = $this->latest($subject, $subjectId)->filter(fn (ProviderDocument $d) => $d->isValid())
            ->pluck('document_type_id')->all();

        return $this->for($establishment, $subject, $vehicleTypeId)
            ->reject(fn (DocumentType $t) => in_array($t->id, $valid, true))
            ->pluck('code')->values()->all();
    }

    /** @return Collection<int, ProviderDocument> the current upload of each type (newest first, replaced ones left out) */
    public function latest(DocumentSubject $subject, int $subjectId): Collection
    {
        return ProviderDocument::query()
            ->where('subject_type', $subject->storedAs())
            ->where('subject_id', $subjectId)
            ->where('status', '!=', DocumentStatus::Replaced->value)
            ->orderByDesc('id')
            ->get()
            ->unique('document_type_id')
            ->values();
    }
}
