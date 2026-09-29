<?php

namespace Modules\Wallet\Exceptions;

use RuntimeException;

/**
 * Thrown when an operation genuinely isn't available for a gateway rather
 * than faked — e.g. Jawad's URPay integration never exposed a standalone
 * status-check endpoint (only the OTP-execute step itself confirms a
 * payment), so Modules\Wallet\Services\Gateways\UrPayGateway::verify()
 * throws this instead of calling an endpoint that isn't known to exist.
 */
class UnsupportedGatewayOperationException extends RuntimeException
{
    public function __construct(string $gateway, string $operation)
    {
        parent::__construct("The '{$gateway}' gateway driver doesn't support {$operation}.");
    }
}
