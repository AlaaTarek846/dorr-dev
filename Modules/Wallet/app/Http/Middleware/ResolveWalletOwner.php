<?php

namespace Modules\Wallet\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Wallet\Support\WalletOwner;
use Symfony\Component\HttpFoundation\Response;

/** Puts on the request whose wallet it is about, when a module says it isn't the signed-in account (see WalletOwner). */
class ResolveWalletOwner
{
    public function __construct(private readonly WalletOwner $owners) {}

    public function handle(Request $request, Closure $next): Response
    {
        $owner = $this->owners->resolve($request);
        if ($owner !== null) {
            $request->attributes->set(WalletOwner::ATTRIBUTE, $owner);
        }

        return $next($request);
    }
}
