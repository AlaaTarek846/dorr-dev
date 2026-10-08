<?php

namespace Modules\AI\Services;

/**
 * Pattern-based PII/secret redaction (v2.0 doc, 17.3: minimize and mask
 * PII in logs). Deliberately conservative regexes - false positives (an
 * order number that looks like a card number) are an acceptable cost for
 * a log/audit copy, since the real, unredacted content always stays in
 * ai_messages (the actual product data), never in the audit tables this
 * is applied to.
 */
class AiPiiSanitizer
{
    public function redactPii(string $text): string
    {
        $text = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[email]', $text) ?? $text;

        // Long digit runs with NO separators (card numbers, national IDs,
        // IBAN fragments) are checked before the phone pattern below -
        // the phone pattern's character class also contains \d, so a
        // separator-free run would otherwise always be consumed by it
        // first and mislabeled [phone], leaving this branch dead code.
        $text = preg_replace('/(?<!\d)\d{9,}(?!\d)/', '[id_number]', $text) ?? $text;

        // Phone numbers: international/local formats, 8-15 digits with
        // optional +, spaces, dashes or parentheses.
        $text = preg_replace('/(?<!\d)(\+?\d[\d\s\-\(\)]{7,15}\d)(?!\d)/', '[phone]', $text) ?? $text;

        return $text;
    }

    public function redactSecrets(string $text): string
    {
        // Provider-style API key prefixes (OpenAI/Anthropic/Google/Groq…).
        $text = preg_replace('/\b(sk|pk|rk|gsk|AIza)[-_][A-Za-z0-9_\-]{10,}\b/', '[secret]', $text) ?? $text;

        // Authorization / bearer headers pasted inline.
        $text = preg_replace('/\bBearer\s+[A-Za-z0-9_\-\.]{10,}\b/i', 'Bearer [secret]', $text) ?? $text;

        // key/token/password = value patterns.
        $text = preg_replace('/\b(api[_-]?key|token|secret|password)\s*[:=]\s*\S{6,}/i', '$1=[secret]', $text) ?? $text;

        // Long opaque alphanumeric strings (32+ chars) - typical of raw
        // tokens/keys with no recognizable prefix.
        $text = preg_replace('/\b[A-Za-z0-9_\-]{32,}\b/', '[secret]', $text) ?? $text;

        return $text;
    }

    /**
     * Runs secret redaction FIRST, then PII: the PII layer's phone-number
     * pattern (digits + separators) can otherwise consume part of an API
     * key or Bearer token before the secret patterns get a chance to
     * recognize it whole, silently corrupting what should have been
     * masked as [secret] into a mangled mix of [phone] fragments instead.
     */
    public function redactBoth(string $text): string
    {
        return $this->redactPii($this->redactSecrets($text));
    }
}
