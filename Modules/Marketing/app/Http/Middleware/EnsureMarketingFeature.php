<?php

namespace Modules\Marketing\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Marketing\Exceptions\MarketingException;
use Modules\Marketing\Services\Features\MarketingCountry;
use Modules\Marketing\Services\Features\MarketingFeatures;
use Symfony\Component\HttpFoundation\Response;

/**
 * `marketing.feature:research` turns the request down with `marketing_feature_off` (403) when that switch is off in
 * the caller's country. The service itself (`marketing`) must be on too.
 */
class EnsureMarketingFeature
{
    public function __construct(private readonly MarketingFeatures $features) {}

    public function handle(Request $request, Closure $next, string $feature = MarketingFeatures::SERVICE): Response
    {
        $countryId = MarketingCountry::of($request->user());

        if ($countryId === null || ! $this->features->allows($countryId, $feature)) {
            throw new MarketingException('feature_off', 403, [], ['feature' => $feature]);
        }

        return $next($request);
    }
}
