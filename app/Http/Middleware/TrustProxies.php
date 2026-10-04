<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;

/**
 * Behind a tunnel / load balancer (ngrok, Cloudflare, nginx) the socket address is the proxy's,
 * so the caller's real IP (country by IP, throttling) comes from X-Forwarded-For — but only from
 * the proxies listed in `app.trusted_proxies` (TRUSTED_PROXIES: "*" for any, or a comma list of
 * IPs/CIDRs; empty = none). Read from config at request time so it also works with config:cache.
 */
class TrustProxies extends Middleware
{
    protected function proxies()
    {
        $configured = trim((string) config('app.trusted_proxies', ''));

        if ($configured === '') {
            return parent::proxies();
        }

        return $configured === '*' ? '*' : array_map('trim', explode(',', $configured));
    }
}
