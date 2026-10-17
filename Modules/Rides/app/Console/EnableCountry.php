<?php

namespace Modules\Rides\Console;

use App\Models\Country;
use Illuminate\Console\Command;
use Modules\Rides\Models\RideFeatureRule;
use Modules\Rides\Services\Features\RideFeatures;

/**
 * Switches the phase-1 ride features on for a country (or one city), the same set the seeder gives Saudi
 * Arabia. Only the switches: prices and the pricing policy stay the admin's call (Admin → Rides → Policies).
 */
class EnableCountry extends Command
{
    protected $signature = 'rides:enable-country {code : ISO country code, e.g. EG} {--city= : only this Discover city id} {--off : switch them off instead}';

    protected $description = 'Switch the phase-1 ride features on (or off) for a country or one of its cities';

    /** Same list the seeder switches on for Saudi Arabia. */
    private const PHASE_ONE = ['rides', 'category.economy', 'multi_stop', 'open_destination', 'cash', 'wallet', 'card', 'tips'];

    public function handle(): int
    {
        $country = Country::query()->where('code', strtoupper((string) $this->argument('code')))->first();
        if ($country === null) {
            $this->error('No country with that code.');

            return self::FAILURE;
        }

        $cityId = $this->option('city') !== null ? (int) $this->option('city') : null;
        $enabled = ! $this->option('off');

        foreach (self::PHASE_ONE as $feature) {
            RideFeatureRule::query()->updateOrCreate(
                ['feature' => $feature, 'country_id' => $country->id, 'city_id' => $cityId],
                ['enabled' => $enabled, 'note' => 'rides:enable-country'],
            );
        }
        RideFeatures::forget();

        $this->info(($enabled ? 'Enabled ' : 'Disabled ').count(self::PHASE_ONE)." ride switches for {$country->code}".($cityId ? " / city {$cityId}" : '').'.');
        if ($enabled) {
            $this->line('Next: add prices in Admin → Rides → Policies, and a Discover city in this country so pickups resolve.');
        }

        return self::SUCCESS;
    }
}
