<?php

namespace Modules\Chat\Support;

/**
 * A group's banned words. Matching is forgiving the way people dodge filters, but never matches
 * inside another word ("ass" is not found in "class"):
 *  - case and Arabic spelling variants count as the same letters (أ إ آ ا · ى ي · ة ه),
 *  - diacritics (tashkeel) and the stretching tatweel (كـــلمة) are ignored,
 *  - a banned phrase of several words matches with any spacing between them.
 */
final class BannedWords
{
    public const MAX_WORDS = 200;

    public const MAX_LENGTH = 50;

    /**
     * Clean the admin's list: trimmed, no blanks or repeats, at most MAX_WORDS.
     *
     * @param  list<mixed>  $words
     * @return list<string>
     */
    public static function clean(array $words): array
    {
        $seen = [];
        $out = [];

        foreach ($words as $word) {
            $word = trim(preg_replace('/\s+/u', ' ', (string) $word) ?? '');
            $key = self::normalize($word);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = mb_substr($word, 0, self::MAX_LENGTH);
        }

        return array_slice($out, 0, self::MAX_WORDS);
    }

    /**
     * The first banned word the text contains, or null.
     *
     * @param  list<string>|null  $words
     */
    public static function firstIn(?string $text, ?array $words): ?string
    {
        if ($text === null || $text === '' || empty($words)) {
            return null;
        }

        $haystack = self::normalize($text);

        foreach ($words as $word) {
            $needle = self::normalize((string) $word);
            if ($needle === '') {
                continue;
            }
            $pattern = implode('\s+', array_map(fn ($part) => preg_quote($part, '/'), explode(' ', $needle)));
            if (preg_match('/(?<![\p{L}\p{N}])'.$pattern.'(?![\p{L}\p{N}])/u', $haystack)) {
                return (string) $word;
            }
        }

        return null;
    }

    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        // Tashkeel, superscript alef, and tatweel.
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text) ?? $text;
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
