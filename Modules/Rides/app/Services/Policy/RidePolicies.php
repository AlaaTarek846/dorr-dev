<?php

namespace Modules\Rides\Services\Policy;

use App\Services\General\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Rides\Models\RidePolicy;

/**
 * Resolves and saves ride policies (docs/rides-structure.md §2.4). Scopes from broadest to most
 * specific — each key takes its value from the most specific row that sets it:
 *
 *   country → country+category → city → city+category → zone → zone+category
 */
class RidePolicies
{
    private const CACHE = 'rides:policies';

    public function __construct(private readonly AuditLogger $audit) {}

    /** `$skipPolicyId`: leave one row out — what a scope inherits from the broader ones (the admin's form). */
    public function for(int $countryId, ?int $cityId = null, ?int $zoneId = null, ?int $categoryId = null, ?int $skipPolicyId = null): RidePolicyData
    {
        $rows = $this->rows()->where('country_id', $countryId);

        $scopes = [
            [null, null, null],
            [null, null, $categoryId],
            [$cityId, null, null],
            [$cityId, null, $categoryId],
            [$cityId, $zoneId, null],
            [$cityId, $zoneId, $categoryId],
        ];

        $layers = [];
        $ids = [];
        $seen = [];
        // A scope whose part wasn't asked for collapses onto a broader one; $seen keeps each row once.
        foreach ($scopes as [$city, $zone, $category]) {
            // A zone sits in one place, so its rows apply whatever city the trip resolved to.
            $row = $rows->first(fn (RidePolicy $p) => $p->zone_id === $zone && $p->category_id === $category && ($zone !== null || $p->city_id === $city));
            if ($row === null || isset($seen[$row->id]) || $row->id === $skipPolicyId) {
                continue;
            }
            $seen[$row->id] = true;
            $layers[] = (array) $row->data;
            $ids[] = $row->id;
        }

        return RidePolicyData::merge($layers, $ids);
    }

    /**
     * Creates or replaces the keys of one scope (validated, audited). A scope is one row: the
     * unique index can't see NULL columns, so this looks the row up itself under a lock.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(int $countryId, ?int $cityId, ?int $zoneId, ?int $categoryId, array $data): RidePolicy
    {
        RidePolicyData::validate($data);

        return DB::transaction(function () use ($countryId, $cityId, $zoneId, $categoryId, $data) {
            $policy = RidePolicy::query()
                ->where('country_id', $countryId)
                ->where(fn ($q) => $cityId === null ? $q->whereNull('city_id') : $q->where('city_id', $cityId))
                ->where(fn ($q) => $zoneId === null ? $q->whereNull('zone_id') : $q->where('zone_id', $zoneId))
                ->where(fn ($q) => $categoryId === null ? $q->whereNull('category_id') : $q->where('category_id', $categoryId))
                ->lockForUpdate()
                ->first();

            $before = $policy?->data;
            $policy ??= new RidePolicy(['country_id' => $countryId, 'city_id' => $cityId, 'zone_id' => $zoneId, 'category_id' => $categoryId]);
            $policy->data = $data;
            $policy->status = true;
            $policy->updated_by_admin_id = request()?->user('admin_api')?->getKey();
            $policy->save();

            $this->audit->record('ride_policy.saved', $policy, $before, $data);

            return $policy;
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE);
    }

    /** @return Collection<int, RidePolicy> */
    private function rows(): Collection
    {
        return Cache::remember(self::CACHE, 300, fn () => RidePolicy::query()->where('status', true)->get());
    }
}
