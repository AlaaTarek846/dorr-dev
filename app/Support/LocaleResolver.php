<?php

namespace App\Support;

use Illuminate\Http\Request;

class LocaleResolver
{
    /**
     * @return list<string>
     */
    public static function supported(): array
    {
        return ['ar', 'en'];
    }

    public static function resolveFromRequest(Request $request, ?string $default = null): string
    {
        $locale = $request->route('locale')
            ?? $request->header('X-Locale')
            ?? $request->query('lang')
            ?? $request->getPreferredLanguage(self::supported())
            ?? $default
            ?? config('app.locale', 'en');

        $locale = strtolower(substr((string) $locale, 0, 2));

        if (in_array($locale, self::supported(), true)) {
            return $locale;
        }

        $fallback = config('app.locale', 'en');

        return in_array($fallback, self::supported(), true) ? $fallback : 'en';
    }

    public static function apply(Request $request, ?string $default = null): void
    {
        app()->setLocale(self::resolveFromRequest($request, $default));
    }
}
