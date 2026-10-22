<?php

namespace Modules\Freelance\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Features\FreelanceCountry;
use Symfony\Component\HttpFoundation\Response;

/**
 * `freelance.feature:bidding` — turns the request down with `freelance_feature_off` (403) when that
 * switch (FL-002) is off in the caller's country. The service itself (`freelance`) must be on too.
 * Works for the freelancer (`freelance_api`) and the client (`user_api`).
 */
class EnsureFreelanceFeature
{
    public function __construct(private readonly FreelanceFeatures $features) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $countryId = FreelanceCountry::of($request->user('freelance_api') ?? $request->user('user_api'));

        if ($countryId === null || ! $this->features->allows($countryId, $feature)) {
            throw new FreelanceException('feature_off', 403, ['feature' => $feature], ['feature' => $feature]);
        }

        return $next($request);
    }
}
