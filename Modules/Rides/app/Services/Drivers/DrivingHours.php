<?php

namespace Modules\Rides\Services\Drivers;

use App\Services\Notifications\NotificationCenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Modules\Driver\Enums\PresenceStatus;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverPresence;
use Modules\Driver\Models\DriverPresenceSession;
use Modules\Driver\Services\PresenceService;
use Modules\Rides\Events\DriverChanged;
use Modules\Rides\Models\RideActiveLock;
use Modules\Rides\Services\Policy\RidePolicies;
use Throwable;

/**
 * Driving-time limits (D-42, D-43): the time a captain was online in the last `driving.window_h`
 * hours is counted from their presence sessions. At `driving.warn_s` they get a rest reminder; at
 * `driving.max_s` — the mandatory limit — they can't go online and are not offered new requests
 * (`DriverEligibility` says `driving_limit`), and a captain with no ride running is taken offline.
 * Every limit is the country's own policy and 0 = off, so nothing is limited until it is set.
 * A break (pause) keeps the session open, so it counts like any online time; going offline is rest.
 */
class DrivingHours
{
    public function __construct(
        private readonly RidePolicies $policies,
        private readonly NotificationCenter $center,
        private readonly PresenceService $presence,
    ) {}

    /**
     * @return array{enabled: bool, window_h: int, used_s: int, warn_s: int, max_s: int, remaining_s: int|null, level: string, free_at: string|null}
     */
    public function status(Driver $driver): array
    {
        $policy = $driver->country_id === null ? null : $this->policies->for((int) $driver->country_id);
        $window = max(1, (int) ($policy?->int('driving.window_h') ?? 24));
        $warn = max(0, (int) ($policy?->int('driving.warn_s') ?? 0));
        $max = max(0, (int) ($policy?->int('driving.max_s') ?? 0));
        $enabled = $warn > 0 || $max > 0;
        $now = CarbonImmutable::now();
        $intervals = $enabled ? $this->intervals($driver, $now, $window) : [];
        $used = (int) array_sum(array_map(fn (array $i) => $i[1] - $i[0], $intervals));

        $level = match (true) {
            $max > 0 && $used >= $max => 'limit',
            $warn > 0 && $used >= $warn => 'warn',
            default => 'ok',
        };

        return [
            'enabled' => $enabled,
            'window_h' => $window,
            'used_s' => $used,
            'warn_s' => $warn,
            'max_s' => $max,
            'remaining_s' => $max > 0 ? max(0, $max - $used) : null,
            'level' => $level,
            // At the limit: when enough of the oldest time has left the window to be under it again.
            'free_at' => $level === 'limit' ? $this->freeAt($intervals, $used - $max + 1, $window)->toIso8601String() : null,
        ];
    }

    /** Over the mandatory limit — no going online, no new requests (D-43). */
    public function blocked(Driver $driver): bool
    {
        return $this->status($driver)['level'] === 'limit';
    }

    /**
     * Every minute: the reminder to anyone online who crossed `driving.warn_s` (once per window),
     * and offline for anyone who crossed `driving.max_s` with no ride running.
     */
    public function sweep(): int
    {
        $touched = 0;
        $drivers = Driver::query()->whereIn('id', DriverPresence::query()->whereIn('status', [PresenceStatus::Online->value, PresenceStatus::Paused->value, PresenceStatus::OnTrip->value])->pluck('driver_id'))->get();

        foreach ($drivers as $driver) {
            $status = $this->status($driver);
            if ($status['level'] === 'ok') {
                continue;
            }
            // Told once per window per level; the limit is enforced every time (a ride in progress may finish first).
            if (Cache::add("rides:driving-{$status['level']}:{$driver->id}", true, $status['window_h'] * 3600)) {
                $this->tell($driver, $status);
                $touched++;
            }
            if ($status['level'] === 'limit' && RideActiveLock::query()->find(RideActiveLock::forDriver($driver->id)) === null) {
                $this->presence->goOffline($driver, 'driving_limit');
                $this->changed($driver);
            }
        }

        return $touched;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function tell(Driver $driver, array $status): void
    {
        $limit = $status['level'] === 'limit';
        $hours = fn (int $s) => intdiv($s, 3600).':'.str_pad((string) intdiv($s % 3600, 60), 2, '0', STR_PAD_LEFT);
        $this->center->send($driver, $limit ? 'ride.driving_limit' : 'ride.driving_warn', $limit ? 'ride_driving_limit_title' : 'ride_driving_warn_title', $limit ? 'ride_driving_limit_body' : 'ride_driving_warn_body', [
            'used' => $hours((int) $status['used_s']),
            'max' => $hours((int) $status['max_s']),
        ], ['type' => 'driving']);
        $this->changed($driver);
    }

    private function changed(Driver $driver): void
    {
        try {
            broadcast(new DriverChanged((int) $driver->id, '', 'driving_hours'));
        } catch (Throwable) {
            // The app catches up when it next opens.
        }
    }

    /**
     * The online stretches inside the window, clipped to it, oldest first, as [start, end] unix seconds.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function intervals(Driver $driver, CarbonImmutable $now, int $windowH): array
    {
        $from = $now->subHours($windowH);

        return DriverPresenceSession::query()->where('driver_id', $driver->id)->where('started_at', '<', $now)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $from))
            ->orderBy('started_at')->get()
            ->map(fn (DriverPresenceSession $s) => [max($s->started_at->getTimestamp(), $from->getTimestamp()), min($s->ended_at?->getTimestamp() ?? $now->getTimestamp(), $now->getTimestamp())])
            ->filter(fn (array $i) => $i[1] > $i[0])->values()->all();
    }

    /**
     * When `$excess` seconds of the oldest time have slid out of the window: walk the stretches from the
     * oldest, and that moment plus the window is when the captain is under the limit again.
     *
     * @param  list<array{0: int, 1: int}>  $intervals
     */
    private function freeAt(array $intervals, int $excess, int $windowH): CarbonImmutable
    {
        foreach ($intervals as [$start, $end]) {
            if ($excess <= $end - $start) {
                return CarbonImmutable::createFromTimestamp($start + $excess)->addHours($windowH);
            }
            $excess -= $end - $start;
        }

        return CarbonImmutable::now()->addHours($windowH);
    }
}
