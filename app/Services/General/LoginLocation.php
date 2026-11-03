<?php

namespace App\Services\General;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Where a sign-in (or an app open) comes from, by IP (docs/watch_earn_tasks.md WE-003, D10): the coordinates the IP
 * lookup gives and the nearest of the admin's cities (`discover_cities`) within CITY_RADIUS_KM of them. A city is
 * matched by its id — the IP's own city name ("Ar Riyad", a district, the provider's city…) is shown, never compared.
 */
class LoginLocation
{
    public const CITY_RADIUS_KM = 60;

    private const CITIES = 'login_location:cities';

    /** @return array{lat: float, lng: float, ip_city: ?string, city_id: ?int, country_code: string}|null */
    public function locate(?string $ip = null): ?array
    {
        $geo = ipGeo($ip);

        if ($geo === null) {
            return null;
        }

        return [
            'lat' => $geo['lat'],
            'lng' => $geo['lng'],
            'ip_city' => $geo['city'],
            'city_id' => $this->cityAt($geo['lat'], $geo['lng']),
            'country_code' => $geo['country_code'],
        ];
    }

    /**
     * Saves it on the account — on every sign-in, and when the app opens from another IP. An IP that can't be placed
     * keeps the last known place.
     */
    public function remember(Model $user, ?string $ip = null): void
    {
        $ip ??= (string) request()->ip();

        if ($user->getAttribute('geo_ip') === $ip && $user->getAttribute('geo_at') !== null) {
            return;
        }

        $place = $this->locate($ip);
        if ($place === null) {
            return;
        }

        $user->forceFill([
            'geo_city_id' => $place['city_id'],
            'geo_lat' => $place['lat'],
            'geo_lng' => $place['lng'],
            'geo_ip' => $ip,
            'geo_at' => now(),
        ])->saveQuietly();
    }

    /** The admin's city the point is in: the nearest one within CITY_RADIUS_KM. */
    public function cityAt(float $lat, float $lng): ?int
    {
        return collect($this->cities())
            ->map(fn (array $city) => [$city['id'], self::km($lat, $lng, $city['lat'], $city['lng'])])
            ->filter(fn (array $pair) => $pair[1] <= self::CITY_RADIUS_KM)
            ->sortBy(fn (array $pair) => $pair[1])
            ->first()[0] ?? null;
    }

    public static function forget(): void
    {
        Cache::forget(self::CITIES);
    }

    public static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** Discover's cities are the admin's list of cities (same as the chat's and rides' city rules). */
    private function cities(): array
    {
        $class = 'Modules\\Discover\\Models\\DiscoverCity';
        if (! class_exists($class)) {
            return [];
        }

        return Cache::remember(self::CITIES, 300, fn () => $class::query()
            ->where('status', true)->whereNotNull('lat')->whereNotNull('lng')
            ->get(['id', 'lat', 'lng'])
            ->map(fn (Model $c) => ['id' => (int) $c->getKey(), 'lat' => (float) $c->getAttribute('lat'), 'lng' => (float) $c->getAttribute('lng')])
            ->all());
    }
}
