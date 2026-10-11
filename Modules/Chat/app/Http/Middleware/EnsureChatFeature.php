<?php

namespace Modules\Chat\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Services\ChatFeatures;
use Symfony\Component\HttpFoundation\Response;

/**
 * `EnsureChatFeature::class.':stories'` — the feature is off where this person is (a rule for
 * their country or city): `403 chat_feature_unavailable`. The admin's switch for the whole feature
 * is checked where it always was (its own error).
 */
class EnsureChatFeature
{
    public function __construct(private readonly ChatFeatures $features) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! $this->features->allowsHere($request->user(), $feature)) {
            throw new ChatException('feature_unavailable', 403);
        }

        return $next($request);
    }
}
