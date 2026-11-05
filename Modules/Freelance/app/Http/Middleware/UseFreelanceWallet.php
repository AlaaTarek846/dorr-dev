<?php

namespace Modules\Freelance\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Freelance\Services\Policy\FreelanceMoney;
use Symfony\Component\HttpFoundation\Response;

/**
 * Points the wallet routes after it at the international wallet (`freelance.money`): runs after the `country` middleware and
 * swaps the request's country for the hidden international row, so every wallet screen (balance, statement, top-up, checkout,
 * withdrawal) works unchanged on the freelance currency. The freelancer's wallet routes and the client's `mobile/v1/freelance/wallet/*`.
 */
class UseFreelanceWallet
{
    public function __construct(private readonly FreelanceMoney $money) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Dollars are already this owner's own money (a US client): their local wallet is the freelance wallet, no international one.
        $country = $this->money->walletCountryFor($request->user());

        $request->attributes->set('resolved_country', $country);
        app()->instance('resolved_country', $country);

        return $next($request);
    }
}
