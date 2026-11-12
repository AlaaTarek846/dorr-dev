<?php

namespace Modules\Marketing\Services\Content;

use Modules\Marketing\Models\MarketingContentItem;

/** Cleans the content of an item (from a person or the AI) into one shape: variants A/B/C and the chosen one. */
class ContentSchema
{
    public const VARIANT_KEYS = ['A', 'B', 'C'];

    /**
     * @param  mixed  $variants  list of {headline, body, cta, hashtags, visual_brief}
     * @return array{variants: list<array<string, mixed>>, selected: ?string}
     */
    public static function normalize(mixed $variants, ?string $selected = null): array
    {
        $out = [];
        foreach (array_values(is_array($variants) ? $variants : []) as $i => $variant) {
            if ($i >= count(self::VARIANT_KEYS) || ! is_array($variant)) {
                break;
            }
            $hashtags = collect(is_array($variant['hashtags'] ?? null) ? $variant['hashtags'] : [])
                ->map(fn ($h) => self::text($h, 40))->filter()->map(fn ($h) => '#'.ltrim((string) $h, '#'))->unique()->take(10)->values()->all();
            $row = [
                'key' => self::VARIANT_KEYS[$i],
                'headline' => self::text($variant['headline'] ?? null, 120),
                'body' => self::text($variant['body'] ?? null, 2200),
                'cta' => self::text($variant['cta'] ?? null, 60),
                'hashtags' => $hashtags,
                'visual_brief' => self::text($variant['visual_brief'] ?? null, 500),
            ];
            if ($row['headline'] === null && $row['body'] === null) {
                continue;
            }
            $out[] = $row;
        }
        foreach ($out as $i => $row) {
            $out[$i]['key'] = self::VARIANT_KEYS[$i];
        }
        $keys = array_column($out, 'key');

        return ['variants' => $out, 'selected' => in_array($selected, $keys, true) ? $selected : ($keys[0] ?? null)];
    }

    public static function channel(mixed $channel): string
    {
        $channel = strtolower(trim((string) $channel));

        return in_array($channel, MarketingContentItem::CHANNELS, true) ? $channel : 'other';
    }

    public static function format(mixed $format): ?string
    {
        $format = strtolower(trim((string) $format));

        return in_array($format, MarketingContentItem::FORMATS, true) ? $format : null;
    }

    public static function text(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim(strip_tags((string) $value));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
