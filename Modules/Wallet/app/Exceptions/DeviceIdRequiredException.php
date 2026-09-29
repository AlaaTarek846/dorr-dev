<?php

namespace Modules\Wallet\Exceptions;

use App\Exceptions\ApiRenderable;
use RuntimeException;

/**
 * Confirming device trust needs the same `X-Device-Id` the app sent when it asked for the code —
 * missing here means a wiring bug in the client, not something the person can fix.
 */
class DeviceIdRequiredException extends RuntimeException implements ApiRenderable
{
    public function __construct()
    {
        parent::__construct('X-Device-Id header required.');
    }

    public function apiStatus(): int
    {
        return 422;
    }

    public function apiMessage(): string
    {
        return __('wallet.errors.device_id_required');
    }

    public function apiErrorCode(): string
    {
        return 'wallet_device_id_required';
    }

    public function apiData(): array
    {
        return [];
    }
}
