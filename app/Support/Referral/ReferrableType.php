<?php

namespace App\Support\Referral;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Maps referral owners ('user' / 'provider', later 'driver') to their model
 * class — deliberately NOT via Eloquent's Relation::morphMap().
 *
 * Same reason as Modules\Wallet\Support\OwnerType: User/Provider already store
 * the full class name on other morphs (verification codes, social accounts).
 */
class ReferrableType
{
    public static function aliasFor(Model $owner): string
    {
        foreach (self::map() as $alias => $class) {
            if ($owner instanceof $class) {
                return $alias;
            }
        }

        throw new RuntimeException('No referral alias registered for '.$owner::class.'.');
    }

    /**
     * @return class-string<Model>
     */
    public static function modelClassFor(string $alias): string
    {
        return self::map()[$alias]
            ?? throw new RuntimeException("Unknown referral alias: {$alias}.");
    }

    public static function find(string $alias, int|string $id): ?Model
    {
        $class = self::map()[$alias] ?? null;

        return $class !== null ? $class::query()->find($id) : null;
    }

    public static function isKnown(string $alias): bool
    {
        return isset(self::map()[$alias]);
    }

    /**
     * @return array<string, class-string<Model>>
     */
    public static function map(): array
    {
        return config('referral.morph_map', []);
    }

    /**
     * @return list<string>
     */
    public static function enabled(): array
    {
        return config('referral.enabled', ['user']);
    }
}
