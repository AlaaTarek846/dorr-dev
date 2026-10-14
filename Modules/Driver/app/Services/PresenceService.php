<?php

namespace Modules\Driver\Services;

use Illuminate\Support\Facades\DB;
use Modules\Driver\Contracts\DriverWorkGate;
use Modules\Driver\Enums\PresenceStatus;
use Modules\Driver\Exceptions\DriverException;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverPresence;
use Modules\Driver\Models\DriverPresenceSession;

/**
 * Online / offline / pause (D-26, D-41). Going online needs the location and the work gate's yes;
 * every change opens or closes a presence session (the "hours online" in the stats).
 */
class PresenceService
{
    public function __construct(private readonly DriverWorkGate $gate) {}

    public function of(Driver $driver): DriverPresence
    {
        return DriverPresence::query()->firstOrCreate(['driver_id' => $driver->id], ['status' => PresenceStatus::Offline]);
    }

    public function goOnline(Driver $driver, float $lat, float $lng, ?string $appVersion = null): DriverPresence
    {
        if (($reason = $this->gate->reasonNotToWork($driver)) !== null) {
            throw new DriverException('cannot_go_online', 422, ['reason' => $reason], ['reason' => $reason]);
        }

        return DB::transaction(function () use ($driver, $lat, $lng, $appVersion) {
            $presence = $this->lockedPresence($driver);
            $onTrip = $this->gate->activeRide($driver) !== null;

            if ($presence->status === PresenceStatus::Offline) {
                DriverPresenceSession::query()->create(['driver_id' => $driver->id, 'started_at' => now()]);
                $presence->online_since = now();
            }

            $presence->fill([
                'status' => $onTrip ? PresenceStatus::OnTrip : PresenceStatus::Online,
                'lat' => $lat,
                'lng' => $lng,
                'gps_enabled' => true,
                'gps_off_since' => null,
                'paused_until' => null,
                'last_ride_requested' => false,
                'last_seen_at' => now(),
                'app_version' => $appVersion ?? $presence->app_version,
            ])->save();

            return $presence;
        });
    }

    public function goOffline(Driver $driver, string $reason = 'manual'): DriverPresence
    {
        return DB::transaction(function () use ($driver, $reason) {
            $presence = $this->lockedPresence($driver);
            // A driver on a ride stays reachable until it ends ("last ride" instead, D-400).
            if ($this->gate->activeRide($driver) !== null) {
                $presence->update(['last_ride_requested' => true]);

                return $presence;
            }

            if ($presence->status !== PresenceStatus::Offline) {
                DriverPresenceSession::query()->where('driver_id', $driver->id)->whereNull('ended_at')
                    ->update(['ended_at' => now(), 'end_reason' => $reason]);
            }
            $presence->update(['status' => PresenceStatus::Offline, 'online_since' => null, 'last_ride_requested' => false, 'paused_until' => null]);

            return $presence;
        });
    }

    public function pause(Driver $driver, int $minutes): DriverPresence
    {
        $presence = $this->of($driver);
        if ($presence->status !== PresenceStatus::Online) {
            throw new DriverException('not_online', 422);
        }
        $presence->update(['status' => PresenceStatus::Paused, 'paused_until' => now()->addMinutes(max(1, min(120, $minutes)))]);

        return $presence;
    }

    public function setLastRide(Driver $driver, bool $on): DriverPresence
    {
        $presence = $this->of($driver);
        $presence->update(['last_ride_requested' => $on]);

        return $presence;
    }

    /** Called by rides when a ride ends: back to online, or offline when this was the "last ride". */
    public function rideEnded(Driver $driver): void
    {
        $presence = $this->of($driver);
        if ($presence->last_ride_requested) {
            $this->goOffline($driver, 'last_ride');

            return;
        }
        if ($presence->status === PresenceStatus::OnTrip) {
            $presence->update(['status' => PresenceStatus::Online]);
        }
    }

    public function rideStarted(Driver $driver): void
    {
        $this->of($driver)->update(['status' => PresenceStatus::OnTrip]);
    }

    /** Drivers whose phone stopped sending (D-26: Jawad offlines after 15 min) go offline. */
    public function sweepStale(int $staleAfterSeconds): int
    {
        $stale = DriverPresence::query()
            ->whereIn('status', [PresenceStatus::Online->value, PresenceStatus::Paused->value])
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subSeconds($staleAfterSeconds)))
            ->pluck('driver_id');

        foreach (Driver::query()->whereIn('id', $stale)->get() as $driver) {
            $this->goOffline($driver, 'stale');
        }

        // A pause that ran out is just online again.
        DriverPresence::query()->where('status', PresenceStatus::Paused->value)->where('paused_until', '<', now())
            ->update(['status' => PresenceStatus::Online->value, 'paused_until' => null]);

        return $stale->count();
    }

    private function lockedPresence(Driver $driver): DriverPresence
    {
        $this->of($driver);

        return DriverPresence::query()->whereKey($driver->id)->lockForUpdate()->firstOrFail();
    }
}
