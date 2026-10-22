<?php

namespace Modules\Freelance\Services\Policy;

use App\Models\Country;
use App\Services\General\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Models\FreelancePolicy;

/**
 * Resolves and saves the freelance policies (FL-008). Two scopes, broadest first — each key takes its
 * value from the most specific row that sets it, else `freelance.policy_defaults`:
 *
 *   country → country + specialty
 *
 * (Countries only, no cities — the owner's decision for freelance work.) Money is in the country's
 * own currency, so copying between countries of different currencies leaves the amounts behind.
 */
class FreelancePolicies
{
    private const CACHE = 'freelance:policies';

    public function __construct(private readonly AuditLogger $audit) {}

    /** `$skipPolicyId`: leave one row out — what a scope inherits from the broader ones (the admin's form). */
    public function for(int $countryId, ?int $specialtyId = null, ?int $skipPolicyId = null): FreelancePolicyData
    {
        $rows = $this->rows()->where('country_id', $countryId);
        $layers = [];
        $ids = [];

        foreach (array_unique([null, $specialtyId], SORT_REGULAR) as $specialty) {
            $row = $rows->first(fn (FreelancePolicy $p) => $p->specialty_id === $specialty);
            if ($row === null || $row->id === $skipPolicyId) {
                continue;
            }
            $layers[] = (array) $row->data;
            $ids[] = $row->id;
        }

        return FreelancePolicyData::merge($layers, $ids);
    }

    public function own(int $countryId, ?int $specialtyId): ?FreelancePolicy
    {
        return FreelancePolicy::query()
            ->where('country_id', $countryId)
            ->where(fn ($q) => $specialtyId === null ? $q->whereNull('specialty_id') : $q->where('specialty_id', $specialtyId))
            ->first();
    }

    /**
     * Replaces the keys one scope changes (validated, audited). Empty data removes the scope's row, so it
     * inherits everything again. A country's change is checked against each of its specialties too.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(int $countryId, ?int $specialtyId, array $data, ?int $adminId = null): ?FreelancePolicy
    {
        FreelancePolicyData::validate($data);

        return DB::transaction(function () use ($countryId, $specialtyId, $data, $adminId): ?FreelancePolicy {
            $policy = FreelancePolicy::query()
                ->where('country_id', $countryId)
                ->where(fn ($q) => $specialtyId === null ? $q->whereNull('specialty_id') : $q->where('specialty_id', $specialtyId))
                ->lockForUpdate()
                ->first();

            // The merged numbers this scope would end up with must make sense on their own.
            $inherited = $this->for($countryId, $specialtyId, $policy?->id);
            FreelancePolicyData::merge([$inherited->all(), $data])->assertConsistent();

            $before = $policy?->data;

            if ($data === []) {
                if ($policy !== null) {
                    $policy->delete();
                    $this->audit->record('freelance_policy.reset', $policy, $before, null);
                }

                return null;
            }

            $policy ??= new FreelancePolicy(['country_id' => $countryId, 'specialty_id' => $specialtyId]);
            $policy->data = $data;
            $policy->status = true;
            $policy->updated_by_admin_id = $adminId;
            $policy->save();

            if ($specialtyId === null) {
                // Every specialty of this country now inherits the new country numbers.
                FreelancePolicy::query()->where('country_id', $countryId)->whereNotNull('specialty_id')->get()
                    ->each(fn (FreelancePolicy $row) => FreelancePolicyData::merge([FreelancePolicyData::merge([$data])->all(), (array) $row->data])->assertConsistent());
            }

            $this->audit->record('freelance_policy.saved', $policy, $before, $data);

            return $policy;
        });
    }

    /**
     * Copies every scope of one country onto another. Between two currencies the amounts can't carry
     * over (5 SAR is not 5 EGP): money keys are skipped, and a fixed commission / fee is left out whole
     * (its type with it). The skipped keys are returned so the admin sets them in the new currency.
     *
     * @return array{copied: int, skipped: list<string>}
     */
    public function copy(int $fromCountryId, int $toCountryId, ?int $adminId = null): array
    {
        $sameCurrency = Country::query()->whereKey($fromCountryId)->value('currency_id') === Country::query()->whereKey($toCountryId)->value('currency_id');
        $skipped = [];
        $copied = 0;

        DB::transaction(function () use ($fromCountryId, $toCountryId, $sameCurrency, $adminId, &$skipped, &$copied): void {
            $rows = FreelancePolicy::query()->where('country_id', $fromCountryId)->orderByRaw('specialty_id IS NOT NULL')->orderBy('specialty_id')->get();

            foreach ($rows as $row) {
                $data = (array) $row->data;
                if (! $sameCurrency) {
                    $merged = $this->for($fromCountryId, $row->specialty_id)->all();
                    foreach (array_keys($data) as $key) {
                        $meta = FreelancePolicyData::KEYS[$key] ?? null;
                        $feeIsFixed = $meta !== null && in_array($meta['group'], ['commission', 'client_fee'], true)
                            && ($merged[$meta['group'].'.type'] ?? null) === 'fixed'
                            && in_array($key, [$meta['group'].'.type', $meta['group'].'.value'], true);
                        if (FreelancePolicyData::isMoney($key, $merged) || $feeIsFixed) {
                            unset($data[$key]);
                            $skipped[] = $key;
                        }
                    }
                }

                $this->save($toCountryId, $row->specialty_id, $data, $adminId);
                $copied++;
            }
        });

        return ['copied' => $copied, 'skipped' => array_values(array_unique($skipped))];
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE);
    }

    /** @return Collection<int, FreelancePolicy> */
    private function rows(): Collection
    {
        return Cache::remember(self::CACHE, 300, fn () => FreelancePolicy::query()->where('status', true)->get());
    }
}
