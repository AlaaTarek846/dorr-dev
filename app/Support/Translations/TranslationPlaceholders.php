<?php

namespace App\Support\Translations;

use App\Enums\TranslationPlatform;

/**
 * Placeholder signatures per platform — a translation must keep the base's placeholders.
 */
final class TranslationPlaceholders
{
    /**
     * Sorted placeholder tokens found in a string.
     *
     * @return list<string>
     */
    public static function extract(TranslationPlatform $platform, string $text): array
    {
        $tokens = match ($platform) {
            TranslationPlatform::Backend => self::laravel($text),
            TranslationPlatform::Vue => self::vue($text),
            TranslationPlatform::Android => self::android($text),
        };

        sort($tokens);

        return $tokens;
    }

    /**
     * :name / :Name / :NAME are the same Laravel placeholder.
     *
     * @return list<string>
     */
    private static function laravel(string $text): array
    {
        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $text, $matches);

        return array_values(array_unique(array_map(
            static fn (string $name): string => ':'.strtolower($name),
            $matches[1],
        )));
    }

    /**
     * {name} placeholders plus vue-i18n syntax characters (plural pipes, linked @).
     *
     * @return list<string>
     */
    private static function vue(string $text): array
    {
        preg_match_all('/\{\s*([^{}\s]+)\s*\}/', $text, $matches);

        $tokens = array_values(array_unique(array_map(
            static fn (string $name): string => '{'.$name.'}',
            $matches[1],
        )));

        $withoutPlaceholders = (string) preg_replace('/\{[^{}]*\}/', '', $text);

        foreach (['|', '@'] as $special) {
            $count = substr_count($withoutPlaceholders, $special);

            if ($count > 0) {
                $tokens[] = $special.'×'.$count;
            }
        }

        return $tokens;
    }

    /**
     * printf tokens; non-positional ones are numbered in order so "%s %d" equals "%2$d %1$s".
     *
     * @return list<string>
     */
    private static function android(string $text): array
    {
        preg_match_all('/%(?:(\d+)\$)?[-#+ 0,(]*\d*(?:\.\d+)?([a-zA-Z%])/', $text, $matches, PREG_SET_ORDER);

        $tokens = [];
        $sequence = 0;

        foreach ($matches as $match) {
            $type = $match[2];

            if ($type === '%') {
                continue;
            }

            $position = $match[1] !== '' ? (int) $match[1] : ++$sequence;
            $tokens[] = '%'.$position.'$'.$type;
        }

        return $tokens;
    }
}
