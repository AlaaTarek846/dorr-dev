<?php

namespace Modules\Rides\Support;

/**
 * Plain-PHP geometry (docs/rides-structure.md §0.3): the tests run on SQLite, so there is no
 * spatial SQL — a bounding box narrows the rows in SQL, these finish the job exactly.
 */
final class Geo
{
    private const EARTH_RADIUS_M = 6371000.0;

    /** Great-circle distance in metres. */
    public static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_M * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * The box around a point that contains every point within $metres of it.
     *
     * @return array{0: float, 1: float, 2: float, 3: float} [minLat, maxLat, minLng, maxLng]
     */
    public static function boxAround(float $lat, float $lng, float $metres): array
    {
        $dLat = rad2deg($metres / self::EARTH_RADIUS_M);
        $cos = max(cos(deg2rad($lat)), 0.000001);
        $dLng = rad2deg($metres / (self::EARTH_RADIUS_M * $cos));

        return [$lat - $dLat, $lat + $dLat, $lng - $dLng, $lng + $dLng];
    }

    /**
     * @param  list<array{0: float|int|string, 1: float|int|string}>  $polygon  [[lat, lng], …]
     * @return array{0: float, 1: float, 2: float, 3: float} [minLat, maxLat, minLng, maxLng]
     */
    public static function boundingBox(array $polygon): array
    {
        if ($polygon === []) {
            return [0.0, 0.0, 0.0, 0.0];
        }
        $lats = array_map(fn ($p) => (float) $p[0], $polygon);
        $lngs = array_map(fn ($p) => (float) $p[1], $polygon);

        return [min($lats), max($lats), min($lngs), max($lngs)];
    }

    /**
     * Ray casting: is the point inside the polygon (edges count as inside)?
     *
     * @param  list<array{0: float|int|string, 1: float|int|string}>  $polygon  [[lat, lng], …]
     */
    public static function inPolygon(float $lat, float $lng, array $polygon): bool
    {
        $count = count($polygon);
        if ($count < 3) {
            return false;
        }

        $inside = false;
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$latI, $lngI] = [(float) $polygon[$i][0], (float) $polygon[$i][1]];
            [$latJ, $lngJ] = [(float) $polygon[$j][0], (float) $polygon[$j][1]];

            if (self::onSegment($lat, $lng, $latI, $lngI, $latJ, $lngJ)) {
                return true;
            }

            $crosses = ($lngI > $lng) !== ($lngJ > $lng)
                && $lat < ($latJ - $latI) * ($lng - $lngI) / (($lngJ - $lngI) ?: 1e-12) + $latI;
            if ($crosses) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    private static function onSegment(float $lat, float $lng, float $lat1, float $lng1, float $lat2, float $lng2): bool
    {
        $cross = ($lat - $lat1) * ($lng2 - $lng1) - ($lng - $lng1) * ($lat2 - $lat1);
        if (abs($cross) > 1e-12) {
            return false;
        }

        return $lat >= min($lat1, $lat2) && $lat <= max($lat1, $lat2) && $lng >= min($lng1, $lng2) && $lng <= max($lng1, $lng2);
    }
}
