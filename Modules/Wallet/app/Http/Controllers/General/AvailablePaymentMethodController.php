<?php

namespace Modules\Wallet\Http\Controllers\General;

use App\Http\Controllers\Controller;
use Modules\Wallet\Services\PaymentMethodService;

/**
 * "Which payment methods can I top up with, here?" — mounted under both the
 * mobile/user and provider route groups. Relies on the `country` middleware
 * (Phase 3) having already resolved the request's country; a missing one
 * means the route was registered without it, which is a wiring bug.
 */
class AvailablePaymentMethodController extends Controller
{
    public function __construct(private readonly PaymentMethodService $service) {}

    public function __invoke()
    {
        $country = currentCountry();

        abort_if($country === null, 500, 'The country middleware did not run on this route.');

        return $this->service->availableForCountry($country);
    }
}
