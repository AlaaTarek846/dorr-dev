<?php

namespace App\Support;

use App\Support\Translations\PublishedTranslations;
use Illuminate\Http\Request;

class LocaleResolver
{
    /**
     * Source locales (ar, en) plus dashboard-managed languages with published backend translations.
     *
     * @return list<string>
     */
    public static function supported(): array
    {
        return app(PublishedTranslations::class)->backendLocales();
    }

    public static function resolveFromRequest(Request $request, ?string $default = null): string
    {
        $supported = self::supported();

        $locale = $request->route('locale')
            ?? $request->header('X-Locale')
            ?? $request->query('lang')
            ?? $request->getPreferredLanguage($supported)
            ?? $default
            ?? config('app.locale', 'en');

        $locale = self::match((string) $locale, $supported);

        if ($locale !== null) {
            return $locale;
        }

        $fallback = config('app.locale', 'en');

        return in_array($fallback, $supported, true) ? $fallback : 'en';
    }

    public static function apply(Request $request, ?string $default = null): void
    {
        app()->setLocale(self::resolveFromRequest($request, $default));
    }

    /**
     * Exact code first (pt-br), then its primary language (en-US → en).
     *
     * @param  list<string>  $supported
     */
    private static function match(string $locale, array $supported): ?string
    {
        $locale = strtolower(str_replace('_', '-', trim($locale)));

        if (in_array($locale, $supported, true)) {
            return $locale;
        }

        $primary = explode('-', $locale)[0];

        return in_array($primary, $supported, true) ? $primary : null;
    }
}
