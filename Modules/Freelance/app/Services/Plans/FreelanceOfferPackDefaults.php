<?php

namespace Modules\Freelance\Services\Plans;

use App\Models\Country;
use Modules\Freelance\Models\FreelanceOfferPack;
use Modules\Freelance\Models\FreelanceOfferPackPrice;

/**
 * The default packs of extra offers and their price in every country (FL-L01, phase 2, `freelance.offer_packs`). Like the plans, `seed()`
 * only adds what is missing — a pack, or a price for a country — so an edited pack or price is never touched and it is safe on every
 * deploy. The price is a base in USD turned into the country's currency the same way the plans' are.
 */
class FreelanceOfferPackDefaults
{
    public function __construct(private readonly FreelancePlanDefaults $plans) {}

    /** @return array{packs: int, prices: int} what was added */
    public function seed(): array
    {
        $packs = 0;
        $prices = 0;

        foreach ((array) config('freelance.offer_packs') as $slug => $def) {
            $pack = FreelanceOfferPack::query()->firstOrCreate(['slug' => $slug], [
                'name' => $def['name'], 'offers' => $def['offers'], 'sort_order' => $def['sort_order'] ?? 0, 'status' => true,
            ]);
            $packs += $pack->wasRecentlyCreated ? 1 : 0;

            // One price for the whole world, in the freelance currency: only the international row gets prices.
            foreach ([app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->country()] as $country) {
                $minor = $this->plans->monthlyMinor((float) $def['usd'], $country->currency);
                if ($minor === null) {
                    continue;
                }
                $row = FreelanceOfferPackPrice::query()->firstOrCreate(['pack_id' => $pack->id, 'country_id' => $country->id], ['price_minor' => $minor, 'status' => true]);
                $prices += $row->wasRecentlyCreated ? 1 : 0;
            }
        }

        return ['packs' => $packs, 'prices' => $prices];
    }
}
