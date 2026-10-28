<?php

namespace Modules\AI\Services\World\Location;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\AI\Exceptions\AiWorldException;
use Modules\AI\Models\World\AiWorldLocation;
use Modules\AI\Services\World\Contracts\LocationProviderInterface;

/**
 * Place search: the built-in landmark catalog first (free, offline), then the maps provider, whose answers are
 * cached both as place rows and as per-query results, so the same search is never paid for twice.
 */
class AiWorldLocationEngine
{
    public function __construct(
        protected LocationProviderInterface $provider,
        protected AiWorldFidelityClassifier $classifier,
    ) {}

    /**
     * @return Collection<int, AiWorldLocation>
     *
     * @throws AiWorldException location_query_short | location_provider_failed (only when nothing local matched either)
     */
    public function search(string $query, string $language = 'en', int $limit = 8): Collection
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            throw new AiWorldException('location_query_short', 422);
        }

        $limit = max(1, min(20, $limit));
        $this->ensureCatalog();

        $needle = AiWorldLocation::normalize($query);
        $like = '%'.addcslashes($needle, '%_\\').'%';

        $found = AiWorldLocation::query()
            ->where('search_text', 'like', $like)
            ->orderByDesc('is_curated')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($found->count() >= $limit || ! $this->provider->isConfigured()) {
            return $found;
        }

        try {
            $remote = $this->remote($query, $needle, $language, $limit);
        } catch (AiWorldException $e) {
            if ($found->isEmpty()) {
                throw $e;
            }

            return $found;
        }

        $merged = $found->keyBy('id');

        foreach ($remote as $row) {
            $location = $this->store($row, $language);
            $merged->put($location->id, $location);
        }

        return $merged->values()->take($limit);
    }

    /** @return list<array<string, mixed>> */
    protected function remote(string $query, string $needle, string $language, int $limit): array
    {
        $key = 'ai.world.places.'.sha1($language.'|'.$needle.'|'.$limit);

        return Cache::remember($key, (int) config('ai.world.search_cache_ttl', 86400), fn () => $this->provider->search($query, $language, $limit));
    }

    /** @param  array<string, mixed>  $row */
    protected function store(array $row, string $language): AiWorldLocation
    {
        $location = AiWorldLocation::query()->firstOrNew(['source' => 'google', 'provider_place_id' => $row['provider_place_id']]);

        $location->fill([
            'name_en' => $row['name'],
            'name_ar' => $language === 'ar' ? $row['name'] : $location->name_ar,
            'kind' => $row['kind'],
            'country_code' => isset($row['country_code']) ? strtoupper(substr((string) $row['country_code'], 0, 2)) : null,
            'city' => $row['city'] ?? null,
            'lat' => $row['lat'],
            'lng' => $row['lng'],
            'formatted_address' => $row['formatted_address'] ?? null,
            'search_text' => AiWorldLocation::normalize(implode(' ', array_filter([$row['name'], $row['city'] ?? null, $row['formatted_address'] ?? null, $location->name_ar]))),
            'cached_at' => now(),
        ]);

        // A new row has no DB defaults loaded yet, so state the flags explicitly rather than leave them null.
        $location->is_curated = (bool) $location->is_curated;
        $location->tiles_available = (bool) $location->tiles_available;

        $this->classifier->apply($location)->save();

        return $location;
    }

    /** Inserts the landmark catalog the first time it is needed. */
    public function ensureCatalog(): void
    {
        if (AiWorldLocation::query()->where('is_curated', true)->exists()) {
            return;
        }

        foreach (AiWorldLandmarkCatalog::LANDMARKS as [$slug, $en, $ar, $country, $city, $lat, $lng]) {
            $location = new AiWorldLocation([
                'source' => 'curated',
                'provider_place_id' => $slug,
                'name_en' => $en,
                'name_ar' => $ar,
                'kind' => AiWorldLocation::KIND_LANDMARK,
                'country_code' => $country,
                'city' => $city,
                'lat' => $lat,
                'lng' => $lng,
                'formatted_address' => $city.', '.$country,
                'search_text' => AiWorldLocation::normalize($en.' '.$ar.' '.$city.' '.$country),
                'is_curated' => true,
            ]);

            $this->classifier->apply($location)->save();
        }
    }
}
