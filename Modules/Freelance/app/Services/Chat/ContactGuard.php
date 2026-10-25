<?php

namespace Modules\Freelance\Services\Chat;

/**
 * Finds a way to reach someone outside Dorr in a piece of text (FL-308): a phone number, an email, a link or a social handle.
 * Until the agreement (FL-401) the two sides talk inside Dorr only, so such a message is not sent. It reads Arabic-Indic digits
 * and numbers broken up with spaces, dots or dashes; a date and a price are not taken for a phone number.
 */
class ContactGuard
{
    /** @return string|null `email` · `link` · `phone` · `handle` — what the text contains (null: nothing of the sort) */
    public function find(string $text): ?string
    {
        $text = $this->normalize($text);

        if (preg_match('/[\p{L}\p{N}._%+\-]+\s?(?:@|\(at\)|\[at\])\s?[\p{L}\p{N}\-]+\s?(?:\.|\(dot\)|\[dot\])\s?\p{L}{2,}/iu', $text)) {
            return 'email';
        }
        if (preg_match('/(?:https?:\/\/|www\.|wa\.me|t\.me|telegram\.me|bit\.ly)/iu', $text)
            || preg_match('/\b[\p{L}\p{N}\-]+\.(?:com|net|org|io|me|co|app|info|biz|xyz|dev|ly|tv|link|sa|eg|ae|kw|qa|bh|om|jo)\b/iu', $text)) {
            return 'link';
        }
        // A date reads like a long number: take it out first.
        $plain = preg_replace('/\b\d{1,4}[\/.\-]\d{1,2}[\/.\-]\d{1,4}\b/u', ' ', $text) ?? $text;
        if (preg_match('/(?:\+|00)?\d(?:[\s.\-()_]*\d){7,}/u', $plain)) {
            return 'phone';
        }
        if (preg_match('/(?<![\w@])@[a-z0-9._]{3,}/i', $text)) {
            return 'handle';
        }

        return null;
    }

    private function normalize(string $text): string
    {
        // Arabic-Indic (٠-٩) and Persian (۰-۹) digits to 0-9; invisible characters out.
        $text = strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);

        return preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}]/u', '', $text) ?? $text;
    }
}
