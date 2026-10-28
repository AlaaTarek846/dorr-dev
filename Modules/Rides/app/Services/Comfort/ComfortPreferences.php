<?php

namespace Modules\Rides\Services\Comfort;

use Illuminate\Support\Facades\DB;
use Modules\Driver\Models\Driver;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RiderRideProfile;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Lifecycle\RideStateMachine;
use Modules\Rides\Services\Notifications\RideNotifier;
use Modules\User\Models\User;

/**
 * «راحتي» — how the rider likes the trip (Block 06 phase 7; R-INN-04 / Trip Radar): a quiet ride,
 * the air conditioning (moderate / cold / whatever is available), music (none / as agreed) and a few
 * notes from a fixed list. They are **requests, never guarantees**, and never above safety: a quiet
 * ride still hears what the trip needs, and no free text is taken, so nothing sensitive is shared.
 *
 * Saved once on the rider's profile, copied onto each ride booked where the `comfort` switch is on
 * (or changed for that ride only), shown to the captain on the request card and the ride. The captain
 * can say an item can't be done and why; the rider is told, and it's kept on the ride.
 */
class ComfortPreferences
{
    public const AC = ['any', 'moderate', 'cold'];

    public const MUSIC = ['any', 'none', 'agreed'];

    /** The only notes a rider can add — no free text (nothing sensitive, nothing against safety). */
    public const NOTES = ['help_with_bags', 'message_on_arrival', 'no_strong_scent', 'window_open', 'gentle_driving'];

    /** Why a captain can't do one of them. */
    public const UNABLE_REASONS = ['not_available', 'vehicle_issue', 'safety', 'traffic_rules', 'other'];

    public function __construct(private readonly RideFeatures $features, private readonly RideNotifier $notifier, private readonly RideStateMachine $machine) {}

    /** @return array{quiet: bool, ac: string, music: string, notes: list<string>} */
    public function saved(User $rider): array
    {
        return self::clean((array) (RiderRideProfile::query()->find($rider->id)?->comfort ?? []));
    }

    /**
     * @param  array<string, mixed>  $prefs
     * @return array{quiet: bool, ac: string, music: string, notes: list<string>}
     */
    public function save(User $rider, array $prefs): array
    {
        $clean = self::clean($prefs);
        RiderRideProfile::query()->updateOrCreate(['user_id' => $rider->id], ['comfort' => $clean]);

        return $clean;
    }

    /** What the app needs for the «راحتي» sheet. */
    public function screen(User $rider, bool $enabled): array
    {
        return [
            'enabled' => $enabled,
            'prefs' => $this->saved($rider),
            'options' => [
                'ac' => array_map(fn (string $v) => ['value' => $v, 'label' => __('rides.comfort.ac.'.$v)], self::AC),
                'music' => array_map(fn (string $v) => ['value' => $v, 'label' => __('rides.comfort.music.'.$v)], self::MUSIC),
                'notes' => array_map(fn (string $v) => ['value' => $v, 'label' => __('rides.comfort.notes.'.$v)], self::NOTES),
            ],
            'disclaimer' => __('rides.comfort.disclaimer'),
        ];
    }

    /**
     * After booking: the rider's «راحتي» (or what they chose for this ride) goes with it — only where the
     * switch is on, and only when something was asked for.
     *
     * @param  array<string, mixed>|null  $override
     */
    public function attach(Ride $ride, User $rider, ?array $override): void
    {
        if ($ride->comfort !== null || ! $this->features->allows((int) $ride->country_id, $ride->city_id, 'comfort')) {
            return;
        }
        $prefs = $override !== null ? self::clean($override) : $this->saved($rider);
        if (! self::asksForSomething($prefs)) {
            return;
        }
        $ride->forceFill(['comfort' => $prefs + ['unable' => []]])->save();
    }

    /** The captain can't do one of the items: kept on the ride, and the rider is told why. */
    public function unable(Driver $driver, Ride $ride, string $item, string $reason): Ride
    {
        if ($ride->driver_id !== $driver->id) {
            throw new RidesException('ride_not_found', 404);
        }
        if (! in_array($ride->status, [RideStatus::Accepted, RideStatus::Arrived, RideStatus::InProgress], true)) {
            throw new RidesException('comfort_closed', 422);
        }
        $comfort = (array) ($ride->comfort ?? []);
        $asked = self::askedItems($comfort);
        if (! in_array($item, $asked, true)) {
            throw new RidesException('comfort_item_unknown', 422, [], ['items' => $asked]);
        }
        if (! in_array($reason, self::UNABLE_REASONS, true)) {
            throw new RidesException('comfort_reason_unknown', 422, [], ['reasons' => self::UNABLE_REASONS]);
        }
        $unable = collect((array) ($comfort['unable'] ?? []));
        if ($unable->contains(fn ($u) => ($u['item'] ?? null) === $item)) {
            return $ride; // said once is enough
        }

        $ride = DB::transaction(function () use ($ride, $comfort, $unable, $item, $reason, $driver) {
            $ride->forceFill(['comfort' => ['unable' => $unable->push(['item' => $item, 'reason' => $reason, 'at' => now()->toIso8601String()])->values()->all()] + $comfort])->save();
            $this->machine->record($ride, 'comfort_unable', 'driver', $driver->id, [], ['item' => $item, 'reason' => $reason]);

            return $ride;
        });
        $this->notifier->rider($ride, 'comfort_unable', ['item' => self::itemLabel($item, $comfort), 'reason' => __('rides.comfort.unable_reasons.'.$reason)]);
        $this->notifier->changed($ride, 'comfort_unable');

        return $ride;
    }

    /**
     * How a ride's «راحتي» reads in the apps (both sides), or null when the rider asked for nothing.
     *
     * @return array<string, mixed>|null
     */
    public static function present(Ride $ride): ?array
    {
        $comfort = $ride->comfort;
        if (! is_array($comfort) || ! self::asksForSomething($comfort)) {
            return null;
        }
        $items = [];
        foreach (self::askedItems($comfort) as $item) {
            $items[] = ['item' => $item, 'label' => self::itemLabel($item, $comfort)];
        }

        return [
            'quiet' => (bool) ($comfort['quiet'] ?? false),
            'ac' => $comfort['ac'] ?? 'any',
            'music' => $comfort['music'] ?? 'any',
            'notes' => array_values((array) ($comfort['notes'] ?? [])),
            'items' => $items,
            'unable' => collect((array) ($comfort['unable'] ?? []))->map(fn ($u) => [
                'item' => $u['item'] ?? null, 'label' => self::itemLabel((string) ($u['item'] ?? ''), $comfort),
                'reason' => $u['reason'] ?? null, 'reason_label' => __('rides.comfort.unable_reasons.'.($u['reason'] ?? 'other')), 'at' => $u['at'] ?? null,
            ])->values()->all(),
            'reasons' => array_map(fn (string $r) => ['value' => $r, 'label' => __('rides.comfort.unable_reasons.'.$r)], self::UNABLE_REASONS),
            'disclaimer' => __('rides.comfort.disclaimer'),
        ];
    }

    /**
     * @param  array<string, mixed>  $prefs
     * @return array{quiet: bool, ac: string, music: string, notes: list<string>}
     */
    public static function clean(array $prefs): array
    {
        return [
            'quiet' => (bool) ($prefs['quiet'] ?? false),
            'ac' => in_array($prefs['ac'] ?? null, self::AC, true) ? $prefs['ac'] : 'any',
            'music' => in_array($prefs['music'] ?? null, self::MUSIC, true) ? $prefs['music'] : 'any',
            'notes' => array_values(array_unique(array_filter((array) ($prefs['notes'] ?? []), fn ($n) => in_array($n, self::NOTES, true)))),
        ];
    }

    /** @param  array<string, mixed>  $prefs */
    private static function asksForSomething(array $prefs): bool
    {
        return self::askedItems($prefs) !== [];
    }

    /**
     * The items a captain answers for: quiet, ac, music, and each note.
     *
     * @param  array<string, mixed>  $prefs
     * @return list<string>
     */
    private static function askedItems(array $prefs): array
    {
        $items = [];
        if (! empty($prefs['quiet'])) {
            $items[] = 'quiet';
        }
        if (($prefs['ac'] ?? 'any') !== 'any') {
            $items[] = 'ac';
        }
        if (($prefs['music'] ?? 'any') !== 'any') {
            $items[] = 'music';
        }
        foreach ((array) ($prefs['notes'] ?? []) as $note) {
            if (in_array($note, self::NOTES, true)) {
                $items[] = $note;
            }
        }

        return $items;
    }

    /** @param  array<string, mixed>  $comfort */
    private static function itemLabel(string $item, array $comfort): string
    {
        return match ($item) {
            'quiet' => __('rides.comfort.quiet'),
            'ac', 'music' => __('rides.comfort.'.$item.'_label').': '.__('rides.comfort.'.$item.'.'.($comfort[$item] ?? 'any')),
            default => __('rides.comfort.notes.'.$item),
        };
    }
}
