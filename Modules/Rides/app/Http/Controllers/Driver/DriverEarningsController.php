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
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\DriverDailyStat;
use Modules\Rides\Models\DriverPointsEntry;
use Modules\Rides\Models\DriverRideProfile;
use Modules\Rides\Models\RideSettlementItem;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\Wallet\Enums\WalletBucket;

/**
 * The driver's home numbers (owner: daily income, stats, points): today / this week / this month,
 * the earnings still pending release, the wallet (a negative balance = commission owed on cash
 * rides), the points and the last few point entries. And the period report (D-193..D-202): the last
 * 3 / 6 / 9 / 12 months or a range the captain picks, set against the period of the same length just
 * before it.
 */
class DriverEarningsController extends Controller
{
    /** The preset periods: the key the app sends → months back from today. */
    private const PRESETS = ['3m' => 3, '6m' => 6, '9m' => 9, '12m' => 12];

    /** What a period is compared on. */
    private const COMPARED = ['net_minor', 'gross_minor', 'trips', 'tips_minor', 'online_s'];

    /** Longer than this many days a series is by month, not by day. */
    private const DAILY_SERIES_MAX_DAYS = 62;

    public function __construct(private readonly RidePolicies $policies) {}

    public function summary(Request $request): JsonResponse
    {
        /** @var Driver $driver */
        $driver = $request->user();
        $today = CarbonImmutable::now(config('rides.default_timezone', 'Asia/Riyadh'))->startOfDay();
        $profile = DriverRideProfile::query()->find($driver->id);

        $period = fn (CarbonImmutable $from) => $this->sum($driver->id, $from, $today);
        $wallet = $driver->country_id !== null && ($country = Country::query()->find($driver->country_id)) !== null ? $driver->walletFor($country) : null;

        return ApiResponse::success([
            'currency' => $this->currency($driver, $wallet),
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

    /**
     * `period` = 3m | 6m | 9m | 12m (ending today) or `custom` with `from` and `to` (D-193..D-197), and the
     * same figures for the period of equal length right before it with the difference — in money, in
     * percent and as a direction word, so a screen never has to rely on colour alone (D-200..D-202).
     */
    public function report(Request $request): JsonResponse
    {
        /** @var Driver $driver */
        $driver = $request->user();
        $today = CarbonImmutable::now(config('rides.default_timezone', 'Asia/Riyadh'))->startOfDay();
        $maxDays = $driver->country_id === null ? 366 : max(1, $this->policies->for((int) $driver->country_id)->int('earnings.report_max_days'));

        $input = $request->validate([
            'period' => ['required', 'in:3m,6m,9m,12m,custom'],
            'from' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        if ($input['period'] === 'custom') {
            $from = CarbonImmutable::parse($input['from'], $today->getTimezone())->startOfDay();
            $to = min(CarbonImmutable::parse($input['to'], $today->getTimezone())->startOfDay(), $today);
            if ($from > $to) {
                throw new RidesException('earnings_range_in_future');
            }
            if ($from->diffInDays($to) + 1 > $maxDays) {
                throw new RidesException('earnings_range_too_long', 422, ['days' => $maxDays], ['max_days' => $maxDays]);
            }
        } else {
            $to = $today;
            $from = $today->subMonthsNoOverflow(self::PRESETS[$input['period']])->addDay();
        }

        $days = (int) $from->diffInDays($to) + 1;
        $previousTo = $from->subDay();
        $previousFrom = $previousTo->subDays($days - 1);

        $current = $this->sum($driver->id, $from, $to);
        $previous = $this->sum($driver->id, $previousFrom, $previousTo);
        $wallet = $driver->country_id !== null && ($country = Country::query()->find($driver->country_id)) !== null ? $driver->walletFor($country) : null;

        return ApiResponse::success([
            'currency' => $this->currency($driver, $wallet),
            'period' => ['key' => $input['period'], 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days],
            'totals' => $current,
            'previous' => ['from' => $previousFrom->toDateString(), 'to' => $previousTo->toDateString(), 'totals' => $previous],
            'change' => collect(self::COMPARED)->mapWithKeys(fn (string $key) => [$key => $this->change((int) $current[$key], (int) $previous[$key])])->all(),
            'series' => $this->series($driver->id, $from, $to, $days),
        ], __('api.retrieved'));
    }

    /**
     * The difference between this period and the one before it: `value` (this − before), `pct_bp` (basis
     * points of the earlier figure; absent when there was nothing before) and the direction `up` | `down`
     * | `same` | `new` (something now, nothing before).
     *
     * @return array{value: int, pct_bp: int|null, direction: string}
     */
    private function change(int $now, int $before): array
    {
        $direction = $now > $before ? ($before === 0 ? 'new' : 'up') : ($now < $before ? 'down' : 'same');

        return [
            'value' => $now - $before,
            'pct_bp' => $before === 0 ? null : (int) round(($now - $before) * 10000 / $before),
            'direction' => $direction,
        ];
    }

    /**
     * The period split by day (short ranges) or by month, for the bars: every slot in the range appears,
     * empty ones with zeros, so the bars keep their places.
     *
     * @return array{unit: string, points: list<array<string, mixed>>}
     */
    private function series(int $driverId, CarbonImmutable $from, CarbonImmutable $to, int $days): array
    {
        $byMonth = $days > self::DAILY_SERIES_MAX_DAYS;
        $rows = DriverDailyStat::query()->where('driver_id', $driverId)->whereBetween('stat_date', [$from->toDateString(), $to->toDateString()])
            ->get(['stat_date', 'trips', 'net_minor'])
            ->groupBy(fn (DriverDailyStat $d) => substr((string) $d->stat_date, 0, $byMonth ? 7 : 10));

        $points = [];
        for ($cursor = $byMonth ? $from->startOfMonth() : $from; $cursor <= $to; $cursor = $byMonth ? $cursor->addMonthNoOverflow() : $cursor->addDay()) {
            $key = $cursor->format($byMonth ? 'Y-m' : 'Y-m-d');
            $group = $rows->get($key);
            $points[] = ['key' => $key, 'trips' => (int) ($group?->sum('trips') ?? 0), 'net_minor' => (int) ($group?->sum('net_minor') ?? 0)];
        }

        return ['unit' => $byMonth ? 'month' : 'day', 'points' => $points];
    }

    /** @return array{code: string, symbol: string, decimal_places: int}|null */
    private function currency(Driver $driver, mixed $wallet): ?array
    {
        $currency = $wallet?->currency ?? ($driver->country_id !== null ? Country::query()->find($driver->country_id)?->currency : null);

        return $currency === null ? null : ['code' => $currency->code, 'symbol' => $currency->symbol, 'decimal_places' => (int) ($currency->decimal_places ?? 2)];
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
