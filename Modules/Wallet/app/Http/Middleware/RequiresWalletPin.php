<?php

namespace Modules\Wallet\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Wallet\Exceptions\PinRequiredException;
use Modules\Wallet\Services\PinService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for every money-moving endpoint (topup, transfer, service payment,
 * withdrawal, withdrawal-method change). The client sends the PIN in the
 * `X-Wallet-Pin` header — a header, not the body, so it never lands in a
 * validated/logged payload. PinService owns the attempt limit + lockout; the
 * domain exceptions it throws render themselves (428 not set / 422 required
 * or wrong / 423 locked).
 */
class RequiresWalletPin
{
    public function __construct(private readonly PinService $pins) {}

    public function handle(Request $request, Closure $next): Response
    {
        $owner = $request->user();
        $pin = $request->header('X-Wallet-Pin');

        // "Not set" is reported before "not sent" so a first-time user is
        // sent to PIN creation instead of being asked for a PIN they lack.
        if (! $this->pins->has($owner)) {
            $this->pins->verify($owner, (string) $pin); // throws PinNotSetException
        }

        if (! is_string($pin) || $pin === '') {
            throw new PinRequiredException;
        }

        $this->pins->verify($owner, $pin);

        return $next($request);
    }
}
