<?php

namespace Modules\AI\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\AI\Models\AiSiteHosting;
use Modules\AI\Services\Sites\AiSiteResponder;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sub-domain hosting: a request to {name}.{AI_SITES_HOSTING_DOMAIN} is answered here,
 * before routing, sessions or cookies - so no app route can shadow a customer's site
 * and a hosted page never touches the app's own session. Every other host passes through.
 */
class ServeHostedSiteMiddleware
{
    public function __construct(protected AiSiteResponder $responder) {}

    public function handle(Request $request, Closure $next): Response
    {
        $domain = strtolower((string) config('ai.sites.hosting.domain'));

        if ($domain === '' || ! config('ai.sites.hosting.enabled', true) || ! config('ai.sites.enabled', true)) {
            return $next($request);
        }

        $host = strtolower($request->getHost());

        if (! str_ends_with($host, '.'.$domain)) {
            return $next($request);
        }

        $name = substr($host, 0, -strlen('.'.$domain));

        if ($name === '' || str_contains($name, '.')) {
            abort(404);
        }

        $hosting = AiSiteHosting::query()->where('subdomain', $name)->with(['project', 'publishedVersion'])->first();

        if ($hosting === null || ! $hosting->isLive() || $hosting->publishedVersion === null
            || $hosting->project === null || $hosting->project->trashed() || $hosting->project->isDisabled()) {
            abort(404);
        }

        return $this->responder->respond($request, $hosting->project, $hosting->publishedVersion, $request->path() === '/' ? '' : $request->path(), hosted: true, redirectRoot: false);
    }
}
