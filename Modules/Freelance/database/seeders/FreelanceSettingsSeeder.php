<?php

namespace Modules\Freelance\Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;

/**
 * The freelance switches (FL-002) for every active country. It only adds the rules that are missing: a switch an admin turned off is
 * left off, so it is safe on every deploy. The numbers (commission, fees, budget limits…) come from `freelance.policy_defaults`
 * until an admin overrides them per country (FL-008), so no policy rows are written here.
 */
class FreelanceSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $added = 0;
        foreach (Country::query()->where('status', true)->get() as $country) {
            foreach (FreelanceFeatures::all() as $feature) {
                $rule = FreelanceFeatureRule::query()->firstOrCreate(['feature' => $feature, 'country_id' => $country->id], ['enabled' => true]);
                $added += $rule->wasRecentlyCreated ? 1 : 0;
            }
        }

        FreelanceFeatures::forget();
        FreelancePolicies::forget();
        $this->command?->info("Freelance switches: {$added} added.");
    }
}
