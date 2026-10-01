<?php

namespace App\Support\Translations;

use App\Enums\TranslationPlatform;
use Illuminate\Contracts\Translation\Loader;
use Throwable;

/**
 * Reads the source-code translations (base `en`, reference `ar`) as flat "units":
 * key => ['segments' => [...], 'value' => string|array<quantity, string>, 'plural' => bool].
 * The key is the dot-joined path; `segments` keep the real nesting (some keys contain dots).
 */
final class TranslationSources
{
    /** @var array<string, array<string, array{segments: list<string>, value: string|array<string, string>, plural: bool}>> */
    private array $memo = [];

    private readonly Loader $loader;

    public function __construct(?Loader $loader = null)
    {
        $this->loader = $loader ?? app('translation.loader');
    }

    /**
     * @return array<string, array{segments: list<string>, value: string|array<string, string>, plural: bool}>
     */
    public function units(TranslationPlatform $platform, string $group, ?string $locale = null): array
    {
        $locale ??= (string) config('translations.base_locale', 'en');
        $memoKey = "{$platform->value}.{$group}.{$locale}";

        return $this->memo[$memoKey] ??= match ($platform) {
            TranslationPlatform::Backend => self::flatten($this->loader->load($locale, $group, '*')),
            TranslationPlatform::Vue => self::flatten($this->readJson($locale)),
            TranslationPlatform::Android => $this->androidUnits($group, $locale),
        };
    }

    /**
     * Nested array → units (string leaves only).
     *
     * @param  array<mixed>  $tree
     * @param  list<string>  $prefix
     * @return array<string, array{segments: list<string>, value: string, plural: bool}>
     */
    public static function flatten(array $tree, array $prefix = []): array
    {
        $units = [];

        foreach ($tree as $key => $value) {
            $segments = [...$prefix, (string) $key];

            if (is_array($value)) {
                $units += self::flatten($value, $segments);
            } elseif (is_string($value)) {
                $units[implode('.', $segments)] = ['segments' => $segments, 'value' => $value, 'plural' => false];
            }
        }

        return $units;
    }

    /**
     * @return array<mixed>
     */
    private function readJson(string $locale): array
    {
        if (! preg_match('/^[a-z]{2,3}$/', $locale)) {
            return [];
        }

        $path = rtrim((string) config('translations.vue_locales_path'), '/\\').DIRECTORY_SEPARATOR.$locale.'.json';

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, array{segments: list<string>, value: string|array<string, string>, plural: bool}>
     */
    private function androidUnits(string $group, string $locale): array
    {
        if (! TranslationPlatform::Android->hasGroup($group)) {
            return [];
        }

        $directory = $locale === config('translations.base_locale', 'en') ? 'values' : AndroidStringsXml::qualifier($locale);
        $path = rtrim((string) config('translations.android_res_path'), '/\\')
            .DIRECTORY_SEPARATOR.$directory.DIRECTORY_SEPARATOR.$group.'.xml';

        if (! is_file($path)) {
            return [];
        }

        try {
            $entries = AndroidStringsXml::parse((string) file_get_contents($path));
        } catch (Throwable) {
            return [];
        }

        $units = [];

        foreach ($entries as $name => $value) {
            $units[$name] = ['segments' => [$name], 'value' => $value, 'plural' => is_array($value)];
        }

        return $units;
    }
}
