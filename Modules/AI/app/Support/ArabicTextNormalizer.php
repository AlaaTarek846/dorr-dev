<?php

namespace Modules\AI\Support;

/**
 * One shared, framework-free text normalizer for every place in the AI
 * module that matches free-typed user text against a keyword list
 * (AiChatLexicon, AiIntentClassifier, AiToolResolver, ...).
 *
 * Root-cause fix - real, repeated, observed class of bug: Arabic has
 * several letters that look (and are typed) interchangeably but are
 * DIFFERENT Unicode codepoints, so a byte-exact substring comparison
 * silently fails between two spellings a human reads as identical:
 *
 *  - "صوتي" (ya, U+064A) vs "صوتى" (alef maksura, U+0649) - on most
 *    Egyptian keyboards the second is what actually gets typed;
 *  - "صورة" (ta marbuta) vs "صوره" (ha) - the dominant informal spelling
 *    on mobile;
 *  - "أعمل" / "إعمل" / "اعمل" - hamza forms of alef, usually dropped.
 *
 * Each of those was found and patched one word at a time, in a different
 * file each time (a voice-reply keyword, an image-edit verb, "صورة"/
 * "صوره", ...). Normalizing BOTH sides of every comparison with this one
 * function ends the whole class instead of the next instance of it.
 *
 * The output is only ever used to COMPARE - never shown to a user and
 * never stored - so it is free to be aggressive (it also strips
 * diacritics, tatweel, elongated letters and unifies digits/quotes).
 */
final class ArabicTextNormalizer
{
    /** Diacritics (tashkeel), Quranic annotation marks, superscript alef, tatweel. */
    private const STRIP_PATTERN = '/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u';

    /** @var array<string, string> */
    private const LETTER_MAP = [
        // Alef forms -> bare alef.
        'أ' => 'ا',
        'إ' => 'ا',
        'آ' => 'ا',
        'ٱ' => 'ا',
        // Alef maksura / Persian-Urdu yeh -> ya.
        'ى' => 'ي',
        'ی' => 'ي',
        'ې' => 'ي',
        'ئ' => 'ي',
        // Hamza on waw.
        'ؤ' => 'و',
        // Ta marbuta -> ha.
        'ة' => 'ه',
        'ھ' => 'ه',
        // Persian/Urdu kaf and gaf -> Arabic kaf.
        'ک' => 'ك',
        'گ' => 'ك',
        // Arabic-Indic and extended Arabic-Indic digits -> ASCII.
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        // Typographic quotes -> ASCII (so "what's" matches "what’s").
        '’' => "'",
        '‘' => "'",
        '`' => "'",
        '“' => '"',
        '”' => '"',
        // Arabic punctuation -> plain separators.
        '؟' => ' ',
        '،' => ' ',
        '؛' => ' ',
    ];

    public static function normalize(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $text = mb_strtolower($text);
        $text = preg_replace(self::STRIP_PATTERN, '', $text) ?? $text;
        $text = strtr($text, self::LETTER_MAP);

        // "اعملللللي" / "pleeeease" -> one letter. Only letters are
        // collapsed (never digits), and only runs of 3+, so legitimate
        // double letters ("اللي", "coffee") are untouched.
        $text = preg_replace('/(\p{L})\1{2,}/u', '$1', $text) ?? $text;

        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
