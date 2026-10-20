<?php

namespace Modules\Rides\Support;

/** Google's encoded polyline format — a ride's track kept small (ride_tracks), drawn by both apps. */
final class Polyline
{
    /**
     * @param  iterable<array{0: float, 1: float}>  $points  [lat, lng]
     */
    public static function encode(iterable $points): string
    {
        $out = '';
        $prevLat = 0;
        $prevLng = 0;
        foreach ($points as [$lat, $lng]) {
            $lat5 = (int) round($lat * 1e5);
            $lng5 = (int) round($lng * 1e5);
            $out .= self::value($lat5 - $prevLat).self::value($lng5 - $prevLng);
            [$prevLat, $prevLng] = [$lat5, $lng5];
        }

        return $out;
    }

    /** @return list<array{0: float, 1: float}> */
    public static function decode(string $encoded): array
    {
        $points = [];
        $index = 0;
        $lat = 0;
        $lng = 0;
        $length = strlen($encoded);
        while ($index < $length) {
            foreach (['lat', 'lng'] as $axis) {
                $shift = 0;
                $result = 0;
                do {
                    $byte = ord($encoded[$index++]) - 63;
                    $result |= ($byte & 0x1F) << $shift;
                    $shift += 5;
                } while ($byte >= 0x20 && $index < $length);
                $delta = ($result & 1) ? ~($result >> 1) : ($result >> 1);
                $$axis += $delta;
            }
            $points[] = [$lat / 1e5, $lng / 1e5];
        }

        return $points;
    }

    private static function value(int $value): string
    {
        $value = $value < 0 ? ~($value << 1) : ($value << 1);
        $chunk = '';
        while ($value >= 0x20) {
            $chunk .= chr((0x20 | ($value & 0x1F)) + 63);
            $value >>= 5;
        }

        return $chunk.chr($value + 63);
    }
}
