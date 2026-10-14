<?php

namespace Modules\Rides\Services\Stats;

use Illuminate\Support\Facades\DB;
use Modules\Rides\Models\DriverDailyStat;

/**
 * Adds to a driver's day (driver_daily_stats) as things happen — the earnings and stats screens
 * read these rows instead of summing rides. The day is the driver's local day.
 */
class DriverStats
{
    /**
     * @param  array<string, int>  $increments  column => amount
     */
    public function bump(int $driverId, array $increments, ?string $timezone = null): void
    {
        $date = now($timezone ?? (string) config('rides.default_timezone', 'Asia/Riyadh'))->toDateString();

        DB::transaction(function () use ($driverId, $increments, $date) {
            $row = DriverDailyStat::query()->firstOrCreate(['driver_id' => $driverId, 'stat_date' => $date]);
            $row = DriverDailyStat::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            foreach ($increments as $column => $amount) {
                $row->{$column} = (int) $row->{$column} + $amount;
            }
            $row->save();
        });
    }
}
