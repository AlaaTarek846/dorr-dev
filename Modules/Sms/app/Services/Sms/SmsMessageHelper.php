<?php

namespace Modules\Sms\Services\Sms;

/**
 * SmsMessageHelper — SMS length/encoding aware helpers.
 *
 * SMS providers do NOT all cap a message at 160 characters. Longest SMS:
 *   - GSM-7  : 160 / 7-bit; multipart 153 chars per segment.
 *   - Unicode: 70  / 16-bit UCS-2; multipart 67 chars per segment.
 * Arabic is Unicode (UCS-2), so segment accounting must switch automatically —
 * never assume 1 SMS = 160 characters.
 *
 * This is provider-aware: providers pass their own per-segment limits (e.g.
 * SMS Misr documents vendor-specific boundaries). Defaults follow the GSM
 * 03.38 standard.
 */
class SmsMessageHelper
{
    /**
     * True when the text can be encoded on the GSM-7 default alphabet.
     */
    public function isGsm7(string $text): bool
    {
        return preg_match('/^[A-Za-z0-9 \r\n@£\$¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ!"#¤%&\'\(\)\*\+,\-\.\/:;<=>\?¡ÄÖÑÜ§¿äöñüà^{}\[~\]\|€]*$/u', $text) === 1;
    }

    /**
     * Whether the text must be sent as Unicode (UCS-2) to render correctly.
     */
    public function isUnicode(string $text): bool
    {
        return ! $this->isGsm7($text);
    }

    /**
     * @return array{charset:string, per_segment:int, max_multipart:int}
     */
    public function charsetSpecs(string $text): array
    {
        if ($this->isGsm7($text)) {
            return ['charset' => 'GSM-7', 'per_segment' => 160, 'max_multipart' => 153];
        }

        return ['charset' => 'UCS-2', 'per_segment' => 70, 'max_multipart' => 67];
    }

    /**
     * Estimate the number of segments a message will consume, allowing a
     * provider to override the per-segment boundaries.
     *
     * @param  array|null  $limits  ['single'=>int,'multipart'=>int]
     */
    public function estimateSegments(string $text, ?array $limits = null): int
    {
        $specs = $this->charsetSpecs($text);
        $single = $limits['single'] ?? $specs['per_segment'];
        $multipart = $limits['multipart'] ?? $specs['max_multipart'];

        if ($text === '' || mb_strlen($text) <= $single) {
            return 1;
        }

        return (int) ceil(mb_strlen($text) / $multipart);
    }
}
