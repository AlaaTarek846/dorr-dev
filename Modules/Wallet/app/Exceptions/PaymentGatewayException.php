<?php

namespace Modules\Wallet\Exceptions;

use App\Exceptions\ApiRenderable;
use RuntimeException;

/**
 * The gateway itself couldn't be reached/misbehaved (network error, missing
 * credentials) — distinct from a normal "payment declined" outcome, which is
 * a non-exceptional GatewayCallbackResult with confirmed=false. The customer
 * only ever sees the generic message; the technical reason stays in the log.
 */
class PaymentGatewayException extends RuntimeException implements ApiRenderable
{
    public function apiStatus(): int
    {
        return 502;
    }

    public function apiMessage(): string
    {
        return __('wallet.errors.gateway_unreachable');
    }

    public function apiErrorCode(): string
    {
        return 'gateway_unreachable';
    }

    public function apiData(): array
    {
        return [];
    }
}
