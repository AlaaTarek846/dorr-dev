<?php

namespace App\Http\Middleware;

use App\Services\General\CountryResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request's country once and makes it available two ways:
 * `$request->attributes->get('resolved_country')` and the `currentCountry()`
 * helper (app/Support/helpers.php) for code that doesn't have the Request.
 */
class ResolveCountryContext
{
    public function __construct(private readonly CountryResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $country = $this->resolver->resolve($request);

        $request->attributes->set('resolved_country', $country);
        app()->instance('resolved_country', $country);

        return $next($request);
    }
}
