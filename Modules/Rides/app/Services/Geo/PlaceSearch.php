<?php

namespace Modules\Rides\Services\Geo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Models\RideSetting;
use Modules\Rides\Support\Geo;
use Throwable;

/**
 * Finding a place for the pickup, the destination or a stop (docs/rides_tasks.md O-57) — the phone's own
 * geocoder knew little and nothing of half-typed words. With the admin's provider:
 *
 *  - suggest: as the rider types (from two letters, «الدق…»), places near them first — Mapbox's geocoder
 *    with autocomplete and points of interest, or Google's geocoder biased to the area around them;
 *  - popular: before anything is typed, where people around them go most (completed rides' destinations
 *    within 50 km), topped up with well-known places (malls, the airport, hospitals…) from Mapbox.
 *
 * Every answer is [{name, address, lat, lng}]. Nothing here blocks: a provider that fails gives an empty list.
 */
class PlaceSearch
{
    private const RADIUS_M = 50000;

    /** «Nearby places» (the chat's location page): how far around the point. */
    private const NEARBY_M = 500;

    /** @return list<array{name: string, address: string, lat: float, lng: float}> */
    public function suggest(string $query, ?float $lat, ?float $lng, string $lang = 'ar'): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $settings = RideSetting::current();
        $key = 'rides:places:'.md5($settings->mapProvider().'|'.$query.'|'.round((float) $lat, 2).','.round((float) $lng, 2).'|'.$lang);

        return Cache::remember($key, now()->addHours(6), function () use ($settings, $query, $lat, $lng, $lang) {
            $found = $settings->mapProvider() === 'mapbox'
                ? $this->mapbox($settings->mapboxToken(), $query, $lat, $lng, $lang)
                : $this->google($settings->googleKey(), $query, $lat, $lng, $lang);
            if ($lat !== null && $lng !== null) {
                usort($found, fn (array $a, array $b) => Geo::metres($lat, $lng, $a['lat'], $a['lng']) <=> Geo::metres($lat, $lng, $b['lat'], $b['lng']));
            }

            return array_slice($found, 0, 8);
        });
    }

    /** @return list<array{name: string, address: string, lat: float, lng: float}> */
    public function popular(float $lat, float $lng, string $lang = 'ar'): array
    {
        $key = 'rides:places:popular:'.round($lat, 1).','.round($lng, 1).'|'.$lang;

        return Cache::remember($key, now()->addHour(), function () use ($lat, $lng, $lang) {
            [$minLat, $maxLat, $minLng, $maxLng] = Geo::boxAround($lat, $lng, self::RADIUS_M);
            // Where people around here went most (three months), one row per place (rounded to ~100 m).
            $rides = DB::table('rides')->where('status', RideStatus::Completed->value)
                ->where('completed_at', '>=', now()->subDays(90))
                ->whereNotNull('destination_address')
                ->whereBetween('destination_lat', [$minLat, $maxLat])->whereBetween('destination_lng', [$minLng, $maxLng])
                ->selectRaw('destination_address as address, AVG(destination_lat) as lat, AVG(destination_lng) as lng, COUNT(*) as trips')
                ->groupByRaw('destination_address, ROUND(destination_lat, 3), ROUND(destination_lng, 3)')
                ->orderByDesc('trips')->limit(8)->get()
                ->map(fn ($r) => ['name' => self::short((string) $r->address), 'address' => (string) $r->address, 'lat' => (float) $r->lat, 'lng' => (float) $r->lng])
                ->all();
            if (count($rides) >= 6) {
                return $rides;
            }
            $token = RideSetting::current()->mapboxToken();
            $known = $token === '' ? [] : $this->landmarks($token, $lat, $lng, $lang);

            return array_slice([...$rides, ...$known], 0, 10);
        });
    }

    /**
     * Places right around a point — the chat's «send a location» (like WhatsApp's «nearby places»). The admin's
     * provider first: Google's Places «nearby» (nearest first) or Mapbox's points of interest. When that gives
     * few (no key, a refusal, a quiet street), where people around there go most (rides within 5 km) tops it up.
     * Nearest first, at most 15, one per name. Kept a day per ~100 m; an empty answer is asked again in 10 minutes.
     *
     * @return list<array{name: string, address: string, lat: float, lng: float}>
     */
    public function nearby(float $lat, float $lng, string $lang = 'ar'): array
    {
        $settings = RideSetting::current();
        $key = 'rides:places:nearby:'.$settings->mapProvider().'|'.round($lat, 3).','.round($lng, 3).'|'.$lang;
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $found = $settings->mapProvider() === 'mapbox'
            ? $this->mapboxNearby($settings->mapboxToken(), $lat, $lng, $lang)
            : $this->googleNearby($settings->googleKey(), $lat, $lng, $lang);
        if (count($found) < 5) {
            $close = array_filter($this->popular($lat, $lng, $lang), fn (array $p) => Geo::metres($lat, $lng, $p['lat'], $p['lng']) <= 5000);
            $found = [...$found, ...$close];
        }
        $seen = [];
        $found = array_values(array_filter($found, function (array $p) use (&$seen) {
            $name = mb_strtolower(trim($p['name']));
            if ($name === '' || isset($seen[$name])) {
                return false;
            }

            return $seen[$name] = true;
        }));
        usort($found, fn (array $a, array $b) => Geo::metres($lat, $lng, $a['lat'], $a['lng']) <=> Geo::metres($lat, $lng, $b['lat'], $b['lng']));
        $found = array_slice($found, 0, 15);
        Cache::put($key, $found, $found === [] ? now()->addMinutes(10) : now()->addDay());

        return $found;
    }

    /**
     * Google Places (New) «Nearby Search», within 500 m, by distance — only the fields shown (name, short address,
     * place), so it bills as little as it can.
     *
     * @return list<array{name: string, address: string, lat: float, lng: float}>
     */
    private function googleNearby(string $key, float $lat, float $lng, string $lang): array
    {
        if ($key === '') {
            return [];
        }
        try {
            $response = Http::timeout(6)->withHeaders([
                'X-Goog-Api-Key' => $key,
                'X-Goog-FieldMask' => 'places.displayName,places.shortFormattedAddress,places.formattedAddress,places.location',
            ])->post((string) config('rides.google.nearby_url'), [
                'maxResultCount' => 15,
                'rankPreference' => 'DISTANCE',
                'languageCode' => $lang,
                'locationRestriction' => ['circle' => ['center' => ['latitude' => $lat, 'longitude' => $lng], 'radius' => (float) self::NEARBY_M]],
            ]);
        } catch (Throwable $e) {
            Log::warning('[Rides] nearby places (Google) failed: '.$e->getMessage());

            return [];
        }
        if (! $response->successful()) {
            // Usually the key: «Places API (New)» not enabled on it, or billing.
            Log::warning('[Rides] nearby places (Google) refused: HTTP '.$response->status());

            return [];
        }

        return collect((array) $response->json('places'))
            ->map(fn (array $p) => [
                'name' => (string) ($p['displayName']['text'] ?? ''),
                'address' => (string) ($p['shortFormattedAddress'] ?? $p['formattedAddress'] ?? ''),
                'lat' => (float) ($p['location']['latitude'] ?? 0), 'lng' => (float) ($p['location']['longitude'] ?? 0),
            ])
            ->filter(fn (array $p) => $p['name'] !== '' && ($p['lat'] != 0 || $p['lng'] != 0))
            ->values()->all();
    }

    /**
     * Mapbox's points of interest around a point (Search Box «reverse»).
     *
     * @return list<array{name: string, address: string, lat: float, lng: float}>
     */
    private function mapboxNearby(string $token, float $lat, float $lng, string $lang): array
    {
        if ($token === '') {
            return [];
        }
        try {
            $response = Http::timeout(6)->get('https://api.mapbox.com/search/searchbox/v1/reverse', [
                'access_token' => $token, 'longitude' => $lng, 'latitude' => $lat, 'types' => 'poi', 'limit' => 10, 'language' => $lang,
            ]);
        } catch (Throwable $e) {
            Log::warning('[Rides] nearby places (Mapbox) failed: '.$e->getMessage());

            return [];
        }
        if (! $response->successful()) {
            return [];
        }

        return collect((array) $response->json('features'))
            ->map(fn (array $f) => [
                'name' => (string) ($f['properties']['name'] ?? ''),
                'address' => (string) ($f['properties']['full_address'] ?? $f['properties']['place_formatted'] ?? ''),
                'lat' => (float) ($f['geometry']['coordinates'][1] ?? 0), 'lng' => (float) ($f['geometry']['coordinates'][0] ?? 0),
            ])
            ->filter(fn (array $p) => $p['name'] !== '' && ($p['lat'] != 0 || $p['lng'] != 0))
            ->values()->all();
    }

    /** @return list<array{name: string, address: string, lat: float, lng: float}> */
    private function mapbox(string $token, string $query, ?float $lat, ?float $lng, string $lang): array
    {
        if ($token === '') {
            return [];
        }
        try {
            $response = Http::timeout(6)->get(rtrim((string) config('rides.mapbox.geocode_url'), '/').'/'.rawurlencode($query).'.json', array_filter([
                'access_token' => $token, 'autocomplete' => 'true', 'language' => $lang, 'limit' => 8,
                'types' => 'poi,address,neighborhood,locality,place,district',
                'proximity' => $lat !== null && $lng !== null ? sprintf('%.5F,%.5F', $lng, $lat) : null,
            ]));
        } catch (Throwable $e) {
            Log::warning('[Rides] place search (Mapbox) failed: '.$e->getMessage());

            return [];
        }
        if (! $response->successful()) {
            return [];
        }

        return collect((array) $response->json('features'))
            ->map(fn (array $f) => [
                'name' => (string) ($f['text'] ?? ''),
                'address' => (string) ($f['place_name'] ?? $f['text'] ?? ''),
                'lat' => (float) ($f['center'][1] ?? 0), 'lng' => (float) ($f['center'][0] ?? 0),
            ])
            ->filter(fn (array $p) => $p['name'] !== '' && ($p['lat'] != 0 || $p['lng'] != 0))
            ->values()->all();
    }

    /** @return list<array{name: string, address: string, lat: float, lng: float}> */
    private function google(string $key, string $query, ?float $lat, ?float $lng, string $lang): array
    {
        if ($key === '') {
            return [];
        }
        $bounds = null;
        if ($lat !== null && $lng !== null) {
            [$minLat, $maxLat, $minLng, $maxLng] = Geo::boxAround($lat, $lng, self::RADIUS_M);
            $bounds = sprintf('%.5F,%.5F|%.5F,%.5F', $minLat, $minLng, $maxLat, $maxLng);
        }
        try {
            $response = Http::timeout(6)->get((string) config('rides.google.geocode_url'), array_filter([
                'address' => $query, 'key' => $key, 'language' => $lang, 'bounds' => $bounds,
            ]));
        } catch (Throwable $e) {
            Log::warning('[Rides] place search (Google) failed: '.$e->getMessage());

            return [];
        }
        if (! $response->successful() || $response->json('status') !== 'OK') {
            return [];
        }

        return collect((array) $response->json('results'))
            ->map(fn (array $r) => [
                'name' => self::short((string) ($r['formatted_address'] ?? '')),
                'address' => (string) ($r['formatted_address'] ?? ''),
                'lat' => (float) ($r['geometry']['location']['lat'] ?? 0), 'lng' => (float) ($r['geometry']['location']['lng'] ?? 0),
            ])
            ->filter(fn (array $p) => $p['address'] !== '')
            ->values()->all();
    }

    /**
     * Well-known places nearby (Mapbox's Search Box categories): malls, the airport, hospitals, universities, parks.
     *
     * @return list<array{name: string, address: string, lat: float, lng: float}>
     */
    private function landmarks(string $token, float $lat, float $lng, string $lang): array
    {
        $found = [];
        foreach (['shopping_mall', 'airport', 'hospital', 'university', 'park'] as $category) {
            try {
                $response = Http::timeout(5)->get('https://api.mapbox.com/search/searchbox/v1/category/'.$category, [
                    'access_token' => $token, 'proximity' => sprintf('%.5F,%.5F', $lng, $lat), 'language' => $lang, 'limit' => 2,
                ]);
            } catch (Throwable) {
                continue;
            }
            foreach ((array) $response->json('features') as $f) {
                $c = $f['geometry']['coordinates'] ?? null;
                if (! is_array($c) || Geo::metres($lat, $lng, (float) $c[1], (float) $c[0]) > self::RADIUS_M) {
                    continue;
                }
                $found[] = [
                    'name' => (string) ($f['properties']['name'] ?? ''),
                    'address' => (string) ($f['properties']['full_address'] ?? $f['properties']['place_formatted'] ?? $f['properties']['name'] ?? ''),
                    'lat' => (float) $c[1], 'lng' => (float) $c[0],
                ];
            }
        }

        return array_values(array_filter($found, fn (array $p) => $p['name'] !== ''));
    }

    /** The first part of an address, for the bold line. */
    private static function short(string $address): string
    {
        return trim(explode('،', explode(',', $address)[0])[0]);
    }
}
