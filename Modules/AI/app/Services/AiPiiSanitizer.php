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
    /** The kinds a provider data rule's transformation_rules.mask_fields may name, in the order they are applied. */
    public const MASK_FIELDS = ['email', 'id_number', 'phone', 'secrets'];

    public function redactPii(string $text): string
    {
        // Long digit runs with NO separators (card numbers, national IDs,
        // IBAN fragments) are checked before the phone pattern -
        // the phone pattern's character class also contains \d, so a
        // separator-free run would otherwise always be consumed by it
        // first and mislabeled [phone], leaving that branch dead code.
        return $this->maskPhones($this->maskIdNumbers($this->maskEmails($text)));
    }

    public function maskEmails(string $text): string
    {
        return preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[email]', $text) ?? $text;
    }

    public function maskIdNumbers(string $text): string
    {
        return preg_replace('/(?<!\d)\d{9,}(?!\d)/', '[id_number]', $text) ?? $text;
    }

    /** International/local formats, 8-15 digits with optional +, spaces, dashes or parentheses. */
    public function maskPhones(string $text): string
    {
        return preg_replace('/(?<!\d)(\+?\d[\d\s\-\(\)]{7,15}\d)(?!\d)/', '[phone]', $text) ?? $text;
    }

    /**
     * A provider data rule's transformation_rules (AiGateway::applyDataRules):
     * - mask_fields: any of MASK_FIELDS - only those kinds are masked (email, id_number, phone; "secrets" = keys,
     *   tokens, passwords);
     * - redact_terms: literal words / phrases replaced with [redacted], case-insensitive.
     * Unknown keys and malformed values are ignored, so a bad rule never breaks the call.
     *
     * @param  array<string, mixed>  $rules
     */
    public function applyTransformationRules(string $text, array $rules): string
    {
        $fields = is_array($rules['mask_fields'] ?? null) ? array_map('strval', $rules['mask_fields']) : [];

        // Secrets first (see redactBoth()), then the PII kinds in redactPii()'s order.
        if (in_array('secrets', $fields, true) || in_array('secret', $fields, true)) {
            $text = $this->redactSecrets($text);
        }

        foreach (['email' => 'maskEmails', 'id_number' => 'maskIdNumbers', 'phone' => 'maskPhones'] as $field => $method) {
            if (in_array($field, $fields, true)) {
                $text = $this->{$method}($text);
            }
        }

        foreach (is_array($rules['redact_terms'] ?? null) ? $rules['redact_terms'] : [] as $term) {
            if (is_string($term) && trim($term) !== '') {
                $text = preg_replace('/'.preg_quote(trim($term), '/').'/iu', '[redacted]', $text) ?? $text;
            }
        }

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
