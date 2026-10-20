<?php

namespace Modules\AI\Services\World\Location;

use Illuminate\Support\Facades\Http;
use Modules\AI\Exceptions\AiWorldException;
use Modules\AI\Models\World\AiWorldLocation;
use Modules\AI\Services\World\Contracts\LocationProviderInterface;
use Throwable;

/** Google Places API (New) Text Search. The key comes from config('ai.world.google_maps_key'), never from the app. */
class GooglePlacesProvider implements LocationProviderInterface
{
    protected const ENDPOINT = 'https://places.googleapis.com/v1/places:searchText';

    protected const FIELDS = 'places.id,places.displayName,places.formattedAddress,places.location,places.types,places.addressComponents';

    public function isConfigured(): bool
    {
        return (string) config('ai.world.google_maps_key') !== '';
    }

    public function search(string $query, string $language = 'en', int $limit = 8): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        try {
            $response = Http::timeout((int) config('ai.world.places_timeout', 10))
                ->withHeaders([
                    'X-Goog-Api-Key' => (string) config('ai.world.google_maps_key'),
                    'X-Goog-FieldMask' => self::FIELDS,
                ])
                ->post(self::ENDPOINT, [
                    'textQuery' => $query,
                    'languageCode' => $language,
                    'pageSize' => max(1, min(20, $limit)),
                ]);
        } catch (Throwable $e) {
            report($e);

            throw new AiWorldException('location_provider_failed', 503);
        }

        if (! $response->successful()) {
            // The Google error text can hold project details, so only the status is logged.
            logger()->warning('ai.world.places_failed', ['status' => $response->status()]);

            throw new AiWorldException('location_provider_failed', 503);
        }

        $results = [];

        foreach ((array) $response->json('places', []) as $place) {
            $lat = data_get($place, 'location.latitude');
            $lng = data_get($place, 'location.longitude');
            $id = (string) data_get($place, 'id', '');

            if ($id === '' || ! is_numeric($lat) || ! is_numeric($lng)) {
                continue;
            }

            $results[] = [
                'provider_place_id' => $id,
                'name' => (string) data_get($place, 'displayName.text', ''),
                'kind' => $this->kindFor((array) data_get($place, 'types', [])),
                'country_code' => $this->component($place, 'country', true),
                'city' => $this->component($place, 'locality', false),
                'lat' => (float) $lat,
                'lng' => (float) $lng,
                'formatted_address' => data_get($place, 'formattedAddress'),
            ];
        }

        return $results;
    }

    /** @param  list<string>  $types */
    protected function kindFor(array $types): string
    {
        return match (true) {
            in_array('country', $types, true) => AiWorldLocation::KIND_COUNTRY,
            in_array('locality', $types, true), in_array('administrative_area_level_1', $types, true) => AiWorldLocation::KIND_CITY,
            in_array('sublocality', $types, true), in_array('sublocality_level_1', $types, true), in_array('neighborhood', $types, true) => AiWorldLocation::KIND_DISTRICT,
            in_array('route', $types, true), in_array('street_address', $types, true) => AiWorldLocation::KIND_STREET,
            default => AiWorldLocation::KIND_LANDMARK,
        };
    }

    protected function component(array $place, string $type, bool $short): ?string
    {
        foreach ((array) data_get($place, 'addressComponents', []) as $component) {
            if (in_array($type, (array) ($component['types'] ?? []), true)) {
                $value = $short ? ($component['shortText'] ?? null) : ($component['longText'] ?? null);

                return is_string($value) && $value !== '' ? $value : null;
            }
        }

        return null;
    }
}
