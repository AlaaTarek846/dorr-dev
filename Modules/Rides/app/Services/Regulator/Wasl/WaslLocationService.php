<?php

namespace Modules\Rides\Services\Regulator\Wasl;

use App\Models\Country;
use Modules\Driver\Enums\PresenceStatus;
use Modules\Driver\Models\DriverPresence;
use Modules\Rides\Enums\RegulatorStatus;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Models\DriverRegulatorStatus;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideRegulatorCity;

/**
 * Every minute (`rides:wasl-locations`): where each working driver approved by Wasl is, and
 * whether a rider is in the car (`/dispatching/v2/locations`, docs/rides-plan.md §10.3). The point
 * is the last one the phone sent (it logs every 30 s, owner's decision); a driver whose phone went
 * quiet isn't reported.
 */
class WaslLocationService
{
    public function __construct(private readonly WaslClient $client) {}

    public function send(): int
    {
        $countries = Country::query()->whereIn('code', array_keys((array) config('rides.regulators'), RideRegulatorCity::WASL, true))->pluck('id');
        if (! $this->client->configured() || $countries->isEmpty()) {
            return 0;
        }

        $approved = DriverRegulatorStatus::query()->where('regulator', RideRegulatorCity::WASL)
            ->whereIn('country_id', $countries)->where('driver_status', RegulatorStatus::Valid->value)
            ->with(['driver:id,national_id', 'vehicle:id,sequence_number'])->get()->keyBy('driver_id');
        if ($approved->isEmpty()) {
            return 0;
        }

        $presences = DriverPresence::query()->whereIn('driver_id', $approved->keys())
            ->whereIn('status', [PresenceStatus::Online->value, PresenceStatus::OnTrip->value])
            ->whereNotNull('lat')->where('last_seen_at', '>=', now()->subMinutes(2))->get();
        $withRider = Ride::query()->whereIn('driver_id', $presences->pluck('driver_id'))->where('status', RideStatus::InProgress->value)->pluck('driver_id')->flip();

        $points = $presences->map(fn (DriverPresence $p) => [
            'driverIdentityNumber' => $approved[$p->driver_id]->driver?->national_id,
            'vehicleSequenceNumber' => $approved[$p->driver_id]->vehicle?->sequence_number,
            'hasCustomer' => $withRider->has($p->driver_id),
            'latitude' => $p->lat,
            'longitude' => $p->lng,
            'updatedWhen' => WaslClient::time($p->last_seen_at),
        ])->filter(fn (array $p) => filled($p['driverIdentityNumber']) && filled($p['vehicleSequenceNumber']))->values();

        foreach ($points->chunk((int) config('rides.wasl.locations_per_request', 1000)) as $chunk) {
            $this->client->post('/dispatching/v2/locations', ['locations' => $chunk->values()->all()], 'locations');
        }

        return $points->count();
    }
}
