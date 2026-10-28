<?php

namespace Modules\AI\Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;
use Modules\AI\Models\World\AiWorldPrice;
use Modules\AI\Models\World\AiWorldSetting;

/**
 * DORR AI WORLD starting data: the settings row and a price per generated second for each country whose
 * currency is listed below. These are STARTING values (roughly 1.5-2x the Veo 3.1 Fast cost of $0.12/s):
 * review them in the admin before launch. Existing rows are never overwritten, so admin edits survive re-seeding.
 * Amounts are minor units (1000 on EGP = 10.00 EGP per second).
 */
class AiWorldSeeder extends Seeder
{
    /** @var array<string, array{standard: int, premium: int}> */
    protected const PRICES = [
        'EGP' => ['standard' => 1000, 'premium' => 1500],
        'SAR' => ['standard' => 110, 'premium' => 170],
        'AED' => ['standard' => 110, 'premium' => 170],
        'USD' => ['standard' => 30, 'premium' => 45],
    ];

    public function run(): void
    {
        AiWorldSetting::current();

        foreach (Country::query()->with('currency')->get() as $country) {
            $prices = self::PRICES[strtoupper((string) $country->currency?->code)] ?? null;

            if ($prices === null) {
                continue;
            }

            foreach ($prices as $quality => $minor) {
                AiWorldPrice::query()->firstOrCreate(
                    ['country_id' => $country->id, 'quality' => $quality],
                    ['price_per_second_minor' => $minor, 'is_active' => true],
                );
            }
        }
    }
}
