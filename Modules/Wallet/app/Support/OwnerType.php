<?php

namespace Modules\Wallet\Support;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Maps wallet owners ('user' / 'provider' / 'admin') to their model class
 * and back — deliberately NOT via Eloquent's Relation::morphMap().
 *
 * Why: User/Provider/Admin already have other, unrelated polymorphic
 * relations elsewhere in the app (e.g. HasVerificationCodes) that store the
 * full class name. Relation::morphMap() is global per model class — aliasing
 * "user" here would silently change what those other relations write too,
 * which broke an existing test the first time this was tried. Keeping the
 * wallet alias local to this module (config/config.php `morph_map`, read
 * only through this class) avoids that blast radius entirely.
 */
class OwnerType
{
    public static function aliasFor(Model $owner): string
    {
        foreach (self::map() as $alias => $class) {
            if ($owner instanceof $class) {
                return $alias;
            }
        }

        throw new RuntimeException('No wallet owner_type alias registered for '.$owner::class.'.');
    }

    /**
     * @return class-string<Model>
     */
    public static function modelClassFor(string $alias): string
    {
        return self::map()[$alias]
            ?? throw new RuntimeException("Unknown wallet owner_type alias: {$alias}.");
    }

    /**
     * @return array<string, class-string<Model>>
     */
    private static function map(): array
    {
        return config('wallet.morph_map', []);
    }
}
