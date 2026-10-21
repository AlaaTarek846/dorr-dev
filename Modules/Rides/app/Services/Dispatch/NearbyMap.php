<?php

namespace Modules\Rides\Services\Dispatch;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Driver\Enums\PresenceStatus;
use Modules\Driver\Models\DriverPresence;
use Modules\Rides\Events\AreaDriverMoved;
use Modules\Rides\Models\RideActiveLock;
use Modules\Rides\Support\Geo;

/**
 * What the maps show around a place:
 *  - O-59 (rider): the free captains' cars nearby right now (aliases, positions, headings) — the first
 *    picture; after it, AreaDriverMoved moves them in real time;
 *  - O-58 (captain): where requests come from — the last hour's ride requests around him, in ~1 km cells,
 *    hottest first.
 */
class NearbyMap
{
    /** @return array{cells: list<string>, cars: list<array{car: string, lat: float, lng: float, heading: ?int}>} */
    public function cars(float $lat, float $lng, int $radiusM = 5000): array
    {
        [$minLat, $maxLat, $minLng, $maxLng] = Geo::boxAround($lat, $lng, $radiusM);
        $busy = RideActiveLock::query()->where('lock_key', 'like', 'driver:%')->pluck('lock_key')->map(fn (string $k) => (int) substr($k, 7));
        $cars = DriverPresence::query()->where('status', PresenceStatus::Online->value)->where('gps_enabled', true)
            ->where('last_seen_at', '>=', now()->subMinutes(10))
            ->whereBetween('lat', [$minLat, $maxLat])->whereBetween('lng', [$minLng, $maxLng])
            ->whereNotIn('driver_id', $busy)->limit(40)->get()
            ->map(fn (DriverPresence $p) => ['car' => AreaDriverMoved::alias((int) $p->driver_id), 'lat' => round((float) $p->lat, 5), 'lng' => round((float) $p->lng, 5), 'heading' => $p->heading !== null ? (int) $p->heading : null])
            ->values()->all();

        // The area channels to follow: the cell here and the eight around it.
        $cells = [];
        $row = (int) floor($lat / AreaDriverMoved::CELL);
        $col = (int) floor($lng / AreaDriverMoved::CELL);
        foreach ([-1, 0, 1] as $dr) {
            foreach ([-1, 0, 1] as $dc) {
                $cells[] = ($row + $dr).'_'.($col + $dc);
            }
        }

        return ['cells' => $cells, 'cars' => $cars];
    }

    /** @return list<array{lat: float, lng: float, weight: float, requests: int}> */
    public function heat(float $lat, float $lng, int $radiusM = 15000): array
    {
        $key = 'rides:heat:'.round($lat, 2).','.round($lng, 2);

        return Cache::remember($key, now()->addMinutes(2), function () use ($lat, $lng, $radiusM) {
            [$minLat, $maxLat, $minLng, $maxLng] = Geo::boxAround($lat, $lng, $radiusM);
            $rows = DB::table('rides')->where('requested_at', '>=', now()->subHour())
                ->whereBetween('origin_lat', [$minLat, $maxLat])->whereBetween('origin_lng', [$minLng, $maxLng])
                ->selectRaw('ROUND(origin_lat, 2) as clat, ROUND(origin_lng, 2) as clng, COUNT(*) as n, SUM(CASE WHEN status IN (\'expired\', \'searching\') THEN 1 ELSE 0 END) as unmet')
                ->groupByRaw('ROUND(origin_lat, 2), ROUND(origin_lng, 2)')
                ->orderByDesc('n')->limit(40)->get();
            $max = max(1, (int) $rows->max('n'));

            // A request nobody took counts double: that's where a captain is missing.
            return $rows->map(fn ($r) => [
                'lat' => (float) $r->clat, 'lng' => (float) $r->clng, 'requests' => (int) $r->n,
                'weight' => round(min(1, ((int) $r->n + (int) $r->unmet) / ($max * 1.5)), 2),
            ])->values()->all();
        });
    }
}
