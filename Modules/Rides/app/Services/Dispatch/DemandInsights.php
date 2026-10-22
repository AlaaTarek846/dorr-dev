<?php

namespace Modules\Rides\Services\Dispatch;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Driver\Enums\PresenceStatus;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverPresence;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Services\Drivers\DriverEligibility;
use Modules\Rides\Support\Geo;

/**
 * Where the work is, for one captain (Block 06 phase 4) — only the requests of the categories this captain
 * may and wants to drive (their eligibility and choices), around where they are:
 *
 *  - hot zones now (the last hour, ~1 km cells): requests, the ones nobody took, and how many free
 *    captains are already there → an opportunity score; a cell with unmet requests and no captain is a
 *    shortage (AI-18);
 *  - the forecast (AI-02): for the next 15 / 30 / 60 minutes, what the same weekday and time brought in
 *    the last `weeks` weeks, with a confidence = how many of those weeks had it;
 *  - booked-ahead demand (AI-17): bookings already waiting in the next hours, and what the same hours
 *    usually bring.
 *
 * The heat map (`demand-heat`) stays as it was. Nothing here moves a captain: they are suggestions.
 */
class DemandInsights
{
    private const RADIUS_M = 15000;

    private const WEEKS = 4;

    public function __construct(private readonly DriverEligibility $eligibility) {}

    /** @return list<int> the categories this captain could be offered (null = none known → all) */
    public function categoriesFor(Driver $driver): ?array
    {
        $vehicle = $this->eligibility->activeVehicle($driver);
        if ($vehicle === null) {
            return null;
        }

        return RideCategory::query()->where('status', true)->get()
            ->filter(fn (RideCategory $c) => $this->eligibility->categoryProblem($driver, $vehicle, $c) === null && $this->eligibility->wants($driver, $c))
            ->pluck('id')->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public function hotZones(Driver $driver, float $lat, float $lng): array
    {
        $categories = $this->categoriesFor($driver);
        $key = 'rides:hot:'.round($lat, 2).','.round($lng, 2).':'.md5(json_encode($categories));

        return Cache::remember($key, now()->addMinutes(2), function () use ($lat, $lng, $categories) {
            [$minLat, $maxLat, $minLng, $maxLng] = Geo::boxAround($lat, $lng, self::RADIUS_M);
            $rows = DB::table('rides')->where('requested_at', '>=', now()->subHour())
                ->whereBetween('origin_lat', [$minLat, $maxLat])->whereBetween('origin_lng', [$minLng, $maxLng])
                ->when($categories !== null, fn ($q) => $q->whereIn('category_id', $categories === [] ? [0] : $categories))
                ->selectRaw("ROUND(origin_lat, 2) as clat, ROUND(origin_lng, 2) as clng, COUNT(*) as n, SUM(CASE WHEN status IN ('expired', 'searching') THEN 1 ELSE 0 END) as unmet")
                ->groupByRaw('ROUND(origin_lat, 2), ROUND(origin_lng, 2)')
                ->orderByDesc('n')->limit(30)->get();
            if ($rows->isEmpty()) {
                return [];
            }
            $free = DriverPresence::query()->where('status', PresenceStatus::Online->value)->where('last_seen_at', '>=', now()->subMinutes(10))
                ->whereBetween('lat', [$minLat, $maxLat])->whereBetween('lng', [$minLng, $maxLng])
                ->get(['lat', 'lng'])->countBy(fn ($p) => round((float) $p->lat, 2).','.round((float) $p->lng, 2));

            return $rows->map(function ($r) use ($lat, $lng, $free) {
                $captains = (int) ($free[round((float) $r->clat, 2).','.round((float) $r->clng, 2)] ?? 0);
                $requests = (int) $r->n;
                $unmet = (int) $r->unmet;
                $score = round(($requests + 2 * $unmet) / ($captains + 1), 2);

                return [
                    'lat' => (float) $r->clat, 'lng' => (float) $r->clng,
                    'requests' => $requests, 'unmet' => $unmet, 'captains' => $captains,
                    'score' => $score,
                    'shortage' => $unmet >= 2 && $captains === 0,
                    'distance_m' => (int) round(Geo::metres($lat, $lng, (float) $r->clat, (float) $r->clng)),
                    'why' => $unmet >= 2 && $captains === 0
                        ? __('rides.ai.zone_shortage', ['unmet' => $unmet])
                        : __('rides.ai.zone_busy', ['requests' => $requests, 'captains' => $captains]),
                ];
            })->sortByDesc('score')->take(6)->values()->all();
        });
    }

    /**
     * AI-02: the next 15 / 30 / 60 minutes, from the same weekday and time in the last few weeks.
     *
     * @return list<array{in_min: int, zones: list<array<string, mixed>>}>
     */
    public function forecast(Driver $driver, float $lat, float $lng): array
    {
        $categories = $this->categoriesFor($driver);
        $tz = (string) config('rides.default_timezone', 'Asia/Riyadh');
        $now = CarbonImmutable::now($tz);
        $key = 'rides:forecast:'.round($lat, 1).','.round($lng, 1).':'.$now->format('N-H-').intdiv((int) $now->format('i'), 15).':'.md5(json_encode($categories));

        return Cache::remember($key, now()->addMinutes(10), function () use ($lat, $lng, $categories, $now) {
            [$minLat, $maxLat, $minLng, $maxLng] = Geo::boxAround($lat, $lng, self::RADIUS_M);
            $out = [];
            foreach ([15, 30, 60] as $in) {
                $cells = [];
                for ($week = 1; $week <= self::WEEKS; $week++) {
                    $from = $now->subWeeks($week)->addMinutes($in - 15)->utc();
                    $rows = DB::table('rides')->whereBetween('requested_at', [$from, $from->addMinutes(30)])
                        ->whereBetween('origin_lat', [$minLat, $maxLat])->whereBetween('origin_lng', [$minLng, $maxLng])
                        ->when($categories !== null, fn ($q) => $q->whereIn('category_id', $categories === [] ? [0] : $categories))
                        ->selectRaw('ROUND(origin_lat, 2) as clat, ROUND(origin_lng, 2) as clng, COUNT(*) as n')
                        ->groupByRaw('ROUND(origin_lat, 2), ROUND(origin_lng, 2)')->get();
                    foreach ($rows as $r) {
                        $k = $r->clat.','.$r->clng;
                        $cells[$k] ??= ['lat' => (float) $r->clat, 'lng' => (float) $r->clng, 'total' => 0, 'weeks' => 0];
                        $cells[$k]['total'] += (int) $r->n;
                        $cells[$k]['weeks']++;
                    }
                }
                $zones = collect($cells)
                    ->map(fn (array $c) => [
                        'lat' => $c['lat'], 'lng' => $c['lng'],
                        'expected' => round($c['total'] / self::WEEKS, 1),
                        // Seen in 3 of the last 4 weeks = 0.75: how much to trust it, said plainly.
                        'confidence' => round($c['weeks'] / self::WEEKS, 2),
                        'distance_m' => (int) round(Geo::metres($lat, $lng, $c['lat'], $c['lng'])),
                    ])
                    ->filter(fn (array $z) => $z['expected'] >= 0.5)
                    ->sortByDesc(fn (array $z) => $z['expected'] * $z['confidence'])->take(5)->values()->all();
                $out[] = ['in_min' => $in, 'zones' => $zones];
            }

            return $out;
        });
    }

    /**
     * AI-17: bookings ahead around here — already waiting for a captain in the next hours, and what the same
     * hours usually bring.
     *
     * @return list<array{hour: string, booked: int, usual: float}>
     */
    public function scheduled(Driver $driver, float $lat, float $lng, int $hours = 12): array
    {
        $categories = $this->categoriesFor($driver);
        $tz = (string) config('rides.default_timezone', 'Asia/Riyadh');
        [$minLat, $maxLat, $minLng, $maxLng] = Geo::boxAround($lat, $lng, self::RADIUS_M);
        $base = fn () => DB::table('rides')->whereNotNull('scheduled_at')
            ->whereBetween('origin_lat', [$minLat, $maxLat])->whereBetween('origin_lng', [$minLng, $maxLng])
            ->when($categories !== null, fn ($q) => $q->whereIn('category_id', $categories === [] ? [0] : $categories));

        $booked = $base()->where('status', RideStatus::Scheduled->value)->whereNull('reserved_driver_id')
            ->whereBetween('scheduled_at', [now(), now()->addHours($hours)])->pluck('scheduled_at')
            ->countBy(fn ($at) => CarbonImmutable::parse($at)->setTimezone($tz)->format('Y-m-d H:00'));
        $usual = $base()->whereBetween('scheduled_at', [now()->subWeeks(self::WEEKS), now()])->pluck('scheduled_at')
            ->countBy(fn ($at) => CarbonImmutable::parse($at)->setTimezone($tz)->format('N H'));

        $out = [];
        $start = CarbonImmutable::now($tz)->startOfHour();
        for ($h = 0; $h < $hours; $h++) {
            $slot = $start->addHours($h);
            $usualHere = round(($usual[$slot->format('N H')] ?? 0) / self::WEEKS, 1);
            $bookedHere = (int) ($booked[$slot->format('Y-m-d H:00')] ?? 0);
            if ($bookedHere > 0 || $usualHere > 0) {
                $out[] = ['hour' => $slot->toIso8601String(), 'booked' => $bookedHere, 'usual' => $usualHere];
            }
        }

        return $out;
    }
}
