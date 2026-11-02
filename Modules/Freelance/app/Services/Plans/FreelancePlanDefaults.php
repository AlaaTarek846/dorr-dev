<?php

namespace Modules\Freelance\Services\Plans;

use App\Models\Country;
use App\Models\Currency;
use Modules\Freelance\Models\FreelancePlan;
use Modules\Freelance\Models\FreelancePlanPrice;

/**
 * The default plans and prices (FL-L01, `freelance.plans`). `seed()` adds what is missing — the plans, and a monthly and a yearly price for
 * every country — and never touches a plan or a price an admin already set, so it is safe to run on every deploy and again when a
 * country is added. The price comes from a base in USD turned into the country's currency by the stored exchange rates.
 */
class FreelancePlanDefaults
{
    /** @return array{plans: int, prices: int} what was added */
    public function seed(): array
    {
        $plans = 0;
        $prices = 0;

        foreach ((array) config('freelance.plans') as $slug => $def) {
            $plan = FreelancePlan::query()->firstOrCreate(['slug' => $slug], [
                'rank' => $def['rank'], 'name' => $def['name'], 'description' => $def['description'] ?? null,
                'offers_monthly' => $def['offers_monthly'] ?? null, 'offers_unlimited' => $def['offers_unlimited'] ?? false,
                'commission_bp' => $def['commission_bp'] ?? null, 'trial_days' => $def['trial_days'] ?? 0, 'status' => true,
                'badge' => $def['badge'] ?? false, 'boost' => $def['boost'] ?? 0, 'analytics' => $def['analytics'] ?? 0, 'services_max' => $def['services_max'] ?? null,
            ]);
            $plans += $plan->wasRecentlyCreated ? 1 : 0;

            if (! isset($def['usd'])) {
                continue;
            }
            foreach (Country::query()->with('currency')->get() as $country) {
                $monthly = $this->monthlyMinor((float) $def['usd'], $country->currency);
                if ($monthly === null) {
                    continue;
                }
                $yearly = $this->yearlyMinor($monthly, $country->currency);
                foreach ([FreelancePlanPrice::MONTHLY => $monthly, FreelancePlanPrice::YEARLY => $yearly] as $period => $minor) {
                    $row = FreelancePlanPrice::query()->firstOrCreate(
                        ['plan_id' => $plan->id, 'country_id' => $country->id, 'period' => $period],
                        ['price_minor' => $minor, 'status' => true],
                    );
                    $prices += $row->wasRecentlyCreated ? 1 : 0;
                }
            }
        }

        return ['plans' => $plans, 'prices' => $prices];
    }

    /** The monthly price in the minor unit of the currency, or null when the currency has no usable rate. */
    public function monthlyMinor(float $usd, ?Currency $currency): ?int
    {
        if ($currency === null) {
            return null;
        }
        $perUsd = $this->unitsPerUsd($currency);
        if ($perUsd === null) {
            return null;
        }

        return $this->minor($this->friendly($usd * $perUsd), $currency);
    }

    /** 12 months less the yearly discount, rounded the same friendly way. */
    public function yearlyMinor(int $monthlyMinor, ?Currency $currency): int
    {
        $decimals = (int) ($currency?->decimal_places ?? 2);
        $major = $monthlyMinor / (10 ** $decimals);
        $discount = (int) config('freelance.plan_yearly_discount_bp', 2000);

        return $this->minor($this->friendly($major * 12 * (10000 - $discount) / 10000), $currency);
    }

    /** Units of the currency for 1 USD: from the stored rates (both relative to the default currency), or the placeholder table. */
    private function unitsPerUsd(Currency $currency): ?float
    {
        if ($currency->code === 'USD') {
            return 1.0;
        }
        $rate = (float) $currency->exchange_rate;
        $fallback = config('freelance.plan_usd_rates.'.$currency->code);
        // 0.01 is the placeholder the currencies were seeded with where no rate was known.
        if ($fallback !== null && (abs($rate - 0.01) < 0.0000001 || $rate <= 0)) {
            return (float) $fallback;
        }
        $usd = (float) Currency::query()->where('code', 'USD')->value('exchange_rate');
        if ($rate <= 0 || $usd <= 0) {
            return $fallback === null ? null : (float) $fallback;
        }

        return $rate / $usd;
    }

    /** A price people read easily: nearest 0.5 under 10, 1 under 100, 5 under 1,000, 50 under 10,000, else 500. */
    private function friendly(float $major): float
    {
        $step = match (true) {
            $major < 10 => 0.5,
            $major < 100 => 1,
            $major < 1000 => 5,
            $major < 10000 => 50,
            default => 500,
        };

        return max($step, round($major / $step) * $step);
    }

    private function minor(float $major, ?Currency $currency): int
    {
        return (int) round($major * (10 ** (int) ($currency?->decimal_places ?? 2)));
    }
}
