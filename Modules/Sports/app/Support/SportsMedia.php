<?php

namespace Modules\Sports\Support;

/**
 * The provider's logos and photos (media.api-sports.io) go through our own copy: the provider
 * asks for that — free, but limited per second — and the apps then load them from us, fast.
 * Photos have fixed addresses by id (players/{id}.png, coachs/{id}.png, venues/{id}.png…).
 */
class SportsMedia
{
    public const ORIGIN = 'https://media.api-sports.io/';

    /** A path we copy: {sport}/{kind}/{id}.png, or flags/{code}.svg. */
    public const PATH = '(?:[a-z0-9-]+/[a-z]+/\d+\.png|flags/[a-z0-9-]+\.svg)';

    /** The provider's address → ours; anything else unchanged. */
    public static function url(?string $url): ?string
    {
        if ($url === null || $url === '' || ! str_starts_with($url, self::ORIGIN)) {
            return $url ?: null;
        }
        $path = substr($url, strlen(self::ORIGIN));

        return preg_match('#^'.self::PATH.'$#', $path) ? url('/api/mobile/v1/sports/media/'.$path) : $url;
    }

    /** A photo by the provider's id: players, coachs, venues, teams, leagues. */
    public static function of(string $kind, int|string|null $id, string $sport = 'football'): ?string
    {
        return $id ? self::url(self::ORIGIN.$sport.'/'.$kind.'/'.$id.'.png') : null;
    }

    /** Where our copy lives. */
    public static function file(string $path): string
    {
        return storage_path('app/sports-media/'.$path);
    }
}
