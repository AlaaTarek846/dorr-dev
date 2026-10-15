<?php

namespace Modules\Rides\Services\Safety;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Driver\Models\Driver;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\DriverRideProfile;
use Modules\Rides\Models\FavoriteDriver;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideBlock;
use Modules\Rides\Models\RideSafetyCheck;
use Modules\Rides\Models\RideTrustedContact;
use Modules\User\Models\User;

/**
 * The Safety Center (R-219, D-140, D-381) — one place for the person's safety settings:
 * trusted contacts (R-220, D-143), preferences (R-229), who they won't be matched with again
 * (R-225, D-151, D-187, D-410) and — for a rider — favourite captains (R-226). The trusted
 * contacts of one side are never shown to the other (D-159).
 */
class SafetyCenterService
{
    /** @return array<string, mixed> */
    public function overview(Model $owner): array
    {
        $prefs = SafetyContacts::preferences($owner);

        return [
            'contacts' => $this->contacts($owner),
            'preferences' => [
                'pin_mode' => $prefs->pin_mode ?? 'policy',
                'checkin_after_min' => $prefs->checkin_after_min,
                'seatbelt_reminder' => $prefs->seatbelt_reminder ?? true,
            ],
            'blocked' => $this->blocked($owner),
            'favorites' => $owner instanceof User ? $this->favorites($owner) : [],
            'emergency_number' => (string) (config('rides.policy_defaults')['safety.emergency_number'] ?? '911'),
            // An "Are you OK?" still waiting — e.g. the check-in after a ride that already ended (R-223).
            'pending_check' => ($check = RideSafetyCheck::query()->where('target_type', SafetyContacts::typeOf($owner))->where('target_id', $owner->getKey())
                ->whereNotNull('asked_at')->whereNull('answered_at')->where('asked_at', '>=', now()->subDay())->with('ride:id,uuid')->latest('id')->first()) === null
                ? null : SafetyCheckService::present($check) + ['ride_uuid' => $check->ride?->uuid],
            'max_contacts' => (int) (config('rides.policy_defaults')['safety.max_contacts'] ?? 5),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function contacts(Model $owner): array
    {
        return RideTrustedContact::query()->where('owner_type', SafetyContacts::typeOf($owner))->where('owner_id', $owner->getKey())->orderBy('id')->get()
            ->map(fn (RideTrustedContact $c) => ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'share_mode' => $c->share_mode, 'notify_arrival' => $c->notify_arrival])
            ->values()->all();
    }

    /** @param array{name: string, phone: string, share_mode?: string, notify_arrival?: bool} $data */
    public function saveContact(Model $owner, array $data, ?int $id = null): RideTrustedContact
    {
        $type = SafetyContacts::typeOf($owner);
        $phone = preg_replace('/[^\d+]/', '', $data['phone']);
        if ($phone === (string) $owner->phone) {
            throw new RidesException('contact_is_you', 422);
        }
        if ($id === null) {
            $count = RideTrustedContact::query()->where('owner_type', $type)->where('owner_id', $owner->getKey())->count();
            $max = (int) (config('rides.policy_defaults')['safety.max_contacts'] ?? 5);
            if ($count >= $max) {
                throw new RidesException('contacts_limit', 422, ['max' => $max]);
            }
        }
        $contact = $id === null
            ? new RideTrustedContact(['owner_type' => $type, 'owner_id' => $owner->getKey()])
            : RideTrustedContact::query()->where('owner_type', $type)->where('owner_id', $owner->getKey())->whereKey($id)->first() ?? throw new RidesException('contact_not_found', 404);
        $contact->fill([
            'name' => Str::limit(trim($data['name']), 80, ''),
            'phone' => $phone,
            'share_mode' => $data['share_mode'] ?? $contact->share_mode ?? 'manual',
            'notify_arrival' => $data['notify_arrival'] ?? $contact->notify_arrival ?? true,
        ])->save();

        return $contact;
    }

    public function deleteContact(Model $owner, int $id): void
    {
        RideTrustedContact::query()->where('owner_type', SafetyContacts::typeOf($owner))->where('owner_id', $owner->getKey())->whereKey($id)->delete();
    }

    /** @param array{pin_mode?: string, checkin_after_min?: ?int, seatbelt_reminder?: bool} $data */
    public function savePreferences(Model $owner, array $data): void
    {
        $prefs = SafetyContacts::preferences($owner);
        $prefs->fill(array_intersect_key($data, array_flip(['pin_mode', 'checkin_after_min', 'seatbelt_reminder'])));
        $prefs->pin_mode ??= 'policy';
        $prefs->save();
    }

    /** Never be matched again with the other person of this ride. */
    public function block(Model $owner, Ride $ride, ?string $reason): void
    {
        $user = $owner instanceof User;
        $otherId = $user ? $ride->driver_id : $ride->user_id;
        if ($otherId === null) {
            throw new RidesException('nobody_to_block', 409);
        }
        RideBlock::query()->firstOrCreate(
            ['blocker_type' => $user ? 'user' : 'driver', 'blocker_id' => $owner->getKey(), 'blocked_type' => $user ? 'driver' : 'user', 'blocked_id' => $otherId],
            ['ride_id' => $ride->id, 'reason' => $reason, 'created_at' => now()],
        );
        if ($user) {
            FavoriteDriver::query()->where('user_id', $owner->getKey())->where('driver_id', $otherId)->delete();
        }
    }

    public function unblock(Model $owner, int $id): void
    {
        RideBlock::query()->where('blocker_type', SafetyContacts::typeOf($owner))->where('blocker_id', $owner->getKey())->whereKey($id)->delete();
    }

    /** @return list<array<string, mixed>> */
    public function blocked(Model $owner): array
    {
        $user = $owner instanceof User;

        return RideBlock::query()->where('blocker_type', $user ? 'user' : 'driver')->where('blocker_id', $owner->getKey())->latest('id')->get()
            ->map(function (RideBlock $b) use ($user) {
                $other = $user ? Driver::query()->find($b->blocked_id) : User::query()->find($b->blocked_id);

                return ['id' => $b->id, 'name' => Str::before(trim((string) $other?->name), ' ') ?: null, 'since' => $b->created_at?->toIso8601String()];
            })->values()->all();
    }

    /** A rider marks the captain of this ride a favourite (R-226), or not any more. */
    public function favorite(User $rider, Ride $ride, bool $on): void
    {
        if ($ride->driver_id === null) {
            throw new RidesException('nobody_to_block', 409);
        }
        if ($on) {
            FavoriteDriver::query()->firstOrCreate(['user_id' => $rider->id, 'driver_id' => $ride->driver_id], ['created_at' => now()]);
            RideBlock::query()->where('blocker_type', 'user')->where('blocker_id', $rider->id)->where('blocked_type', 'driver')->where('blocked_id', $ride->driver_id)->delete();
        } else {
            FavoriteDriver::query()->where('user_id', $rider->id)->where('driver_id', $ride->driver_id)->delete();
        }
    }

    public function unfavorite(User $rider, int $driverId): void
    {
        FavoriteDriver::query()->where('user_id', $rider->id)->where('driver_id', $driverId)->delete();
    }

    /** @return list<array<string, mixed>> */
    public function favorites(User $rider): array
    {
        return FavoriteDriver::query()->where('user_id', $rider->id)->latest('id')->get()
            ->map(function (FavoriteDriver $f) {
                $driver = Driver::query()->find($f->driver_id);
                $profile = DriverRideProfile::query()->find($f->driver_id);

                return [
                    'driver_id' => $f->driver_id,
                    'name' => Str::before(trim((string) $driver?->name), ' ') ?: null,
                    'avatar_url' => $driver?->getFirstMediaUrl('avatar') ?: null,
                    'rating' => $profile !== null && $profile->rating_count > 0 ? (float) $profile->rating_avg : null,
                ];
            })->values()->all();
    }

    public static function isFavorite(int $userId, ?int $driverId): bool
    {
        return $driverId !== null && FavoriteDriver::query()->where('user_id', $userId)->where('driver_id', $driverId)->exists();
    }
}
