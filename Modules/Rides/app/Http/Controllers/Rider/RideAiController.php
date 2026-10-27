<?php

namespace Modules\Rides\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Rides\Http\Middleware\RideLocation;
use Modules\Rides\Services\Ai\RideAi;
use Modules\Rides\Services\Ai\RiderSuggestions;
use Modules\Rides\Services\Ai\SmartDestination;
use Modules\Rides\Services\Comfort\ComfortPreferences;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\CityResolver;
use Modules\User\Models\User;

/**
 * The rider's DORR AI in rides (Block 06): «دُرر يفهم مقصدك» / the booking assistant, and «راحتي».
 */
class RideAiController extends Controller
{
    /**
     * `GET ai/status?lat&lng` — whether the assistant is here and the plan allows it, before the button is
     * tapped (the app locks it and offers the DORR AI plans).
     */
    public function status(Request $request, RideFeatures $features, CityResolver $cities, RideAi $ai): JsonResponse
    {
        [$countryId, $cityId] = RideLocation::of($request, $cities);
        $on = $countryId !== null && $features->allows($countryId, $cityId, 'ai_booking');
        $plan = $ai->entitlement($request->user());

        return ApiResponse::success([
            'available' => $on,
            'allowed' => $on && $plan['allowed'],
            'reason' => $on ? ($plan['allowed'] ? null : ($plan['reason'] ?? 'no_plan')) : 'not_enabled',
        ], __('api.retrieved'));
    }

    /** `POST ai/understand {text, lat?, lng?}` — the trip in the rider's words → destination candidates and the rest. */
    public function understand(Request $request, SmartDestination $smart, CityResolver $cities): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'min:2', 'max:500'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        [$countryId, $cityId] = RideLocation::of($request, $cities);
        if ($countryId === null) {
            throw \Modules\Rides\Exceptions\RidesException::featureDisabled('rides');
        }

        return ApiResponse::success($smart->understand(
            $this->rider($request), $data['text'],
            isset($data['lat']) ? (float) $data['lat'] : null, isset($data['lng']) ? (float) $data['lng'] : null,
            $countryId, $cityId,
        ), __('api.retrieved'));
    }

    /** `POST ai/suggest {distance_m?, passengers?}` — AI-09: the category this rider usually takes for a trip like this. */
    public function suggest(Request $request, RiderSuggestions $suggestions): JsonResponse
    {
        $data = $request->validate([
            'distance_m' => ['nullable', 'integer', 'min:0', 'max:2000000'],
            'passengers' => ['nullable', 'integer', 'min:1', 'max:20'],
            'categories' => ['nullable', 'array', 'max:20'],
            'categories.*' => ['string', 'max:40'],
        ]);

        return ApiResponse::success($suggestions->for(
            $this->rider($request),
            isset($data['distance_m']) ? (int) $data['distance_m'] : null,
            isset($data['passengers']) ? (int) $data['passengers'] : null,
            $data['categories'] ?? null,
        ), __('api.retrieved'));
    }

    /** `GET comfort` — «راحتي»: my preferences, the choices, and whether they apply here. */
    public function comfort(Request $request, ComfortPreferences $comfort, RideFeatures $features, CityResolver $cities): JsonResponse
    {
        [$countryId, $cityId] = RideLocation::of($request, $cities);

        return ApiResponse::success($comfort->screen($this->rider($request), $countryId !== null && $features->allows($countryId, $cityId, 'comfort')), __('api.retrieved'));
    }

    /** `PUT comfort {quiet?, ac?, music?, notes?[]}` */
    public function saveComfort(Request $request, ComfortPreferences $comfort, RideFeatures $features, CityResolver $cities): JsonResponse
    {
        $data = $request->validate(self::comfortRules());
        $comfort->save($this->rider($request), $data);
        [$countryId, $cityId] = RideLocation::of($request, $cities);

        return ApiResponse::success($comfort->screen($this->rider($request), $countryId !== null && $features->allows($countryId, $cityId, 'comfort')), __('rides.comfort.saved'));
    }

    /** @return array<string, list<mixed>> */
    public static function comfortRules(string $prefix = ''): array
    {
        return [
            $prefix.'quiet' => ['nullable', 'boolean'],
            $prefix.'ac' => ['nullable', Rule::in(ComfortPreferences::AC)],
            $prefix.'music' => ['nullable', Rule::in(ComfortPreferences::MUSIC)],
            $prefix.'notes' => ['nullable', 'array', 'max:'.count(ComfortPreferences::NOTES)],
            $prefix.'notes.*' => [Rule::in(ComfortPreferences::NOTES)],
        ];
    }

    private function rider(Request $request): User
    {
        /** @var User $rider */
        $rider = $request->user();

        return $rider;
    }
}
