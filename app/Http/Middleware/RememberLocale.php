<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remembers the language an authenticated account is using (users.locale, …) so that a
 * notification composed later — by someone else's request, or by a scheduled job — is
 * written in *this* person's language. Runs after auth; writes only when it changed.
 */
class RememberLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user();

        if ($account !== null && $account->locale !== app()->getLocale()) {
            $account->forceFill(['locale' => app()->getLocale()])->saveQuietly();
        }

        return $next($request);
    }
}
