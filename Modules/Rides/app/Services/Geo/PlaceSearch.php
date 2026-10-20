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
