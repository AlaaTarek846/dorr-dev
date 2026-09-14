<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * @var list<string>
     */
    protected array $supported = ['ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->header('Accept-Language')
            ?? $request->header('X-Locale')
            ?? $request->query('lang');

        $locale = strtolower(substr((string) $locale, 0, 2));

        if (in_array($locale, $this->supported, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
