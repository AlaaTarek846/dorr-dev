<?php

namespace Modules\Freelance\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Freelance\Services\Plans\FreelanceOfferPackDefaults;
use Modules\Freelance\Services\Plans\FreelancePlanDefaults;

/**
 * FL-L01: the Free / Plus / Pro plans and their monthly and yearly price in every country (`freelance.plans`), and the packs of extra offers
 * with their price in every country (`freelance.offer_packs`). Only what is missing is
 * added: an edited plan or price is left as it is, so it is safe on every deploy.
 */
class FreelancePlansSeeder extends Seeder
{
    public function run(): void
    {
        $added = app(FreelancePlanDefaults::class)->seed();
        $this->command?->info("Freelance plans: {$added['plans']} plan(s), {$added['prices']} price(s) added.");
        $packs = app(FreelanceOfferPackDefaults::class)->seed();
        $this->command?->info("Freelance offer packs: {$packs['packs']} pack(s), {$packs['prices']} price(s) added.");
    }
}
