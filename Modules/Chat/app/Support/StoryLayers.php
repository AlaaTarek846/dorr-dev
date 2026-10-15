<?php

namespace Modules\Chat\Support;

/**
 * What a status carries on top (backlog 79–80): stickers / GIFs, written text and drawing.
 * Kept as layers in `style.layers` so a GIF keeps moving for whoever watches it.
 *
 * Positions are fractions of the status frame (0..1), so every phone draws them in the same spot:
 *  - `t` text:    x, y, s (scale), r (degrees), text, c (#RRGGBB), f (font), bg (0/1 pill)
 *  - `s` sticker: x, y, s, r, url, a (width / height)
 *  - `d` drawing: c, w (stroke, fraction of the width), p [x, y, x, y, …]
 */
final class StoryLayers
{
    public const MAX_LAYERS = 40;

    public const MAX_POINTS = 6000;

    private const FONTS = ['bold', 'serif', 'mono', 'script', 'light'];

    /**
     * The sent layers (a JSON string from a form, or an array), cleaned; null when there are none.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function clean(mixed $raw): ?array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw) || ! array_is_list($raw)) {
            return null;
        }

        $out = [];
        $points = 0;
        foreach (array_slice($raw, 0, self::MAX_LAYERS) as $layer) {
            if (! is_array($layer)) {
                continue;
            }
            $kind = $layer['k'] ?? null;
            if ($kind === 'd') {
                $path = self::path($layer['p'] ?? null, self::MAX_POINTS - $points);
                if ($path === null) {
                    continue;
                }
                $points += count($path) / 2;
                $out[] = ['k' => 'd', 'c' => self::color($layer['c'] ?? null), 'w' => self::clamp($layer['w'] ?? 0.012, 0.002, 0.08), 'p' => $path];

                continue;
            }
            if (! in_array($kind, ['t', 's'], true)) {
                continue;
            }
            $placed = [
                'k' => $kind,
                'x' => self::clamp($layer['x'] ?? 0.5, 0, 1),
                'y' => self::clamp($layer['y'] ?? 0.5, 0, 1),
                's' => self::clamp($layer['s'] ?? 1, 0.2, 6),
                'r' => self::clamp($layer['r'] ?? 0, -360, 360),
            ];
            if ($kind === 't') {
                $text = mb_substr(trim((string) (is_scalar($layer['text'] ?? null) ? $layer['text'] : '')), 0, 200);
                if ($text === '') {
                    continue;
                }
                $font = $layer['f'] ?? 'bold';
                $out[] = $placed + ['text' => $text, 'c' => self::color($layer['c'] ?? null), 'f' => in_array($font, self::FONTS, true) ? $font : 'bold', 'bg' => empty($layer['bg']) ? 0 : 1];

                continue;
            }
            $url = self::url($layer['url'] ?? null);
            if ($url === null) {
                continue;
            }
            $out[] = $placed + ['url' => $url, 'a' => self::clamp($layer['a'] ?? 1, 0.2, 5)];
        }

        return $out ?: null;
    }

    private static function clamp(mixed $v, float $min, float $max): float
    {
        return round(max($min, min($max, is_numeric($v) ? (float) $v : $min)), 4);
    }

    private static function color(mixed $v): string
    {
        return is_string($v) && preg_match('/^#[0-9A-Fa-f]{6}$/', $v) ? strtoupper($v) : '#FFFFFF';
    }

    /** A sticker / GIF picture: a web address or one of ours (`/storage/…`) — nothing else. */
    private static function url(mixed $v): ?string
    {
        if (! is_string($v) || strlen($v) > 600) {
            return null;
        }
        if (str_starts_with($v, 'https://') || str_starts_with($v, 'http://')) {
            return filter_var($v, FILTER_VALIDATE_URL) ? $v : null;
        }

        return preg_match('#^/[A-Za-z0-9/_\-.%]+$#', $v) && ! str_contains($v, '..') ? $v : null;
    }

    /** @return list<float>|null */
    private static function path(mixed $v, int $room): ?array
    {
        if (! is_array($v) || ! array_is_list($v) || $room < 1) {
            return null;
        }
        $v = array_slice($v, 0, min(count($v), $room * 2));
        if (count($v) % 2 === 1) {
            array_pop($v);
        }
        $path = array_map(fn ($n) => self::clamp($n, 0, 1), $v);

        return count($path) >= 2 ? $path : null;
    }
}
