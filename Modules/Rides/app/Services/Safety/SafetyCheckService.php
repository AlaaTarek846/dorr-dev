<?php

namespace Modules\Rides\Services\Safety;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverLocationLog;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideSafetyCheck;
use Modules\Rides\Models\RideSafetyPreference;
use Modules\Rides\Models\RideStop;
use Modules\Rides\Services\Notifications\RideNotifier;
use Modules\Rides\Support\Geo;
use Modules\Rides\Support\Polyline;
use Modules\User\Models\User;

/**
 * "Are you OK?" (R-112, R-222, R-223, D-144, D-145, D-396):
 *  - **Ride Check:** during the ride, the car standing still for `safety.stop_alert_s` away from a
 *    stop or the destination, or `safety.deviation_m` off the planned route → both the rider and the
 *    captain are asked (one of a kind per ride per `safety.check_cooldown_s`).
 *  - **Check-In:** the rider may ask to be checked on some minutes after arriving (R-223).
 * "I'm OK" closes it; "I need help" is an SOS; no answer in `safety.check_answer_s` → an incident for
 * the safety team and an alert to that person's trusted contacts. Checks run with the dispatch tick.
 */
class SafetyCheckService
{
    public function __construct(
        private readonly RideNotifier $notifier,
        private readonly SafetyIncidentService $incidents,
        private readonly SafetyContacts $contacts,
    ) {}

    public function tick(): void
    {
        $this->detect();
        $this->ask();
        $this->escalate();
    }

    /** Rides on the way that stand still too long or left their route. */
    public function detect(): void
    {
        Ride::query()->where('status', RideStatus::InProgress->value)->whereNotNull('started_at')->get()
            ->each(function (Ride $ride) {
                $stopFor = (int) $ride->policy('safety.stop_alert_s', 300);
                $points = DriverLocationLog::query()->where('ride_id', $ride->id)->where('recorded_at', '>=', now()->subSeconds($stopFor))
                    ->orderBy('recorded_at')->get(['lat', 'lng', 'recorded_at']);
                $last = $points->last();
                if ($last === null) {
                    return;
                }
                [$lat, $lng] = [(float) $last->lat, (float) $last->lng];

                if ($ride->started_at->lte(now()->subSeconds($stopFor)) && $points->count() >= 3
                    && $points->first()->recorded_at->lte(now()->subSeconds((int) ($stopFor * 0.8)))
                    && $points->every(fn ($p) => Geo::metres($lat, $lng, (float) $p->lat, (float) $p->lng) <= 60)
                    && ! $this->nearAPlannedStop($ride, $lat, $lng)) {
                    $this->raise($ride, 'long_stop', ['lat' => $lat, 'lng' => $lng, 'stopped_s' => $stopFor]);

                    return;
                }

                $route = $ride->planned_polyline ? Polyline::decode($ride->planned_polyline) : [];
                if (count($route) > 1) {
                    $off = self::distanceToRoute($lat, $lng, $route);
                    if ($off > (int) $ride->policy('safety.deviation_m', 1500)) {
                        $this->raise($ride, 'deviation', ['lat' => $lat, 'lng' => $lng, 'off_m' => (int) round($off)]);
                    }
                }
            });
    }

    /** The rider asked to be checked on after arriving (R-223): due some minutes after the end. */
    public function scheduleCheckIn(Ride $ride): void
    {
        $after = RideSafetyPreference::query()->where('owner_type', 'user')->where('owner_id', $ride->user_id)->value('checkin_after_min');
        if ($after === null || (int) $after <= 0) {
            return;
        }
        RideSafetyCheck::query()->create([
            'uuid' => (string) Str::uuid(), 'ride_id' => $ride->id, 'kind' => 'checkin', 'reason' => 'scheduled',
            'target_type' => 'user', 'target_id' => $ride->user_id, 'due_at' => now()->addMinutes((int) $after),
        ]);
    }

    /** Checks whose time came: the person is asked (a push; the app shows the sheet). */
    public function ask(): void
    {
        RideSafetyCheck::query()->whereNull('asked_at')->where('due_at', '<=', now())->with('ride')->get()
            ->each(function (RideSafetyCheck $check) {
                if (RideSafetyCheck::query()->whereKey($check->id)->whereNull('asked_at')->update(['asked_at' => now()]) === 0) {
                    return;
                }
                $key = $check->kind === 'checkin' ? 'checkin' : 'ride_check';
                $person = $this->person($check);
                if ($person instanceof Driver) {
                    $this->notifier->driver($person, $check->ride, $key);
                } elseif ($person instanceof User) {
                    $this->notifier->rider($check->ride, $key);
                }
                $this->notifier->changed($check->ride, 'safety_check');
            });
    }

    /** No answer in time: the safety team and the person's trusted contacts are told. */
    public function escalate(): void
    {
        RideSafetyCheck::query()->whereNotNull('asked_at')->whereNull('answered_at')->whereNull('escalated_at')->with('ride')->get()
            ->filter(fn (RideSafetyCheck $c) => $c->asked_at->copy()->addSeconds((int) $c->ride->policy('safety.check_answer_s', 120))->isPast())
            ->each(function (RideSafetyCheck $check) {
                if (RideSafetyCheck::query()->whereKey($check->id)->whereNull('escalated_at')->update(['escalated_at' => now()]) === 0) {
                    return;
                }
                $meta = (array) $check->meta;
                $this->incidents->unanswered($check->ride, $check->target_type, $check->target_id, $check->kind === 'checkin' ? 'checkin_missed' : 'ride_check', $meta['lat'] ?? null, $meta['lng'] ?? null);
                if (($person = $this->person($check)) !== null) {
                    $this->contacts->alert($check->ride, $person);
                }
            });
    }

    /**
     * "I'm OK" or "I need help" (the latter is an SOS).
     *
     * @return array{check: RideSafetyCheck, emergency_number: ?string}
     */
    public function answer(Ride $ride, Model $person, string $uuid, string $answer, ?float $lat = null, ?float $lng = null): array
    {
        $check = RideSafetyCheck::query()->where('ride_id', $ride->id)->where('uuid', $uuid)
            ->where('target_type', SafetyContacts::typeOf($person))->where('target_id', $person->getKey())->first()
            ?? throw new RidesException('safety_check_not_found', 404);
        if ($check->answered_at === null) {
            $check->update(['answered_at' => now(), 'answer' => $answer]);
        }
        $number = null;
        if ($answer === 'help') {
            $number = $this->incidents->sos($ride, $person, $lat, $lng, null, __('rides.safety_types.'.$check->kind))['emergency_number'];
        }

        return ['check' => $check, 'emergency_number' => $number];
    }

    /** The open question for this person on this ride, if any (the apps show it). */
    public static function current(Ride $ride, Model $person): ?RideSafetyCheck
    {
        return RideSafetyCheck::query()->where('ride_id', $ride->id)
            ->where('target_type', SafetyContacts::typeOf($person))->where('target_id', $person->getKey())
            ->whereNotNull('asked_at')->whereNull('answered_at')->latest('id')->first();
    }

    /** @return array<string, mixed> */
    public static function present(RideSafetyCheck $check): array
    {
        return [
            'uuid' => $check->uuid,
            'kind' => $check->kind,
            'reason' => $check->reason,
            'asked_at' => $check->asked_at?->toIso8601String(),
            'escalated' => $check->escalated_at !== null,
        ];
    }

    /** @param array<string, mixed> $meta */
    private function raise(Ride $ride, string $reason, array $meta): void
    {
        $cooldown = (int) $ride->policy('safety.check_cooldown_s', 900);
        if (RideSafetyCheck::query()->where('ride_id', $ride->id)->where('reason', $reason)->where('created_at', '>=', now()->subSeconds($cooldown))->exists()) {
            return;
        }
        DB::transaction(function () use ($ride, $reason, $meta) {
            foreach (array_filter([['user', $ride->user_id], $ride->driver_id ? ['driver', $ride->driver_id] : null]) as [$type, $id]) {
                RideSafetyCheck::query()->create([
                    'uuid' => (string) Str::uuid(), 'ride_id' => $ride->id, 'kind' => 'ride_check', 'reason' => $reason,
                    'target_type' => $type, 'target_id' => $id, 'due_at' => now(), 'meta' => $meta,
                ]);
            }
        });
    }

    /**
     * Metres from a point to the nearest segment of a route (flat projection — fine at city scale).
     *
     * @param  list<array{0: float, 1: float}>  $route
     */
    public static function distanceToRoute(float $lat, float $lng, array $route): float
    {
        $kx = 111320 * cos(deg2rad($lat));
        $ky = 110540;
        $best = INF;
        for ($i = 1, $n = count($route); $i < $n; $i++) {
            [$ax, $ay] = [($route[$i - 1][1] - $lng) * $kx, ($route[$i - 1][0] - $lat) * $ky];
            [$bx, $by] = [($route[$i][1] - $lng) * $kx, ($route[$i][0] - $lat) * $ky];
            [$dx, $dy] = [$bx - $ax, $by - $ay];
            $len = $dx * $dx + $dy * $dy;
            $t = $len > 0 ? max(0, min(1, -($ax * $dx + $ay * $dy) / $len)) : 0;
            $best = min($best, hypot($ax + $t * $dx, $ay + $t * $dy));
        }

        return $best;
    }

    private function nearAPlannedStop(Ride $ride, float $lat, float $lng): bool
    {
        $points = RideStop::query()->where('ride_id', $ride->id)->get(['lat', 'lng'])->map(fn ($s) => [(float) $s->lat, (float) $s->lng])->all();
        if ($ride->destination_lat !== null) {
            $points[] = [(float) $ride->destination_lat, (float) $ride->destination_lng];
        }
        foreach ($points as [$pLat, $pLng]) {
            if (Geo::metres($lat, $lng, $pLat, $pLng) <= 150) {
                return true;
            }
        }

        return false;
    }

    private function person(RideSafetyCheck $check): ?Model
    {
        return $check->target_type === 'driver' ? Driver::query()->find($check->target_id) : User::query()->find($check->target_id);
    }
}
