<?php

namespace Modules\Driver\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Driver\Contracts\DriverWorkGate;
use Modules\Driver\Events\DriverLocationUpdated;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverLocationBatch;
use Modules\Driver\Models\DriverLocationLog;
use Modules\Driver\Models\DriverPresence;

/**
 * The points the driver's phone logged (every 30 s, faster on a ride — docs/rides-plan.md §10),
 * sent in batches: a resent batch is a no-op; each point's real time is the phone's monotonic
 * clock corrected by the server offset; the latest point moves the driver on the map.
 */
class LocationIngestService
{
    public function __construct(private readonly DriverWorkGate $gate) {}

    /**
     * @param  list<array{lat: float, lng: float, accuracy_m?: ?int, speed_mps?: ?float, heading?: ?int, recorded_at: string, device_time?: ?string, is_mock?: bool}>  $points
     * @return array{stored: int, duplicate: bool}
     */
    public function ingest(Driver $driver, string $batchUuid, array $points, int $clockOffsetMs = 0): array
    {
        if (DriverLocationBatch::query()->where('driver_id', $driver->id)->where('batch_uuid', $batchUuid)->exists()) {
            return ['stored' => 0, 'duplicate' => true];
        }

        usort($points, fn (array $a, array $b) => strcmp($a['recorded_at'], $b['recorded_at']));
        $ride = $this->gate->activeRide($driver);

        $result = DB::transaction(function () use ($driver, $batchUuid, $points, $clockOffsetMs, $ride) {
            $batch = DriverLocationBatch::query()->create([
                'driver_id' => $driver->id,
                'batch_uuid' => $batchUuid,
                'points_count' => count($points),
                'first_recorded_at' => $this->realTime($points[0]['recorded_at'] ?? null, $clockOffsetMs),
                'last_recorded_at' => $this->realTime($points[count($points) - 1]['recorded_at'] ?? null, $clockOffsetMs),
                'clock_offset_ms' => $clockOffsetMs,
                'received_at' => now(),
            ]);

            $rows = array_map(fn (array $p) => [
                'driver_id' => $driver->id,
                'ride_id' => $ride['id'] ?? null,
                'batch_id' => $batch->id,
                'lat' => $p['lat'],
                'lng' => $p['lng'],
                'accuracy_m' => $p['accuracy_m'] ?? null,
                'speed_mps' => $p['speed_mps'] ?? null,
                'heading' => $p['heading'] ?? null,
                'recorded_at' => $this->realTime($p['recorded_at'], $clockOffsetMs),
                'device_time' => isset($p['device_time']) ? Carbon::parse($p['device_time']) : null,
                'has_customer' => (bool) ($ride['has_customer'] ?? false),
                'is_mock' => (bool) ($p['is_mock'] ?? false),
                'created_at' => now(),
            ], $points);
            foreach (array_chunk($rows, 200) as $chunk) {
                DriverLocationLog::query()->insert($chunk);
            }

            return ['stored' => count($rows), 'duplicate' => false];
        });

        $last = $points[count($points) - 1] ?? null;
        if ($last !== null) {
            DriverPresence::query()->updateOrCreate(['driver_id' => $driver->id], [
                'lat' => $last['lat'],
                'lng' => $last['lng'],
                'heading' => $last['heading'] ?? null,
                'speed_mps' => $last['speed_mps'] ?? null,
                'accuracy_m' => $last['accuracy_m'] ?? null,
                'gps_enabled' => true,
                'gps_off_since' => null,
                'last_seen_at' => now(),
            ]);

            event(new DriverLocationUpdated($driver, (float) $last['lat'], (float) $last['lng'], $last['heading'] ?? null, isset($last['speed_mps']) ? (float) $last['speed_mps'] : null, $ride['id'] ?? null));
        }

        return $result;
    }

    /** The phone reports the GPS switched off / on (§10.4): kept on the presence for the alarm and the review. */
    public function gpsChanged(Driver $driver, bool $enabled): void
    {
        DriverPresence::query()->updateOrCreate(['driver_id' => $driver->id], [
            'gps_enabled' => $enabled,
            'gps_off_since' => $enabled ? null : now(),
        ]);
    }

    /** The phone's time, corrected by how far its clock is from the server's (§10.5). */
    private function realTime(?string $time, int $clockOffsetMs): ?Carbon
    {
        return $time === null ? null : Carbon::parse($time)->subMilliseconds($clockOffsetMs);
    }
}
