<?php

namespace Modules\Freelance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceCountry;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use App\Models\Country;

/**
 * What an app needs to know once, when it opens (no polling): the API version, the switches of the
 * caller's country, its currency and policy numbers (FL-008), and the limits it should check before uploading. The freelancer reads it at
 * `freelance/v1/config`, the client at `mobile/v1/freelance/config`.
 */
class FreelanceConfigController extends Controller
{
    public function __construct(
        private readonly FreelanceFeatures $features,
        private readonly FreelancePolicies $policies,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $freelancer = $request->user('freelance_api');
        $who = $freelancer ?? $request->user('user_api');
        $countryId = FreelanceCountry::of($who);
        $snapshot = $countryId !== null ? $this->features->snapshot($countryId) : ['service' => false, 'features' => array_fill_keys(FreelanceFeatures::FEATURES, false)];

        $currency = $countryId !== null ? Country::query()->with('currency')->find($countryId)?->currency : null;

        return ApiResponse::success([
            'api_version' => (string) config('freelance.api_version'),
            'min_app_version' => (string) config('freelance.min_app_version'),
            'role' => $freelancer instanceof Freelancer ? 'freelancer' : 'client',
            'country_id' => $countryId,
            'service' => $snapshot['service'],
            'features' => $snapshot['features'],
            'terms_version' => (string) config('freelance.terms_version'),
            // The country's own currency: every *_minor amount below is in its minor unit (FL-008).
            'currency' => [
                'code' => $currency?->code,
                'symbol' => $currency?->symbol,
                'decimal_places' => (int) ($currency?->decimal_places ?? 2),
            ],
            // The numbers the apps check and show (fee calculator, budget and offer limits); country-wide.
            'policy' => $countryId !== null ? $this->policies->for($countryId)->all() : null,
            'limits' => [
                'upload_max_kb' => (int) config('freelance.upload_max_kb'),
                'avatar_max_kb' => 5120,
            ],
        ], __('api.retrieved'));
    }
}
