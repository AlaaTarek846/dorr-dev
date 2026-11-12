<?php

namespace Modules\Moving\Services\Policy;

use Modules\Moving\Models\MovingPolicy;

/**
 * The moving values in force in a country (B-01): the country's own row over the default row; a key nobody set is
 * null, which means the rule is not applied yet (requirements DEC-020: no made-up numbers).
 */
class MovingPolicies
{
    /** @return array{version: int, values: array<string, mixed>} */
    public function for(int $countryId): array
    {
        $rows = MovingPolicy::query()->whereNull('country_id')->orWhere('country_id', $countryId)->get();
        $default = $rows->firstWhere('country_id', null);
        $own = $rows->firstWhere('country_id', $countryId);

        $values = array_fill_keys((array) config('moving.policy_keys', []), null);
        foreach ([$default, $own] as $row) {
            foreach ((array) ($row?->values ?? []) as $key => $value) {
                if (array_key_exists($key, $values) && $value !== null && $value !== '') {
                    $values[$key] = $value;
                }
            }
        }

        // Either row changing gives a new number: the country's saves count in thousands, the default's in ones.
        return ['version' => (int) (($own?->version ?? 0) * 1000 + ($default?->version ?? 0)), 'values' => $values];
    }

    /** @param array<string, mixed> $values */
    public function save(?int $countryId, array $values, int $adminId): MovingPolicy
    {
        $keys = (array) config('moving.policy_keys', []);
        $clean = array_intersect_key($values, array_flip($keys));
        $row = MovingPolicy::query()->firstOrNew(['country_id' => $countryId]);
        $row->fill([
            'values' => $clean,
            'version' => $row->exists ? $row->version + 1 : 1,
            'updated_by_admin_id' => $adminId,
        ])->save();

        return $row;
    }
}
