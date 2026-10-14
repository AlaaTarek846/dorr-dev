<?php

namespace Modules\Rides\Services\Lifecycle;

use Illuminate\Support\Facades\DB;

/** The ride number people read and Wasl receives as tripId: DR-YYMMDD-NNNN, counted per day. */
class RideNumbers
{
    public function next(): string
    {
        $day = now()->format('ymd');
        $key = 'number:'.$day;

        $value = DB::transaction(function () use ($key) {
            DB::table('ride_counters')->insertOrIgnore(['key' => $key, 'value' => 0, 'updated_at' => now()]);
            $current = (int) DB::table('ride_counters')->where('key', $key)->lockForUpdate()->value('value');
            DB::table('ride_counters')->where('key', $key)->update(['value' => $current + 1, 'updated_at' => now()]);

            return $current + 1;
        });

        return sprintf('DR-%s-%04d', $day, $value);
    }
}
