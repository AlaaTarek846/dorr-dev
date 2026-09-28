<?php

namespace App\Support\Mobile;

class MobileColorTokens
{
    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return config('mobile_appearance.color_token_keys', []);
    }

    /**
     * @return list<string>
     */
    public static function userCustomizableKeys(): array
    {
        return config('mobile_appearance.user_customizable_token_keys', self::keys());
    }

    /**
     * @return array<string, string>
     */
    public static function platformDefaultsLight(): array
    {
        return self::normalize(config('mobile_appearance.default_light_tokens', []));
    }

    /**
     * @return array<string, string>
     */
    public static function platformDefaultsDark(): array
    {
        return self::normalize(config('mobile_appearance.default_dark_tokens', []));
    }

    /**
     * @return list<string>
     */
    public static function authKeys(): array
    {
        return config('mobile_appearance.auth_color_token_keys', []);
    }

    /**
     * @return array<string, string>
     */
    public static function defaultAuthLight(): array
    {
        return self::normalizeAuth(config('mobile_appearance.default_light_auth_tokens', []));
    }

    /**
     * @return array<string, string>
     */
    public static function defaultAuthDark(): array
    {
        return self::normalizeAuth(config('mobile_appearance.default_dark_auth_tokens', []));
    }

    /**
     * Core + auth tokens for DB seed / firstOrCreate (matches Login light & dark).
     *
     * @return array<string, string>
     */
    public static function seededLightTokens(): array
    {
        return array_merge(self::platformDefaultsLight(), self::defaultAuthLight());
    }

    /**
     * @return array<string, string>
     */
    public static function seededDarkTokens(): array
    {
        return array_merge(self::platformDefaultsDark(), self::defaultAuthDark());
    }

    /**
     * @param  array<string, mixed>  $tokens
     * @return array<string, string>
     */
    public static function normalizeAuth(array $tokens): array
    {
        $allowed = array_flip(self::authKeys());
        $normalized = [];

        foreach ($tokens as $key => $value) {
            if (! isset($allowed[$key]) || ! is_string($value) || $value === '') {
                continue;
            }

            $color = self::normalizeColorString($value);
            if ($color !== null) {
                $normalized[$key] = $color;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, string>
     */
    public static function extractAuthFromStored(array $stored): array
    {
        return self::normalizeAuth($stored);
    }

    /**
     * Platform + auth tokens from DB row (admin core keys + auth_* extras).
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, string>
     */
    public static function defaultsFromStoredLight(array $stored): array
    {
        return array_replace(
            self::seededLightTokens(),
            self::normalize($stored),
            self::normalizeAuth($stored),
        );
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, string>
     */
    public static function defaultsFromStoredDark(array $stored): array
    {
        return array_replace(
            self::seededDarkTokens(),
            self::normalize($stored),
            self::normalizeAuth($stored),
        );
    }

    /**
     * @param  array<string, mixed>|null  $overrides
     * @return array<string, string>
     */
    public static function merge(?array $base, ?array $overrides): array
    {
        $base = self::normalize($base ?? []);
        $overrides = self::normalizeUserOverrides($overrides ?? []);

        return array_merge($base, $overrides);
    }

    /**
     * @param  array<string, mixed>  $tokens
     * @return array<string, string>
     */
    public static function normalize(array $tokens): array
    {
        $allowed = array_flip(self::keys());
        $normalized = [];

        foreach ($tokens as $key => $value) {
            if (! isset($allowed[$key]) || ! is_string($value) || $value === '') {
                continue;
            }

            $color = self::normalizeColorString($value);
            if ($color !== null) {
                $normalized[$key] = $color;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $tokens
     * @return array<string, string>
     */
    public static function normalizeUserOverrides(array $tokens): array
    {
        $allowed = array_flip(self::userCustomizableKeys());
        $normalized = [];

        foreach ($tokens as $key => $value) {
            if (! isset($allowed[$key]) || ! is_string($value) || $value === '') {
                continue;
            }

            $color = self::normalizeColorString($value);
            if ($color !== null) {
                $normalized[$key] = $color;
            }
        }

        return $normalized;
    }

    public static function normalizeColorString(string $value): ?string
    {
        $hex = strtoupper(ltrim(trim($value), '#'));

        if (strlen($hex) === 6 && ctype_xdigit($hex)) {
            return '#'.$hex;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function colorTokenValidationRules(string $prefix): array
    {
        $rules = [];

        foreach (self::keys() as $key) {
            $rules["{$prefix}.{$key}"] = ['nullable', 'string', 'regex:/^#?[0-9A-Fa-f]{6}$/'];
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    public static function userOverrideValidationRules(string $prefix): array
    {
        $rules = [];

        foreach (self::userCustomizableKeys() as $key) {
            $rules["{$prefix}.{$key}"] = ['nullable', 'string', 'regex:/^#?[0-9A-Fa-f]{6}$/'];
        }

        return $rules;
    }
}
