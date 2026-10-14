<?php

namespace Modules\Rides\Services\Safety;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverPresence;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideShare;
use Modules\Rides\Services\Features\RideFeatures;

/**
 * Sharing a ride with family (R-86, R-111, R-113, D-142): the rider or the captain gets one link per
 * ride; whoever opens it sees the ride's state, the car and where it is — no phone numbers, no last
 * names — until the ride ends ("arrived safely"), and nothing `safety.share_ttl_after_end_s` later
 * or once revoked.
 */
class RideShareService
{
    public function __construct(private readonly RideFeatures $features) {}

    public function share(Ride $ride, Model $sharer): RideShare
    {
        if (! $this->features->allows($ride->country_id, $ride->city_id, 'share')) {
            throw RidesException::featureDisabled('share');
        }
        if ($ride->status->isFinal() || $ride->status === RideStatus::AwaitingPayment) {
            throw new RidesException('share_unavailable', 409);
        }
        $type = self::typeOf($sharer);

        return RideShare::query()->where('ride_id', $ride->id)->where('sharer_type', $type)->where('sharer_id', $sharer->getKey())->whereNull('revoked_at')->first()
            ?? RideShare::query()->create([
                'ride_id' => $ride->id,
                'token' => Str::random(40),
                'sharer_type' => $type,
                'sharer_id' => $sharer->getKey(),
            ]);
    }

    public function revoke(Ride $ride, Model $sharer): void
    {
        RideShare::query()->where('ride_id', $ride->id)->where('sharer_type', self::typeOf($sharer))->where('sharer_id', $sharer->getKey())
            ->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    public function active(Ride $ride, Model $sharer): ?RideShare
    {
        return RideShare::query()->where('ride_id', $ride->id)->where('sharer_type', self::typeOf($sharer))->where('sharer_id', $sharer->getKey())->whereNull('revoked_at')->first();
    }

    /**
     * What the link shows. Null = no such link, revoked, or expired.
     *
     * @return array<string, mixed>|null
     */
    public function view(string $token, bool $count = true): ?array
    {
        $share = RideShare::query()->where('token', $token)->whereNull('revoked_at')->with('ride')->first();
        $ride = $share?->ride;
        if ($ride === null) {
            return null;
        }
        $endedAt = $ride->completed_at ?? $ride->canceled_at ?? $ride->expired_at;
        if ($ride->status->isFinal() && $endedAt !== null && $endedAt->copy()->addSeconds((int) $ride->policy('safety.share_ttl_after_end_s', 3600))->isPast()) {
            return null;
        }
        if ($count) {
            $share->forceFill(['views' => $share->views + 1, 'last_viewed_at' => now()])->save();
        }

        $ride->loadMissing(['category.translations', 'stops', 'driver', 'rider']);
        $live = $ride->status->hasDriver();
        $presence = $live && $ride->driver_id !== null ? DriverPresence::query()->find($ride->driver_id) : null;
        $vehicle = (array) $ride->vehicle_snapshot;
        $firstName = fn (?string $name) => $name === null ? null : Str::before(trim($name), ' ');
        $sharerName = $share->sharer_type === 'driver' ? $ride->driver?->name : $ride->rider?->name;

        return [
            'status' => $ride->status->value,
            'status_label' => __('rides.share.status.'.$ride->status->value),
            'shared_by' => ['type' => $share->sharer_type, 'name' => $firstName($sharerName)],
            'category' => $ride->category?->translated('name'),
            'origin' => ['address' => $ride->origin_address, 'lat' => $ride->origin_lat, 'lng' => $ride->origin_lng],
            'destination' => $ride->destination_lat === null ? null : ['address' => $ride->destination_address, 'lat' => $ride->destination_lat, 'lng' => $ride->destination_lng],
            'stops' => $ride->stops->map(fn ($s) => ['address' => $s->address, 'lat' => (float) $s->lat, 'lng' => (float) $s->lng, 'done' => $s->status === 'done'])->values(),
            'polyline' => $ride->planned_polyline,
            'driver' => $ride->driver_id === null ? null : [
                'name' => $firstName($ride->driver?->name),
                'avatar_url' => $ride->driver?->getFirstMediaUrl('avatar') ?: null,
                'vehicle' => trim(($vehicle['make'] ?? '').' '.($vehicle['model'] ?? '')) ?: null,
                'color' => $vehicle['color'] ?? null,
                'color_hex' => $vehicle['color_hex'] ?? null,
                'plate' => trim(($vehicle['plate_letters'] ?? '').' '.($vehicle['plate_number'] ?? '')) ?: null,
            ],
            // Where the car is — only while the ride runs.
            'location' => $presence?->lat === null ? null : ['lat' => (float) $presence->lat, 'lng' => (float) $presence->lng, 'heading' => $presence->heading, 'at' => $presence->last_seen_at?->toIso8601String()],
            'times' => [
                'scheduled_at' => $ride->scheduled_at?->toIso8601String(),
                'started_at' => $ride->started_at?->toIso8601String(),
                'completed_at' => $ride->completed_at?->toIso8601String(),
            ],
            'arrived_safely' => $ride->status === RideStatus::Completed,
            'emergency_number' => (string) $ride->policy('safety.emergency_number', config('rides.policy_defaults')['safety.emergency_number'] ?? '911'),
        ];
    }

    private static function typeOf(Model $sharer): string
    {
        return $sharer instanceof Driver ? 'driver' : 'user';
    }
}
