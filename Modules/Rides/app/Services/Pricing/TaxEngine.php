<?php

namespace Modules\Rides\Services\Pricing;

use Modules\Rides\Models\RideTaxRule;

/** The ride tax in force in a country (Saudi Arabia: VAT 15% on the commission, carried by the driver). */
class TaxEngine
{
    /** @return array{code: string, base: string, rate_bp: int, borne_by: string, label: ?string}|null */
    public function ruleFor(int $countryId): ?array
    {
        $rule = RideTaxRule::query()->inForceAt($countryId)->with('translations')->latest('effective_from')->first();

        return $rule === null ? null : [
            'code' => $rule->code,
            'base' => $rule->base->value,
            'rate_bp' => $rule->rate_bp,
            'borne_by' => $rule->borne_by->value,
            'label' => $rule->translated('label'),
        ];
    }
}
