<?php

namespace Modules\Rides\Services\Places;

use Illuminate\Support\Collection;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Models\Ride;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\User\Models\Address;
use Modules\User\Models\User;

/**
 * The rider's shortcuts for "where to?" (R-5, R-174): Home and Work, named favourite places, and the
 * destinations of recent rides. Saved places are the account's own addresses (`user_addresses`: home /
 * work / other with a pinned point), so they follow the rider to every device and every DORR service;
 * recent destinations come from the rides themselves. Only places with a point are offered.
 */
class SavedPlaces
{
    /** Two points this close (≈ 100 m) are the same place. */
    private const SAME_PLACE_DECIMALS = 3;

    public function __construct(private readonly RideFeatures $features, private readonly RidePolicies $policies) {}

    /**
     * @return array{home: array<string, mixed>|null, work: array<string, mixed>|null, favorites: list<array<string, mixed>>, recent: list<array<string, mixed>>}
     */
    public function for(User $rider): array
    {
        $empty = ['home' => null, 'work' => null, 'favorites' => [], 'recent' => []];
        if ($rider->country_id === null || ! $this->features->allows((int) $rider->country_id, null, 'saved_places')) {
            return $empty;
        }
        $policy = $this->policies->for((int) $rider->country_id);

        $addresses = Address::query()->where('user_id', $rider->id)->whereNotNull('latitude')->whereNotNull('longitude')->latest('id')->get();
        $home = $addresses->firstWhere('type', 'home');
        $work = $addresses->firstWhere('type', 'work');
        $favorites = $addresses->where('type', 'other')->take(max(0, $policy->int('places.favorites_max')))->values();

        $saved = collect([$home, $work])->filter()->merge($favorites)->map(fn (Address $a) => $this->key((float) $a->latitude, (float) $a->longitude));

        return [
            'home' => $home === null ? null : $this->fromAddress($home, 'home'),
            'work' => $work === null ? null : $this->fromAddress($work, 'work'),
            'favorites' => $favorites->map(fn (Address $a) => $this->fromAddress($a, 'favorite'))->all(),
            'recent' => $this->recent($rider, max(0, $policy->int('places.recent_max')), $saved),
        ];
    }

    /**
     * The last distinct destinations of the rider's rides that took place, newest first, leaving out
     * what is already saved.
     *
     * @param  Collection<int, string>  $saved
     * @return list<array<string, mixed>>
     */
    private function recent(User $rider, int $limit, Collection $saved): array
    {
        if ($limit === 0) {
            return [];
        }
        $seen = $saved->flip()->all();
        $out = [];
        $rides = Ride::query()->where('user_id', $rider->id)->where('status', RideStatus::Completed->value)->whereNotNull('destination_lat')
            ->latest('id')->limit(60)->get(['id', 'destination_lat', 'destination_lng', 'destination_address']);
        foreach ($rides as $ride) {
            $key = $this->key((float) $ride->destination_lat, (float) $ride->destination_lng);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['id' => null, 'kind' => 'recent', 'title' => null, 'address' => $ride->destination_address, 'lat' => (float) $ride->destination_lat, 'lng' => (float) $ride->destination_lng];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function fromAddress(Address $address, string $kind): array
    {
        return [
            'id' => $address->id,
            'kind' => $kind,
            'title' => $address->title,
            'address' => $address->address_details ?: $address->title,
            'lat' => (float) $address->latitude,
            'lng' => (float) $address->longitude,
        ];
    }

    private function key(float $lat, float $lng): string
    {
        return round($lat, self::SAME_PLACE_DECIMALS).','.round($lng, self::SAME_PLACE_DECIMALS);
    }
}
