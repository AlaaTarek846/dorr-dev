<?php

namespace Modules\Wallet\Support;

use Illuminate\Http\Request;

/**
 * The colours of the small server-rendered wallet pages (the sandbox bank checkout and the payment
 * result page). The Android app opens them in a WebView and passes its own theme colours in the query
 * string — `?primary=0a7e8c&bg=0b1220...` — so the pages look like part of the app.
 *
 * Every value must be exactly six hex digits; anything else is ignored, so what reaches the stylesheet
 * is always a plain colour and never arbitrary text.
 */
final class PageTheme
{
    /**
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'primary' => 'e50914',
        'bg' => 'ffffff',
        'surface' => 'ffffff',
        'ink' => '111928',
        'mut' => '6b7280',
        'soft' => '9ca3af',
        'line' => 'f3f4f6',
        'field' => 'f3f4f6',
    ];

    /**
     * The palette to draw a page with (defaults for anything missing/invalid) plus the brand colour as
     * "r, g, b" for translucent tints.
     *
     * @return array<string, string>
     */
    public static function fromRequest(Request $request): array
    {
        $theme = [];

        foreach (self::DEFAULTS as $key => $default) {
            $theme[$key] = self::valid($request->query($key)) ?? $default;
        }

        $theme['primary_rgb'] = implode(', ', array_map('hexdec', str_split($theme['primary'], 2)));

        return $theme;
    }

    /**
     * Only the valid colours the request carried, to pass them on to the next page of the flow
     * (checkout → decision → result) as a query string.
     *
     * @return array<string, string>
     */
    public static function passThrough(Request $request): array
    {
        $query = [];

        foreach (array_keys(self::DEFAULTS) as $key) {
            $value = self::valid($request->query($key));

            if ($value !== null) {
                $query[$key] = $value;
            }
        }

        return $query;
    }

    private static function valid(mixed $value): ?string
    {
        $value = ltrim((string) $value, '#');

        return preg_match('/^[0-9a-fA-F]{6}$/', $value) === 1 ? strtolower($value) : null;
    }
}
