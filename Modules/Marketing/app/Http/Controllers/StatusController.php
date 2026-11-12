<?php

namespace Modules\Marketing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Marketing\Checkout\MarketingPackagePurpose;
use Modules\Marketing\Models\MarketingPackage;
use Modules\Marketing\Services\Features\MarketingCountry;
use Modules\Marketing\Services\Features\MarketingFeatures;
use Modules\Marketing\Services\Packages\MarketingEntitlement;
use Modules\Marketing\Services\Research\Contracts\SearchDriver;

/** What the apps need before showing the marketing director: is it open here, the package, the prices. */
class StatusController extends Controller
{
    public function __construct(
        private readonly MarketingFeatures $features,
        private readonly MarketingEntitlement $entitlement,
        private readonly SearchDriver $search,
    ) {}

    /** GET marketing/status */
    public function show(Request $request)
    {
        $actor = $request->user();
        $countryId = MarketingCountry::of($actor);
        $country = $countryId === null ? null : Country::query()->with('currency')->find($countryId);
        $snapshot = $countryId === null ? ['service' => false, 'features' => array_fill_keys(MarketingFeatures::FEATURES, false)] : $this->features->snapshot($countryId);

        $packages = ! $snapshot['service'] ? [] : MarketingPackage::query()->where('is_active', true)->with('prices')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (MarketingPackage $p) => [$p, $p->priceIn($countryId)])
            ->filter(fn ($row) => $row[1] !== null)
            ->map(fn ($row) => [
                'code' => $row[0]->code,
                'name' => $row[0]->localized('name'),
                'description' => $row[0]->localized('description'),
                'period_days' => $row[0]->period_days,
                'limits' => collect(MarketingPackage::LIMITS)->mapWithKeys(fn ($k) => [$k => $row[0]->limit($k)])->all(),
                'price_minor' => $row[1]->price_minor,
                'currency_code' => $country?->currency?->code,
            ])->values()->all();

        return ApiResponse::success([
            'available' => $snapshot['service'],
            'features' => $snapshot['features'],
            'research_ready' => $this->search->ready(),
            'country' => $country === null ? null : ['code' => $country->code, 'currency_code' => $country->currency?->code],
            'subscription' => $this->entitlement->snapshot($actor),
            'packages' => $packages,
            'checkout_purpose' => MarketingPackagePurpose::KEY,
        ], __('api.retrieved'));
    }
}
