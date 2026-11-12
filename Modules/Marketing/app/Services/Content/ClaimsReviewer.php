<?php

namespace Modules\Marketing\Services\Content;

/**
 * The claims review of an ad or a post (U07, POL-09), in code so it is the same every time: promises of results and
 * "best / #1" words, numbers and prices that need proof, and text too long for the channel. `blocking` issues keep the
 * item from being sent for review; warnings are shown to whoever approves it.
 */
class ClaimsReviewer
{
    /** Promised results (POL-12): never allowed. */
    private const GUARANTEES = [
        '/\bguarantee[ds]?\b/iu', '/\b100\s?%\s*(?:results?|success|guaranteed|safe|cure)/iu', '/\brisk[\s-]?free\b/iu',
        '/\bget rich\b/iu', '/\bcures?\b/iu',
        '/مضمون/u', '/مضمونة/u', '/ضمان\s+(?:النتائج|الربح|الأرباح|النجاح)/u', '/نتائج\s+مضمونة/u', '/بدون\s+أي\s+مخاطرة/u', '/يعالج/u', '/علاج\s+نهائي/u',
    ];

    /** Superlatives that need proof. */
    private const ABSOLUTES = [
        '/\bthe best\b/iu', '/\bbest in\b/iu', '/#\s?1\b/u', '/\bnumber one\b/iu', '/\bno\.?\s?1\b/iu', '/\bcheapest\b/iu', '/\bfastest\b/iu', '/\bonly one\b/iu',
        '/الأفضل/u', '/الافضل/u', '/رقم\s?(?:1|١|واحد)/u', '/الأرخص/u', '/الارخص/u', '/الأسرع/u', '/الاسرع/u', '/الأول\s+في/u', '/الوحيد/u',
    ];

    private const PRICES = ['/\b(?:discount|off|sale|free|price)\b/iu', '/خصم/u', '/تخفيض/u', '/مجان/u', '/عرض\s+خاص/u', '/بسعر/u', '/ريال|جنيه|درهم|SAR|EGP|AED/u'];

    /** Longest text each channel takes (characters), where it is a known hard limit. */
    private const LIMITS = ['x' => 280, 'sms' => 160, 'google' => 90];

    private const HEADLINE_LIMIT = 60;

    /**
     * @param  array{variants?: list<array<string, mixed>>}  $content
     * @return list<array{code: string, severity: string, variant: ?string, detail: ?string}>
     */
    public function review(string $channel, ?array $content): array
    {
        $issues = [];
        foreach ($content['variants'] ?? [] as $variant) {
            $key = (string) ($variant['key'] ?? '');
            $text = trim(implode("\n", array_filter([
                (string) ($variant['headline'] ?? ''), (string) ($variant['body'] ?? ''), (string) ($variant['cta'] ?? ''),
            ])));

            if (($hit = $this->first(self::GUARANTEES, $text)) !== null) {
                $issues[] = $this->issue('guaranteed_result', 'blocking', $key, $hit);
            }
            if (($hit = $this->first(self::ABSOLUTES, $text)) !== null) {
                $issues[] = $this->issue('absolute_claim', 'warning', $key, $hit);
            }
            if (preg_match('/[0-9٠-٩]+(?:[.,][0-9٠-٩]+)?\s?(?:%|٪|x\b|×|times|مرة|أضعاف)/u', $text, $m)) {
                $issues[] = $this->issue('number_needs_proof', 'warning', $key, $m[0]);
            }
            if (($hit = $this->first(self::PRICES, $text)) !== null) {
                $issues[] = $this->issue('price_claim', 'warning', $key, $hit);
            }
            $limit = self::LIMITS[$channel] ?? null;
            $body = (string) ($variant['body'] ?? '');
            if ($limit !== null && mb_strlen($body) > $limit) {
                $issues[] = $this->issue('too_long_for_channel', 'warning', $key, (string) $limit);
            }
            if (mb_strlen((string) ($variant['headline'] ?? '')) > self::HEADLINE_LIMIT) {
                $issues[] = $this->issue('headline_too_long', 'warning', $key, (string) self::HEADLINE_LIMIT);
            }
            if (trim($body) === '' && trim((string) ($variant['headline'] ?? '')) === '') {
                $issues[] = $this->issue('empty_text', 'blocking', $key, null);
            }
        }
        if (($content['variants'] ?? []) === []) {
            $issues[] = $this->issue('empty_text', 'blocking', null, null);
        }

        return $issues;
    }

    /** @param  list<array<string, mixed>>  $issues */
    public static function blocking(array $issues): bool
    {
        foreach ($issues as $issue) {
            if (($issue['severity'] ?? null) === 'blocking') {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $patterns */
    private function first(array $patterns, string $text): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                return mb_substr($m[0], 0, 60);
            }
        }

        return null;
    }

    private function issue(string $code, string $severity, ?string $variant, ?string $detail): array
    {
        return ['code' => $code, 'severity' => $severity, 'variant' => $variant === '' ? null : $variant, 'detail' => $detail];
    }
}
