<?php

namespace Modules\Rides\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Support\Api\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Driver\Models\Driver;
use Modules\Rides\Enums\SettlementStatus;
use Modules\Rides\Models\DriverDailyStat;
use Modules\Rides\Models\DriverPointsEntry;
use Modules\Rides\Models\DriverRideProfile;
use Modules\Rides\Models\RideSettlementItem;
use Modules\Wallet\Enums\WalletBucket;

/**
 * The driver's home numbers (owner: daily income, stats, points): today / this week / this month,
 * the earnings still pending release, the wallet (a negative balance = commission owed on cash
 * rides), the points and the last few point entries.
 */
class DriverEarningsController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        /** @var Driver $driver */
        $driver = $request->user();
        $today = CarbonImmutable::now(config('rides.default_timezone', 'Asia/Riyadh'))->startOfDay();
        $profile = DriverRideProfile::query()->find($driver->id);

        $period = fn (CarbonImmutable $from) => $this->sum($driver->id, $from, $today);
        $wallet = $driver->country_id !== null && ($country = Country::query()->find($driver->country_id)) !== null ? $driver->walletFor($country) : null;

        $currency = $wallet?->currency ?? ($driver->country_id !== null ? Country::query()->find($driver->country_id)?->currency : null);

        return ApiResponse::success([
            'currency' => $currency === null ? null : ['code' => $currency->code, 'symbol' => $currency->symbol, 'decimal_places' => (int) ($currency->decimal_places ?? 2)],
            'today' => $period($today),
            'week' => $period($today->startOfWeek(CarbonImmutable::SATURDAY)),
            'month' => $period($today->startOfMonth()),
            'days' => DriverDailyStat::query()->where('driver_id', $driver->id)->where('stat_date', '>=', $today->subDays(6)->toDateString())
                ->orderBy('stat_date')->get(['stat_date', 'trips', 'net_minor'])
                ->map(fn (DriverDailyStat $d) => ['date' => substr((string) $d->stat_date, 0, 10), 'trips' => $d->trips, 'net_minor' => (int) $d->net_minor])->values(),
            'pending_minor' => (int) RideSettlementItem::query()->where('driver_id', $driver->id)->where('status', SettlementStatus::Pending->value)->sum('amount_minor'),
            'next_release_at' => RideSettlementItem::query()->where('driver_id', $driver->id)->where('status', SettlementStatus::Pending->value)->min('release_at'),
            'wallet_minor' => $wallet === null ? 0 : $wallet->balanceMinor(WalletBucket::Withdrawable),
            'profile' => [
                'rating' => $profile !== null && $profile->rating_count > 0 ? (float) $profile->rating_avg : null,
                'rating_count' => (int) ($profile?->rating_count ?? 0),
                'trips_count' => (int) ($profile?->trips_count ?? 0),
                'tier' => $profile?->tier ?? 'new',
                'points' => (int) ($profile?->points_balance ?? 0),
            ],
            'recent_points' => DriverPointsEntry::query()->where('driver_id', $driver->id)->latest('id')->limit(10)->get()
                ->map(fn (DriverPointsEntry $e) => ['event' => $e->event, 'label' => __('rides.points.'.$e->event), 'points' => (int) $e->points, 'at' => $e->created_at?->toIso8601String()])->values(),
        ], __('api.retrieved'));
    }

    /** @return array<string, int> */
    private function sum(int $driverId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = DriverDailyStat::query()->where('driver_id', $driverId)
            ->whereBetween('stat_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COALESCE(SUM(trips),0) trips, COALESCE(SUM(net_minor),0) net, COALESCE(SUM(gross_minor),0) gross, COALESCE(SUM(tips_minor),0) tips, COALESCE(SUM(online_s),0) online, COALESCE(SUM(offers_received),0) received, COALESCE(SUM(offers_accepted),0) accepted, COALESCE(SUM(distance_with_rider_m),0) distance')
            ->first();

        return [
            'trips' => (int) $row->trips,
            'net_minor' => (int) $row->net,
            'gross_minor' => (int) $row->gross,
            'tips_minor' => (int) $row->tips,
            'online_s' => (int) $row->online,
            'distance_m' => (int) $row->distance,
            'acceptance_bp' => (int) $row->received > 0 ? intdiv((int) $row->accepted * 10000, (int) $row->received) : null,
        ];
    }
}
