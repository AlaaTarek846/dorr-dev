<?php

namespace Modules\Rides\Services\Pricing;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Driver\Enums\PresenceStatus;
use Modules\Driver\Models\DriverPresence;
use Modules\Rides\Support\Geo;

/**
 * AI-05 — the price suggested to a rider on an offers (bidding) category, moved with what is happening
 * around the pickup: many recent requests and few free captains → up, plenty of free captains → down —
 * never more than `pricing.smart_max_bp` either way (0 = off, the default), never under the minimum fare.
 *
 * Only a suggestion the rider sees before offering; the price agreed with the captain is fixed with the
 * ride like any other (the confirmed snapshot), so nothing moves it afterwards. Fixed-price categories are
 * not touched: their rate card is the admin's.
 */
final class SmartPrice
{
    private const RADIUS_M = 3000;

    /**
     * @param  array<string, mixed>  $policy
     * @return array{suggested: int, adjust_bp: int, why: ?string}
     */
    public static function suggest(array $policy, int $base, float $lat, float $lng): array
    {
        $max = (int) ($policy['pricing.smart_max_bp'] ?? 0);
        if ($max <= 0 || $base <= 0) {
            return ['suggested' => $base, 'adjust_bp' => 0, 'why' => null];
        }
        [$requests, $free] = Cache::remember('rides:smart-price:'.round($lat, 2).','.round($lng, 2), now()->addMinutes(2), function () use ($lat, $lng) {
            [$minLat, $maxLat, $minLng, $maxLng] = Geo::boxAround($lat, $lng, self::RADIUS_M);
            $requests = DB::table('rides')->where('requested_at', '>=', now()->subMinutes(20))
                ->whereBetween('origin_lat', [$minLat, $maxLat])->whereBetween('origin_lng', [$minLng, $maxLng])->count();
            $free = DriverPresence::query()->where('status', PresenceStatus::Online->value)->where('last_seen_at', '>=', now()->subMinutes(10))
                ->whereBetween('lat', [$minLat, $maxLat])->whereBetween('lng', [$minLng, $maxLng])->count();

            return [$requests, $free];
        });

        // Requests per free captain: 1 is balanced; each one more pushes up a quarter of the room, an idle area pulls down.
        $pressure = $requests / max(1, $free);
        $adjust = match (true) {
            $requests === 0 && $free >= 3 => -intdiv($max, 2),
            $pressure <= 1 => 0,
            default => (int) min($max, round(($pressure - 1) / 4 * $max)),
        };
        $minimum = (int) ($policy['price.min_fare_minor'] ?? 0);
        $suggested = max($minimum, $base + intdiv($base * $adjust, 10000));

        return [
            'suggested' => $suggested,
            'adjust_bp' => $adjust,
            'why' => $adjust === 0 ? null : __($adjust > 0 ? 'rides.ai.price_busy' : 'rides.ai.price_quiet', ['requests' => $requests, 'captains' => $free]),
        ];
    }
}
