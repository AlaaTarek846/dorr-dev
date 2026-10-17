<?php

namespace Modules\Rides\Console;

use App\Models\Country;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Modules\Rides\Http\Middleware\RideLocation;
use Modules\Rides\Models\RideFeatureRule;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\CityResolver;
use Modules\Rides\Services\Geo\GeoProvider;
use Modules\User\Models\User;

/**
 * "Why does the app say rides aren't available?" — follows the same steps the server does for one rider
 * and says where it stops: which country and city the place resolves to, which switches exist there,
 * and the command that fixes it.
 */
class WhyUnavailable extends Command
{
    protected $signature = 'rides:why {--phone= : the rider\'s phone number (or part of it)} {--user= : the rider\'s id} {--lat= : latitude the app sends} {--lng= : longitude the app sends}';

    protected $description = 'Explain why rides are (not) available for a rider and a place';

    public function handle(CityResolver $cities, RideFeatures $features, GeoProvider $geo): int
    {
        $user = $this->rider();
        if ($user === false) {
            return self::FAILURE;
        }

        $lat = $this->option('lat') !== null ? (float) $this->option('lat') : null;
        $lng = $this->option('lng') !== null ? (float) $this->option('lng') : null;

        $this->components->twoColumnDetail('Rider', $user ? "#{$user->getKey()}  {$user->getAttribute('phone')}" : '— (no rider given; only the place is used)');

        $accountCountry = $user?->getAttribute('country_id');
        $this->components->twoColumnDetail('Account country', $this->country($accountCountry !== null ? (int) $accountCountry : null));

        if ($lat !== null && $lng !== null) {
            $name = $geo->cityName($lat, $lng);
            $this->components->twoColumnDetail('Place', "{$lat}, {$lng}");
            $this->components->twoColumnDetail('City name by the map', $name ?? '— (the map could not tell)');
            $cityId = $cities->cityAt($lat, $lng);
            $this->components->twoColumnDetail('Admin city found', $cityId !== null ? "#{$cityId} → ".$this->country($cities->countryOf($cityId)) : '— none within '.config('rides.city_radius_km').' km of the point (add a Discover city there, or a service-area zone)');
        } else {
            $this->components->twoColumnDetail('Place', '— none given (add --lat= --lng= to test where the phone is)');
        }

        $request = Request::create('/', 'GET', $lat !== null && $lng !== null ? ['lat' => $lat, 'lng' => $lng] : []);
        $request->setUserResolver(fn () => $user);
        [$countryId, $cityId] = RideLocation::of($request, $cities);
        $this->components->twoColumnDetail('Rides are looked up for', $countryId === null ? '— no country at all' : $this->country($countryId).($cityId ? " / city #{$cityId}" : ' / no city'));

        if ($countryId === null) {
            $this->newLine();
            $this->error('No country → rides are off (reason: no_country).');
            $this->line('Fix: the account needs a country, or the phone\'s location has to be inside an admin city. A phone on the emulator\'s default spot (California) matches no city.');

            return self::FAILURE;
        }

        $rules = RideFeatureRule::query()->where('country_id', $countryId)->orderBy('feature')->get();
        $this->newLine();
        if ($rules->isEmpty()) {
            $this->line('Switches for this country: none.');
        } else {
            $this->table(['Switch', 'Scope', 'On'], $rules->map(fn (RideFeatureRule $r) => [$r->feature, $r->city_id ? "city #{$r->city_id}" : 'country', $r->enabled ? 'yes' : 'no'])->all());
        }

        $on = $features->allows($countryId, $cityId, RideFeatures::SERVICE);
        $this->newLine();
        if ($on) {
            $this->info('Rides are ON here. If the app still says otherwise, the app is asking about a different place or account — run again with the phone\'s --lat/--lng.');

            return self::SUCCESS;
        }

        $this->error('Rides are OFF here (reason: not_enabled) — nobody switched `rides` on for this country/city (no rule = off).');
        $code = Country::query()->whereKey($countryId)->value('code');
        $this->line("Fix: php artisan rides:enable-country {$code}".($cityId ? "   (or only this city: --city={$cityId})" : '').'  then add prices in Admin → Rides → Prices & rules.');

        return self::FAILURE;
    }

    /** @return User|null|false false = a lookup was asked for and found nothing */
    private function rider(): User|null|false
    {
        $query = User::query();
        if ($this->option('user') !== null) {
            $user = $query->find((int) $this->option('user'));
        } elseif ($this->option('phone') !== null) {
            $digits = preg_replace('/\D+/', '', (string) $this->option('phone'));
            $user = $query->where('phone', 'like', '%'.$digits.'%')->first();
        } else {
            return null;
        }

        if ($user === null) {
            $this->error('No such rider.');

            return false;
        }

        return $user;
    }

    private function country(?int $id): string
    {
        if ($id === null) {
            return '— none';
        }
        $country = Country::query()->find($id);

        return $country ? "{$country->code} (#{$id}, ".($country->status ? 'active' : 'INACTIVE').')' : "#{$id} (missing)";
    }
}
