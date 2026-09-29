<?php

namespace Modules\Wallet\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Wallet\Services\WalletEligibilityService;

/**
 * "Am I allowed to request/receive services here, and if not, how much do I
 * top up?" — so the app can explain a suspension instead of just failing.
 */
class WalletEligibilityController extends Controller
{
    public function __construct(private readonly WalletEligibilityService $eligibility) {}

    public function __invoke(Request $request)
    {
        $country = currentCountry();

        abort_if($country === null, 500, 'The country middleware did not run on this route.');

        return ApiResponse::success(
            $this->eligibility->check($request->user(), $country)->toArray(),
            __('api.retrieved'),
        );
    }
}
