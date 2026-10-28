<?php

namespace Modules\AI\Services\World\Contracts;

/** Source of places for the location search. Swapping the maps provider means binding another implementation of this. */
interface LocationProviderInterface
{
    /** Whether the provider has what it needs (for example an API key) to answer. */
    public function isConfigured(): bool;

    /**
     * @return list<array{provider_place_id: string, name: string, kind: string, country_code: ?string, city: ?string, lat: float, lng: float, formatted_address: ?string}>
     *
     * @throws \Modules\AI\Exceptions\AiWorldException location_provider_failed when the provider could not answer
     */
    public function search(string $query, string $language = 'en', int $limit = 8): array;
}
