<?php

namespace Modules\Rides\Services\Policy;

use App\Enums\LegalPageType;
use App\Models\ServiceCategory;
use App\Repositories\General\LegalPageRepository;

/**
 * The terms in force before booking a ride (rides_tasks R-179): for each kind (terms, privacy) the
 * rides service's own published page, else the general one — what the rider reads before confirming.
 * The apps open each with `GET mobile/v1/legal-pages?type=&service_id=&fallback=1`.
 */
class RideTerms
{
    public function __construct(private readonly LegalPageRepository $pages) {}

    /** @return list<array{type: string, title: string, service_id: ?int, scope: string, updated_at: ?string}> */
    public function current(): array
    {
        $serviceId = ServiceCategory::query()->where('module_name', config('rides.service_module_name', 'passenger_ride'))->value('id');
        $locale = app()->getLocale();
        $terms = [];

        foreach ([LegalPageType::Term, LegalPageType::Privacy] as $type) {
            $page = $this->pages->effectiveForType($type->value, $serviceId !== null ? (int) $serviceId : null);
            if ($page === null) {
                continue;
            }
            $terms[] = [
                'type' => $type->value,
                'title' => $locale === 'ar' ? $type->labelAr() : $type->label(),
                // Which page to open: the service's own, or the general one it falls back to.
                'service_id' => $page->service_id,
                'scope' => $page->service_id !== null ? 'service' : 'general',
                'updated_at' => $page->updated_at?->toIso8601String(),
            ];
        }

        return $terms;
    }
}
