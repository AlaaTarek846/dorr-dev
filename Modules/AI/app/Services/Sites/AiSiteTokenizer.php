<?php

namespace Modules\AI\Services\Sites;

/**
 * Contact details never go to the model. The prompt only lists placeholders
 * ({{PHONE}}, {{EMAIL}} ...); generated files keep the placeholders and the
 * real values are substituted when a file is served or downloaded. That keeps
 * personal data out of provider calls (their data rules would otherwise redact
 * it anyway) and lets a changed phone number show up without a rebuild.
 */
class AiSiteTokenizer
{
    public const SOCIAL = ['facebook', 'instagram', 'x', 'tiktok', 'youtube', 'linkedin', 'snapchat'];

    /**
     * placeholder => raw value, only for details the customer actually gave.
     *
     * @param  array<string, mixed>  $brief
     * @return array<string, string>
     */
    public function map(array $brief): array
    {
        $contact = (array) ($brief['contact'] ?? []);
        $map = [];

        foreach (['phone' => 'PHONE', 'email' => 'EMAIL', 'address' => 'ADDRESS', 'map_url' => 'MAP_URL', 'working_hours' => 'HOURS'] as $key => $token) {
            if (filled($contact[$key] ?? null)) {
                $map['{{'.$token.'}}'] = (string) $contact[$key];
            }
        }

        if (filled($contact['whatsapp'] ?? null)) {
            $map['{{WHATSAPP}}'] = (string) $contact['whatsapp'];
            $map['{{WHATSAPP_DIGITS}}'] = preg_replace('/\D+/', '', (string) $contact['whatsapp']) ?? '';
        }

        foreach (self::SOCIAL as $network) {
            $url = ((array) ($brief['social'] ?? []))[$network] ?? null;

            if (filled($url)) {
                $map['{{'.strtoupper($network).'_URL}}'] = (string) $url;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $map
     */
    public function apply(string $content, array $map): string
    {
        if ($map === [] || ! str_contains($content, '{{')) {
            return $content;
        }

        return strtr($content, array_map(fn (string $value) => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $map));
    }
}
